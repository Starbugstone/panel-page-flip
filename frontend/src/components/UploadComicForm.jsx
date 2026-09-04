import { useCallback, useState } from "react";
import { useNavigate } from "react-router-dom";

import { BulkUploadEntryLink } from "@/components/BulkUploadEntryLink.jsx";
import { Loader2, X } from "lucide-react";
import { useAuth } from "@/hooks/use-auth";
import { useChunkedUpload } from "@/hooks/use-chunked-upload";
import { useConfig } from "@/hooks/use-config.jsx";
import { useTags } from "@/hooks/use-tags.jsx";
import { useToast } from "@/hooks/use-toast";
import {
  configuredComicFormats,
  configuredChunkSize,
  configuredConcurrentChunks,
  generateTitleFromFilename,
  isComicFile,
} from "@/lib/comic-upload";
import { describeTagSubmission } from "@/lib/tag-suggestions";
import { ComicFilePicker } from "@/components/upload/ComicFilePicker";
import { TagBadge } from "@/components/TagBadge";
import { TagCombobox } from "@/components/TagCombobox";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Progress } from "@/components/ui/progress";
import { FolderDestinationSelect } from "@/components/library/FolderDestinationSelect";
import { useLibraryFolders } from "@/hooks/use-library-folders";
import { useUploadFolderDestination } from "@/hooks/use-upload-folder-destination";

const STATUS_LABELS = {
  initialising: "Preparing upload…",
  uploading: "Uploading comic…",
  completing: "Processing comic…",
  done: "Upload complete",
  error: "Upload failed",
};
function folderSearch(folderId) {
  return `?folder=${folderId == null ? "root" : folderId}`;
}

function UploadFileDropZone({ file, uploading, comicFormats, onChoose, onRemove }) {
  return (
    <div className="space-y-2">
      <Label htmlFor="comic-file">Comic File ({comicFormats.join(", ").toUpperCase()})</Label>
      <ComicFilePicker id="comic-file" formats={comicFormats} disabled={uploading} onFiles={(files) => onChoose(files[0])}>
        {file ? (
          <div className="flex min-w-0 items-center justify-center gap-2">
            <span className="min-w-0 break-all text-sm font-medium">{file.name}</span>
            {!uploading && <Button type="button" variant="ghost" size="icon" onClick={onRemove} aria-label="Remove file"><X aria-hidden="true" /></Button>}
          </div>
        ) : <p className="text-sm text-muted-foreground">Drag and drop or select a supported comic file</p>}
      </ComicFilePicker>
    </div>
  );
}

function UploadProgress({ status, progress }) {
  if (status === "idle") return null;

  return (
    <div className="space-y-2" aria-live="polite">
      <div className="flex justify-between text-sm"><span>{STATUS_LABELS[status]}</span><span>{progress}%</span></div>
      <Progress value={progress} className="h-2" />
    </div>
  );
}

function UploadTags({
  tagInput, onTagInputChange, onAddTag, tags, availableTags, uploading, canAddTag, onRemoveTag,
}) {
  return (
    <div className="space-y-2">
      <Label htmlFor="tags">Tags</Label>
      <div className="relative flex gap-2">
        <TagCombobox
          id="tags"
          value={tagInput}
          onChange={onTagInputChange}
          onSubmit={onAddTag}
          applied={tags}
          disabled={uploading}
          placeholder="Add tags…"
          label="Add tags"
          className="min-w-0 flex-1"
        />
        <Button type="button" variant="outline" onClick={() => onAddTag()} disabled={uploading || !canAddTag}>Add</Button>
      </div>
      <div className="flex flex-wrap gap-2">
        {tags.map((tag) => (
          <TagBadge key={tag} tag={availableTags.find((item) => item.name === tag) || tag} className="flex min-w-0 max-w-full items-center gap-1 rounded-md px-2 py-1 text-sm">
            <span className="truncate">{tag}</span>
            {!uploading && (
              <button type="button" onClick={() => onRemoveTag(tag)} aria-label={`Remove ${tag}`}><X size={12} /></button>
            )}
          </TagBadge>
        ))}
      </div>
    </div>
  );
}

