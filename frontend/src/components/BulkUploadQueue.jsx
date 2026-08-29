import { useEffect, useMemo, useRef, useState } from "react";
import { Link, useSearchParams } from "react-router-dom";
import { CheckCircle2, Loader2, RotateCcw, Trash2, Upload } from "lucide-react";
import { createUploadRequestPool, uploadComicInChunks } from "@/hooks/use-chunked-upload";
import { useAuth } from "@/hooks/use-auth";
import { useConfig } from "@/hooks/use-config";
import { useToast } from "@/hooks/use-toast";
import { comicFileAccept, formatFileSize, generateTitleFromFilename, isComicFile } from "@/lib/comic-upload";
import { closeBulkUploadSession } from "@/lib/bulk-upload-session";
import { useAdSense } from "@/components/ads/AdSenseProvider.jsx";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Progress } from "@/components/ui/progress";
import { FolderDestinationSelect } from "@/components/library/FolderDestinationSelect";
import { useLibraryFolders } from "@/hooks/use-library-folders";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";

export const MAX_PARALLEL_FILES = 2;
const ACTIVE_STATUSES = new Set(["initialising", "uploading", "completing"]);
/** Not in flight and not uploaded: a row "Start all" would still pick up. */
const RETRYABLE_STATUSES = ["idle", "error", "cancelled"];

function rowId(file) {
  return `${file.name}-${file.size}-${file.lastModified}`;
}

function statusLabel(status) {
  return {
    idle: "Ready",
    initialising: "Preparing",
    uploading: "Uploading",
    completing: "Processing",
    done: "Complete",
    error: "Failed",
    cancelled: "Cancelled",
  }[status] || status;
}

