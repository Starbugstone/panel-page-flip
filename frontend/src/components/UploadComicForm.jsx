import { useCallback, useRef, useState } from "react";
import { useNavigate, useSearchParams } from "react-router-dom";

import { BulkUploadEntryLink } from "@/components/BulkUploadEntryLink.jsx";
import { Loader2, Upload, X } from "lucide-react";
import { useAuth } from "@/hooks/use-auth";
import { useChunkedUpload } from "@/hooks/use-chunked-upload";
import { useConfig } from "@/hooks/use-config.jsx";
import { useTags } from "@/hooks/use-tags.jsx";
import { useToast } from "@/hooks/use-toast";
import { comicFileAccept, generateTitleFromFilename, isComicFile } from "@/lib/comic-upload";
import { describeTagSubmission } from "@/lib/tag-suggestions";
import { cn } from "@/lib/utils.js";
import { TagBadge } from "@/components/TagBadge";
import { TagCombobox } from "@/components/TagCombobox";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Progress } from "@/components/ui/progress";
import { FolderDestinationSelect } from "@/components/library/FolderDestinationSelect";
import { useLibraryFolders } from "@/hooks/use-library-folders";

const STATUS_LABELS = {
  initialising: "Preparing upload…",
  uploading: "Uploading comic…",
  completing: "Processing comic…",
  done: "Upload complete",
  error: "Upload failed",
};
const DEFAULT_COMIC_FORMATS = ["cbz"];

