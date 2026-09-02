import { useMemo, useRef, useState } from "react";
import { Link } from "react-router-dom";
import { CheckCircle2, Loader2, RotateCcw, Trash2, Upload } from "lucide-react";
import { createUploadRequestPool, uploadComicInChunks } from "@/hooks/use-chunked-upload";
import { useAuth } from "@/hooks/use-auth";
import { useConfig } from "@/hooks/use-config";
import { useToast } from "@/hooks/use-toast";
import {
  comicFileAccept,
  configuredComicFormats,
  configuredConcurrentChunks,
  formatFileSize,
  generateTitleFromFilename,
  isComicFile,
  resolveParallelFiles,
} from "@/lib/comic-upload";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Progress } from "@/components/ui/progress";
import { FolderDestinationSelect } from "@/components/library/FolderDestinationSelect";
import { useLibraryFolders } from "@/hooks/use-library-folders";
import { useUploadFolderDestination } from "@/hooks/use-upload-folder-destination";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";

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

function queueProgress(rows) {
  const completed = rows.filter((row) => row.status === "done").length;
  const failed = rows.filter((row) => row.status === "error" || row.status === "cancelled").length;

  return {
    completed,
    failed,
    allFinished: rows.length > 0 && completed + failed === rows.length,
    hasRetryableRows: rows.some((row) => RETRYABLE_STATUSES.includes(row.status) && row.title.trim()),
  };
}

function BulkDropZone({ dragging, running, inputRef, comicFormats, onDraggingChange, onFiles }) {
  return (
    <div
      className={`rounded-lg border-2 border-dashed p-4 text-center sm:p-8 ${dragging ? "border-primary bg-primary/5" : "border-gray-300"} ${running ? "opacity-60" : "cursor-pointer"}`}
      onClick={() => !running && inputRef.current?.click()}
      onDragEnter={(event) => { event.preventDefault(); if (!running) onDraggingChange(true); }}
      onDragOver={(event) => event.preventDefault()}
      onDragLeave={(event) => { if (event.currentTarget === event.target) onDraggingChange(false); }}
      onDrop={(event) => {
        event.preventDefault();
        onDraggingChange(false);
        if (!running) onFiles(event.dataTransfer.files);
      }}
    >
      <input
        ref={inputRef}
        type="file"
        multiple
        accept={comicFileAccept(comicFormats)}
        className="hidden"
        disabled={running}
        onChange={(event) => {
          onFiles(event.target.files);
          event.target.value = "";
        }}
      />
      <Upload className="mx-auto mb-2 h-10 w-10 text-gray-400" />
      <p className="font-medium">Drop supported comic files here or choose files</p>
    </div>
  );
}

function BulkUploadRow({ row, running, onTitleChange, onCancel, onRetry, onRemove }) {
  const active = ACTIVE_STATUSES.has(row.status);
  const retryable = row.status === "error" || row.status === "cancelled";

  return (
    <TableRow className="grid gap-3 p-3 sm:table-row sm:p-0">
      <TableCell className="block p-0 sm:table-cell sm:p-4">
        <div className="break-all font-medium">{row.file.name}</div>
        <div className="text-xs text-muted-foreground">{formatFileSize(row.file.size)}</div>
      </TableCell>
      <TableCell className="block p-0 sm:table-cell sm:p-4">
        <span className="mb-1 block text-xs font-medium text-muted-foreground sm:sr-only">Title</span>
        <Input
          value={row.title}
          aria-label={`Title for ${row.file.name}`}
          disabled={running || row.status === "done"}
          onChange={(event) => onTitleChange(row.id, event.target.value)}
        />
      </TableCell>
      <TableCell className="block p-0 sm:table-cell sm:min-w-36 sm:p-4">
        <span className="mb-1 block text-xs font-medium text-muted-foreground sm:sr-only">Progress</span>
        <Progress value={row.progress} />
        <span className="text-xs text-muted-foreground">{row.progress}%</span>
      </TableCell>
      <TableCell className="block p-0 sm:table-cell sm:p-4">
        <span className="mb-1 block text-xs font-medium text-muted-foreground sm:sr-only">Status</span>
        <div className="flex items-center gap-1">
          {active && <Loader2 className="h-4 w-4 animate-spin" />}
          {row.status === "done" && <CheckCircle2 className="h-4 w-4 text-green-600" />}
          {statusLabel(row.status)}
        </div>
        {row.error && <div className="max-w-52 text-xs text-destructive">{row.error}</div>}
        {row.comic?.id && <Link className="text-xs text-comic-purple underline" to={`/read/${row.comic.id}`}>Open comic</Link>}
      </TableCell>
      <TableCell className="block p-0 sm:table-cell sm:p-4">
        <div className="flex items-center gap-1">
          {active && (
            <Button size="icon" variant="outline" onClick={() => onCancel(row.id)} aria-label={`Cancel ${row.file.name}`}><XIcon /></Button>
          )}
          {retryable && (
            <Button size="icon" variant="outline" disabled={running} onClick={() => onRetry(row)} aria-label={`Retry ${row.file.name}`}>
              <RotateCcw className="h-4 w-4" />
            </Button>
          )}
          {/* Uploaded rows are comics in the library; deleting those belongs to
              the library, while every inactive unfinished row can leave here. */}
          {!active && row.status !== "done" && (
            <Button size="icon" variant="ghost" onClick={() => onRemove(row.id)} aria-label={`Remove ${row.file.name}`}>
              <Trash2 className="h-4 w-4" />
            </Button>
          )}
        </div>
      </TableCell>
    </TableRow>
  );
}