export default function BulkUploadQueue() {
  const { toast } = useToast();
  const { refreshSession } = useAuth();
  const { config } = useConfig();
  const { isActive: advertisingActive } = useAdSense();
  const [searchParams] = useSearchParams();
  const { folders, isLoading: foldersLoading } = useLibraryFolders();
  const concurrentChunks = config.upload?.maxConcurrentUploads || 4;
  const requestPool = useMemo(
    () => createUploadRequestPool(concurrentChunks),
    [concurrentChunks]
  );
  const comicFormats = config.upload?.comicFormats || ["cbz"];
  const [rows, setRows] = useState([]);
  const [tagsInput, setTagsInput] = useState("");
  const [dragging, setDragging] = useState(false);
  const [running, setRunning] = useState(false);
  const requestedFolder = searchParams.get("folder");
  const [folderId, setFolderId] = useState(() => requestedFolder && /^\d+$/.test(requestedFolder) ? Number(requestedFolder) : null);
  const selectedFolderId = folderId != null && (foldersLoading || folders.some((folder) => Number(folder.id) === folderId)) ? folderId : null;
  const controllers = useRef(new Map());
  // Rows taken out of the queue after it started. The workers below walk a list
  // captured when the run began, so without this a file removed mid-run would
  // still be uploaded after it had left the screen.
  const removedRef = useRef(new Set());
  /** Whether "Start all" has run: only then is there a batch to settle. */
  const batchRanRef = useRef(false);
  const inputRef = useRef(null);
  const completed = rows.filter((row) => row.status === "done").length;
  const failed = rows.filter((row) => row.status === "error" || row.status === "cancelled").length;
  const allFinished = rows.length > 0 && completed + failed === rows.length;
  const tags = useMemo(() => tagsInput.split(",").map((tag) => tag.trim()).filter(Boolean), [tagsInput]);

  const updateRow = (id, updates) => {
    setRows((current) => current.map((row) => row.id === id ? { ...row, ...updates } : row));
  };

  /**
   * Take a file back out of the queue.
   *
   * Allowed while the rest of the queue is running, because that is when it is
   * most wanted: fifty files were dropped in, three of them were the wrong ones,
   * and waiting for the whole run to finish before fixing that is not an answer.
   */
  const removeRow = (id) => {
    removedRef.current.add(id);
    setRows((current) => current.filter((row) => row.id !== id));
  };

  const addFiles = (files) => {
    const candidates = Array.from(files);
    const valid = candidates.filter((file) => isComicFile(file, comicFormats));
    if (valid.length !== candidates.length) {
      toast({ title: "Some files were skipped", description: `Enabled formats: ${comicFormats.join(", ").toUpperCase()}.`, variant: "destructive" });
    }
    setRows((current) => {
      const existing = new Set(current.map((row) => row.id));
      return [...current, ...valid.filter((file) => !existing.has(rowId(file))).map((file) => ({
        id: rowId(file),
        file,
        title: generateTitleFromFilename(file.name),
        progress: 0,
        status: "idle",
        error: null,
        comic: null,
      }))];
    });
  };

  const uploadRow = async (row) => {
    const controller = new AbortController();
    controllers.current.set(row.id, controller);
    updateRow(row.id, { status: "initialising", progress: 0, error: null, comic: null });
    try {
      const result = await uploadComicInChunks({
        file: row.file,
        metadata: { title: row.title, tags, folderId: selectedFolderId },
        concurrentChunks,
        requestPool,
        signal: controller.signal,
        onProgress: (progress) => updateRow(row.id, { progress }),
        onStatus: (status) => updateRow(row.id, { status }),
      });
      updateRow(row.id, { status: "done", progress: 100, comic: result.comic });
    } catch (error) {
      const cancelled = error.name === "AbortError" || controller.signal.aborted;
      updateRow(row.id, { status: cancelled ? "cancelled" : "error", error: cancelled ? "Upload cancelled" : error.message });
    } finally {
      controllers.current.delete(row.id);
    }
  };

  const runQueue = async (selectedRows) => {
    let nextIndex = 0;
    const worker = async () => {
      while (nextIndex < selectedRows.length) {
        const row = selectedRows[nextIndex];
        nextIndex += 1;
        if (removedRef.current.has(row.id)) continue;
        await uploadRow(row);
      }
    };
    await Promise.all(Array.from({ length: Math.min(MAX_PARALLEL_FILES, selectedRows.length) }, worker));
  };

  /**
   * The batch is what a rewarded session covers, so it ends when the batch does
   * — but only once there is nothing left to retry. Closing it while files are
   * still failed would charge a second advertisement for finishing the batch
   * the first one paid for, which issue #73 rules out explicitly. A session
   * nobody closes expires on its own two hours later.
   *
   * Both ways a batch can settle come through here. A retry that rescues the
   * last failure ends the batch exactly as a clean run does, and a session left
   * open by that path would hand the *next* batch a free pass until it expired.
   *
   * Skipped where advertising is off, because the gate opens a session only on
   * the offer path and the offer is only made where advertising is running —
   * so there is nothing to close, and this would be an authenticated request to
   * delete something that does not exist after every batch, for every
   * self-hosted user. Nothing about uploading depended on the session, so
   * nothing here checks whether it closed.
   */
  useEffect(() => {
    if (!batchRanRef.current || running) return;
    // Read off the rows themselves rather than a count each caller works out.
    // Every way a batch can settle — a clean run, a retry that rescues the last
    // failure, removing the last outstanding file — is the same question, and
    // three separate answers to it disagreed about a row whose title was
    // cleared. Rows are never stale here: an effect sees the render they are in.
    if (rows.some((row) => row.status !== "done")) return;

    batchRanRef.current = false;
    if (advertisingActive) void closeBulkUploadSession();
  }, [rows, running, advertisingActive]);

  const startAll = async () => {
    const pending = rows.filter((row) => RETRYABLE_STATUSES.includes(row.status) && row.title.trim());
    if (!pending.length) return;
    if (!await refreshSession()) {
      toast({ title: "Session expired", description: "Please log in again.", variant: "destructive" });
      return;
    }
    // Only removals made during this run may cancel work in it.
    removedRef.current = new Set();
    batchRanRef.current = true;
    setRunning(true);
    await runQueue(pending);
    setRunning(false);
  };

  return (
    <Card className="w-full max-w-6xl">
      <CardHeader>
        <CardTitle className="text-2xl font-comic">Bulk upload comics</CardTitle>
        <CardDescription>Add enabled comic formats ({comicFormats.join(", ").toUpperCase()}). Two comics upload at a time.</CardDescription>
      </CardHeader>
      <CardContent className="space-y-5">
        <div
          className={`rounded-lg border-2 border-dashed p-8 text-center ${dragging ? "border-primary bg-primary/5" : "border-gray-300"} ${running ? "opacity-60" : "cursor-pointer"}`}
          onClick={() => !running && inputRef.current?.click()}
          onDragEnter={(event) => { event.preventDefault(); if (!running) setDragging(true); }}
          onDragOver={(event) => event.preventDefault()}
          onDragLeave={(event) => { if (event.currentTarget === event.target) setDragging(false); }}
          onDrop={(event) => { event.preventDefault(); setDragging(false); if (!running) addFiles(event.dataTransfer.files); }}
        >
          <input ref={inputRef} type="file" multiple accept={comicFileAccept(comicFormats)} className="hidden" disabled={running} onChange={(event) => { addFiles(event.target.files); event.target.value = ""; }} />
          <Upload className="mx-auto mb-2 h-10 w-10 text-gray-400" />
          <p className="font-medium">Drop supported comic files here or choose files</p>
        </div>

        <div className="space-y-2">
          <Label htmlFor="bulk-tags">Tags applied to every comic (comma-separated)</Label>
          <Input id="bulk-tags" value={tagsInput} onChange={(event) => setTagsInput(event.target.value)} placeholder="manga, favorites, sci-fi" disabled={running} />
        </div>
        <FolderDestinationSelect id="bulk-folder-destination" folders={folders} value={selectedFolderId} onChange={setFolderId} disabled={running || foldersLoading} />

        {rows.length > 0 && (
          <Table>
            <TableHeader><TableRow><TableHead>File</TableHead><TableHead>Title</TableHead><TableHead>Progress</TableHead><TableHead>Status</TableHead><TableHead className="w-28">Actions</TableHead></TableRow></TableHeader>
            <TableBody>
              {rows.map((row) => (
                <TableRow key={row.id}>
                  <TableCell><div className="font-medium">{row.file.name}</div><div className="text-xs text-muted-foreground">{formatFileSize(row.file.size)}</div></TableCell>
                  <TableCell><Input value={row.title} aria-label={`Title for ${row.file.name}`} disabled={running || row.status === "done"} onChange={(event) => updateRow(row.id, { title: event.target.value })} /></TableCell>
                  <TableCell className="min-w-36"><Progress value={row.progress} /><span className="text-xs text-muted-foreground">{row.progress}%</span></TableCell>
                  <TableCell>
                    <div className="flex items-center gap-1">{ACTIVE_STATUSES.has(row.status) && <Loader2 className="h-4 w-4 animate-spin" />}{row.status === "done" && <CheckCircle2 className="h-4 w-4 text-green-600" />}{statusLabel(row.status)}</div>
                    {row.error && <div className="max-w-52 text-xs text-destructive">{row.error}</div>}
                    {row.comic?.id && <Link className="text-xs text-comic-purple underline" to={`/read/${row.comic.id}`}>Open comic</Link>}
                  </TableCell>
                  <TableCell>
                    <div className="flex items-center gap-1">
                      {ACTIVE_STATUSES.has(row.status) && (
                        <Button size="icon" variant="outline" onClick={() => controllers.current.get(row.id)?.abort()} aria-label={`Cancel ${row.file.name}`}><XIcon /></Button>
                      )}
                      {(row.status === "error" || row.status === "cancelled") && (
                        <Button size="icon" variant="outline" disabled={running} onClick={async () => {
                          setRunning(true);
                          await uploadRow(row);
                          setRunning(false);
                        }} aria-label={`Retry ${row.file.name}`}><RotateCcw className="h-4 w-4" /></Button>
                      )}
                      {/* Anything not in flight and not already uploaded. A file
                          mid-upload is cancelled first, and an uploaded one is a
                          comic in the library — deleting that is the library's
                          job, not this queue's. */}
                      {!ACTIVE_STATUSES.has(row.status) && row.status !== "done" && (
                        <Button size="icon" variant="ghost" onClick={() => removeRow(row.id)} aria-label={`Remove ${row.file.name}`}><Trash2 className="h-4 w-4" /></Button>
                      )}
                    </div>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}

        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="text-sm text-muted-foreground">
            {allFinished ? `${completed} completed, ${failed} failed or cancelled.` : `${rows.length} file${rows.length === 1 ? "" : "s"} queued.`}
          </div>
          <div className="flex gap-2">
            {allFinished && <Button variant="outline" asChild><Link to="/dashboard">View library</Link></Button>}
            <Button onClick={startAll} disabled={running || foldersLoading || !rows.some((row) => RETRYABLE_STATUSES.includes(row.status) && row.title.trim())}>
              {running ? <><Loader2 className="mr-2 h-4 w-4 animate-spin" />Uploading…</> : "Start all"}
            </Button>
          </div>
        </div>
      </CardContent>
    </Card>
  );
}

function XIcon() {
  return <span aria-hidden="true" className="text-lg leading-none">×</span>;
}