function UploadActions({ file, title, uploading, canCancel, foldersLoading, onCancel, onBack }) {
  return (
    <CardFooter className="justify-between px-4 pb-4 sm:px-6 sm:pb-6">
      <Button variant="outline" type="button" disabled={uploading && !canCancel} onClick={canCancel ? onCancel : onBack}>
        {canCancel ? "Cancel upload" : "Back"}
      </Button>
      <Button type="submit" form="upload-form" disabled={!file || !title.trim() || uploading || foldersLoading}>
        {uploading ? <><Loader2 className="mr-2 h-4 w-4 animate-spin" />Uploading…</> : "Upload Comic"}
      </Button>
    </CardFooter>
  );
}

export default function UploadComicForm() {
  const navigate = useNavigate();
  const { toast } = useToast();
  const { refreshSession } = useAuth();
  const { config } = useConfig();
  const { tags: availableTags, addTagToCache } = useTags();
  const { folders, isLoading: foldersLoading, createFolder } = useLibraryFolders();
  const concurrentChunks = configuredConcurrentChunks(config);
  const comicFormats = configuredComicFormats(config);
  const { start, cancel, status, progress } = useChunkedUpload({ concurrentChunks, chunkSize: configuredChunkSize(config) });
  const [submitting, setSubmitting] = useState(false);
  const canCancel = ["initialising", "uploading", "completing"].includes(status);
  const uploading = submitting || canCancel || status === "done";

  const [file, setFile] = useState(null);
  const [title, setTitle] = useState("");
  const [author, setAuthor] = useState("");
  const [tags, setTags] = useState([]);
  const [tagInput, setTagInput] = useState("");
  const { selectedFolderId, setFolderId } = useUploadFolderDestination(folders, foldersLoading);

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
    if (uploading) return;
    if (!file) {
      toast({ title: "No file selected", description: "Select a comic before uploading.", variant: "destructive" });
      return;
    }
    setSubmitting(true);
    try {
      if (!await refreshSession()) {
        toast({ title: "Session expired", description: "Please log in again.", variant: "destructive" });
        return;
      }
      const result = await start(file, { title, author, tags, folderId: selectedFolderId });
      result.comic?.tags?.forEach((tag) => {
        if (tag?.id && tag?.name) addTagToCache(tag);
      });
      toast({ title: "Upload successful", description: `${title} is now in your library.` });
      setTimeout(() => navigate(`/dashboard${folderSearch(selectedFolderId)}`), 900);
    } catch (error) {
      toast({
        title: error.message === "Upload cancelled" ? "Upload cancelled" : "Upload failed",
        description: error.message,
        variant: error.message === "Upload cancelled" ? "default" : "destructive",
      });
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Card className="w-full max-w-xl">
      <CardHeader className="p-4 sm:p-6">
        <CardTitle as="h1" className="page-title">Upload New Comic</CardTitle>
        <CardDescription>
          Upload one comic here, or <BulkUploadEntryLink className="text-primary underline" search={folderSearch(selectedFolderId)}>upload several at once</BulkUploadEntryLink>.
        </CardDescription>
      </CardHeader>
      <CardContent className="px-4 pb-4 sm:px-6 sm:pb-6">
        <form id="upload-form" onSubmit={handleSubmit} className="space-y-5">
          <UploadFileDropZone
            file={file}
            uploading={uploading}
            comicFormats={comicFormats}
            onChoose={chooseFile}
            onRemove={() => setFile(null)}
          />

          <UploadProgress status={status} progress={progress} />

          <div className="space-y-2">
            <Label htmlFor="title">Title</Label>
            <Input id="title" value={title} onChange={(event) => setTitle(event.target.value)} required disabled={uploading} />
          </div>
          <div className="space-y-2">
            <Label htmlFor="author">Author (optional)</Label>
            <Input id="author" value={author} onChange={(event) => setAuthor(event.target.value)} disabled={uploading} />
          </div>
          <UploadTags
            tagInput={tagInput}
            onTagInputChange={setTagInput}
            onAddTag={addTag}
            tags={tags}
            availableTags={availableTags}
            uploading={uploading}
            canAddTag={canAddTag}
            onRemoveTag={(tag) => setTags((current) => current.filter((item) => item !== tag))}
          />
          <FolderDestinationSelect
            folders={folders}
            value={selectedFolderId}
            onChange={setFolderId}
            onCreateFolder={createFolder}
            disabled={uploading || foldersLoading}
          />
        </form>
      </CardContent>
      <UploadActions
        file={file}
        title={title}
        uploading={uploading}
        canCancel={canCancel}
        foldersLoading={foldersLoading}
        onCancel={cancel}
        onBack={() => navigate(-1)}
      />
    </Card>
  );
}