function BulkUploadTable({ rows, running, onTitleChange, onCancel, onRetry, onRemove }) {
  if (rows.length === 0) return null;

  return (
    <Table className="sm:min-w-[760px]">
      <TableHeader className="hidden sm:table-header-group"><TableRow><TableHead>File</TableHead><TableHead>Title</TableHead><TableHead>Progress</TableHead><TableHead>Status</TableHead><TableHead className="w-28">Actions</TableHead></TableRow></TableHeader>
      <TableBody>
        {rows.map((row) => (
          <BulkUploadRow
            key={row.id}
            row={row}
            running={running}
            onTitleChange={onTitleChange}
            onCancel={onCancel}
            onRetry={onRetry}
            onRemove={onRemove}
          />
        ))}
      </TableBody>
    </Table>
  );
}

function BulkQueueFooter({ rows, progress, running, foldersLoading, onStart }) {
  const summary = progress.allFinished
    ? `${progress.completed} completed, ${progress.failed} failed or cancelled.`
    : `${rows.length} file${rows.length === 1 ? "" : "s"} queued.`;

  return (
    <div className="flex flex-wrap items-center justify-between gap-3">
      <div className="text-sm text-muted-foreground">{summary}</div>
      <div className="flex gap-2">
        {progress.allFinished && <Button variant="outline" asChild><Link to="/dashboard">View library</Link></Button>}
        <Button onClick={onStart} disabled={running || foldersLoading || !progress.hasRetryableRows}>
          {running ? <><Loader2 className="mr-2 h-4 w-4 animate-spin" />Uploading…</> : "Start all"}
        </Button>
      </div>
    </div>
  );
}

export default function BulkUploadQueue() {
  const { toast } = useToast();
  const { refreshSession } = useAuth();
  const { config } = useConfig();
  const { folders, isLoading: foldersLoading, createFolder } = useLibraryFolders();
  const concurrentChunks = configuredConcurrentChunks(config);
  const parallelFiles = resolveParallelFiles(config.upload?.maxParallelFileUploads);
  const comicFormats = configuredComicFormats(config);
  const requestPool = useMemo(
    () => createUploadRequestPool(concurrentChunks),
    [concurrentChunks]
  );
  const [rows, setRows] = useState([]);
  const [tagsInput, setTagsInput] = useState("");
  const [dragging, setDragging] = useState(false);
  const [running, setRunning] = useState(false);
  const { selectedFolderId, setFolderId } = useUploadFolderDestination(folders, foldersLoading);
  const controllers = useRef(new Map());
  // Rows taken out of the queue after it started. The workers below walk a list
  // captured when the run began, so without this a file removed mid-run would
  // still be uploaded after it had left the screen.
  const removedRef = useRef(new Set());
  const inputRef = useRef(null);
  const progress = queueProgress(rows);
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
    await Promise.all(Array.from({ length: Math.min(parallelFiles, selectedRows.length) }, worker));
  };

  const startAll = async () => {
    const pending = rows.filter((row) => RETRYABLE_STATUSES.includes(row.status) && row.title.trim());
    if (!pending.length) return;
    if (!await refreshSession()) {
      toast({ title: "Session expired", description: "Please log in again.", variant: "destructive" });
      return;
    }
    // Only removals made during this run may cancel work in it.
    removedRef.current = new Set();
    setRunning(true);
    await runQueue(pending);
    setRunning(false);
  };

  const retryRow = async (row) => {
    setRunning(true);
    await uploadRow(row);
    setRunning(false);
  };

  return (
    <Card className="w-full max-w-6xl">
      <CardHeader className="p-4 sm:p-6">
        <CardTitle className="text-2xl font-comic">Bulk upload comics</CardTitle>
        <CardDescription>
          Add enabled comic formats ({comicFormats.join(", ").toUpperCase()}).{" "}
          {parallelFiles === 1 ? "One comic uploads" : `${parallelFiles} comics upload`} at a time.
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-5 px-4 pb-4 sm:px-6 sm:pb-6">
        <BulkDropZone
          dragging={dragging}
          running={running}
          inputRef={inputRef}
          comicFormats={comicFormats}
          onDraggingChange={setDragging}
          onFiles={addFiles}
        />

        <div className="space-y-2">
          <Label htmlFor="bulk-tags">Tags applied to every comic (comma-separated)</Label>
          <Input id="bulk-tags" value={tagsInput} onChange={(event) => setTagsInput(event.target.value)} placeholder="manga, favorites, sci-fi" disabled={running} />
        </div>
        <FolderDestinationSelect
          id="bulk-folder-destination"
          folders={folders}
          value={selectedFolderId}
          onChange={setFolderId}
          onCreateFolder={createFolder}
          disabled={running || foldersLoading}
        />

        <BulkUploadTable
          rows={rows}
          running={running}
          onTitleChange={(id, title) => updateRow(id, { title })}
          onCancel={(id) => controllers.current.get(id)?.abort()}
          onRetry={retryRow}
          onRemove={removeRow}
        />

        <BulkQueueFooter rows={rows} progress={progress} running={running} foldersLoading={foldersLoading} onStart={startAll} />
      </CardContent>
    </Card>
  );
}

function XIcon() {
  return <span aria-hidden="true" className="text-lg leading-none">×</span>;
}