export default function UploadComicForm() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const { toast } = useToast();
  const { refreshSession } = useAuth();
  const { config } = useConfig();
  const { tags: availableTags, addTagToCache } = useTags();
  const { folders, isLoading: foldersLoading, createFolder } = useLibraryFolders();
  const concurrentChunks = config.upload?.maxConcurrentUploads || 4;
  const comicFormats = config.upload?.comicFormats || DEFAULT_COMIC_FORMATS;
  const { start, cancel, status, progress } = useChunkedUpload({ concurrentChunks });
  const uploading = ["initialising", "uploading", "completing"].includes(status);

  const [file, setFile] = useState(null);
  const [title, setTitle] = useState("");
  const [author, setAuthor] = useState("");
  const [tags, setTags] = useState([]);
  const [tagInput, setTagInput] = useState("");
  const [dragging, setDragging] = useState(false);
  const requestedFolder = searchParams.get("folder");
  const [folderId, setFolderId] = useState(() => requestedFolder && /^\d+$/.test(requestedFolder) ? Number(requestedFolder) : null);
  const selectedFolderId = folderId != null && (foldersLoading || folders.some((folder) => Number(folder.id) === folderId)) ? folderId : null;
  const inputRef = useRef(null);

  const chooseFile = useCallback((candidate) => {
    if (!isComicFile(candidate, comicFormats)) {
      toast({ title: "Invalid file", description: `Enabled formats: ${comicFormats.join(", ").toUpperCase()}.`, variant: "destructive" });
      return;
    }
    setFile(candidate);
    setTitle((current) => current.trim() || generateTitleFromFilename(candidate.name));
  }, [comicFormats, toast]);

  // Resolves what is typed against the tags this user already has, so uploading
  // does not mint a second spelling of a tag they already use.
  const tagSubmission = describeTagSubmission(availableTags, tagInput, tags);
  const canAddTag = tagSubmission.status === "existing" || tagSubmission.status === "new";

  const addTag = useCallback((value) => {
    const nextTag = value ?? describeTagSubmission(availableTags, tagInput, tags).name;
    if (nextTag && !tags.includes(nextTag)) setTags((current) => [...current, nextTag]);
    setTagInput("");
  }, [availableTags, tagInput, tags]);

  const handleSubmit = async (event) => {
    event.preventDefault();
    if (!file) {
      toast({ title: "No file selected", description: "Select a comic before uploading.", variant: "destructive" });
      return;
    }
    if (!await refreshSession()) {
      toast({ title: "Session expired", description: "Please log in again.", variant: "destructive" });
      return;
    }

    try {
      const result = await start(file, { title, author, tags, folderId: selectedFolderId });
      result.comic?.tags?.forEach((tag) => {
        if (tag?.id && tag?.name) addTagToCache(tag);
      });
      toast({ title: "Upload successful", description: `${title} is now in your library.` });
      setTimeout(() => navigate(`/dashboard?folder=${selectedFolderId == null ? "root" : selectedFolderId}`), 900);
    } catch (error) {
      toast({
        title: error.message === "Upload cancelled" ? "Upload cancelled" : "Upload failed",
        description: error.message,
        variant: error.message === "Upload cancelled" ? "default" : "destructive",
      });
    }
  };

  return (
    <Card className="w-full max-w-xl">
      <CardHeader>
        <CardTitle className="text-2xl font-comic">Upload New Comic</CardTitle>
        <CardDescription>
          Upload one comic here, or <BulkUploadEntryLink className="text-comic-purple underline" search={`?folder=${selectedFolderId == null ? "root" : selectedFolderId}`}>upload several at once</BulkUploadEntryLink>.
        </CardDescription>
      </CardHeader>
      <CardContent>
        <form id="upload-form" onSubmit={handleSubmit} className="space-y-5">
          <div className="space-y-2">
            <Label htmlFor="comic-file">Comic File ({comicFormats.join(", ").toUpperCase()})</Label>
            <div
              className={cn(
                "rounded-lg border-2 border-dashed p-6 text-center",
                !uploading && "cursor-pointer hover:border-gray-400",
                dragging ? "border-primary bg-primary/5" : "border-gray-300 dark:border-gray-600",
              )}
              onClick={() => !uploading && inputRef.current?.click()}
              onDragEnter={(event) => { event.preventDefault(); if (!uploading) setDragging(true); }}
              onDragOver={(event) => event.preventDefault()}
              onDragLeave={(event) => { if (event.currentTarget === event.target) setDragging(false); }}
              onDrop={(event) => {
                event.preventDefault();
                setDragging(false);
                if (!uploading) chooseFile(event.dataTransfer.files[0]);
              }}
            >
              <input
                ref={inputRef}
                id="comic-file"
                type="file"
                accept={comicFileAccept(comicFormats)}
                className="hidden"
                disabled={uploading}
                onChange={(event) => chooseFile(event.target.files[0])}
              />
              {file ? (
                <div className="flex items-center justify-center gap-2">
                  <span className="text-sm font-medium">{file.name}</span>
                  {!uploading && (
                    <Button type="button" variant="ghost" size="sm" className="h-auto p-0" onClick={(event) => { event.stopPropagation(); setFile(null); }} aria-label="Remove file">
                      <X size={16} />
                    </Button>
                  )}
                </div>
              ) : (
                <div className="flex flex-col items-center">
                  <Upload className="mb-2 h-10 w-10 text-gray-400" />
                  <span className="text-sm font-medium">Drag and drop or select a supported comic file</span>
                </div>
              )}
            </div>
          </div>

          {status !== "idle" && (
            <div className="space-y-2" aria-live="polite">
              <div className="flex justify-between text-sm"><span>{STATUS_LABELS[status]}</span><span>{progress}%</span></div>
              <Progress value={progress} className="h-2" />
            </div>
          )}

          <div className="space-y-2">
            <Label htmlFor="title">Title</Label>
            <Input id="title" value={title} onChange={(event) => setTitle(event.target.value)} required disabled={uploading} />
          </div>
          <div className="space-y-2">
            <Label htmlFor="author">Author (optional)</Label>
            <Input id="author" value={author} onChange={(event) => setAuthor(event.target.value)} disabled={uploading} />
          </div>
          <div className="space-y-2">
            <Label htmlFor="tags">Tags</Label>
            <div className="relative flex gap-2">
              <TagCombobox
                id="tags"
                value={tagInput}
                onChange={setTagInput}
                onSubmit={addTag}
                applied={tags}
                disabled={uploading}
                placeholder="Add tags…"
                label="Add tags"
                className="flex-1"
              />
              <Button type="button" variant="outline" onClick={() => addTag()} disabled={uploading || !canAddTag}>Add</Button>
            </div>
            <div className="flex flex-wrap gap-2">
              {tags.map((tag) => (
                <TagBadge key={tag} tag={availableTags.find((item) => item.name === tag) || tag} className="flex items-center gap-1 rounded-md px-2 py-1 text-sm">
                  {tag}
                  {!uploading && <button type="button" onClick={() => setTags((current) => current.filter((item) => item !== tag))} aria-label={`Remove ${tag}`}><X size={12} /></button>}
                </TagBadge>
              ))}
            </div>
          </div>
          <FolderDestinationSelect
            folders={folders}
            value={selectedFolderId}
            onChange={setFolderId}
            onCreateFolder={createFolder}
            disabled={uploading || foldersLoading}
          />
        </form>
      </CardContent>
      <CardFooter className="justify-between">
        <Button variant="outline" type="button" onClick={() => uploading ? cancel() : navigate(-1)}>{uploading ? "Cancel upload" : "Back"}</Button>
        <Button type="submit" form="upload-form" disabled={!file || !title.trim() || uploading || foldersLoading}>
          {uploading ? <><Loader2 className="mr-2 h-4 w-4 animate-spin" />Uploading…</> : "Upload Comic"}
        </Button>
      </CardFooter>
    </Card>
  );
}
