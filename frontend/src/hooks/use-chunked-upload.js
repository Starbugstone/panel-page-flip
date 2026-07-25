import { useCallback, useRef, useState } from "react";
import { api } from "@/lib/api";
import { CHUNK_SIZE_BYTES } from "@/lib/comic-upload";
import sessionManager from "@/lib/session-manager";

function createFileId() {
  const suffix = globalThis.crypto?.randomUUID?.()
    || `${Date.now()}-${Math.random().toString(36).slice(2)}`;
  return `upload-${suffix}`;
}

export async function uploadComicInChunks({
  file,
  metadata,
  concurrentChunks = 5,
  signal,
  onProgress = () => {},
  onStatus = () => {},
}) {
  if (!file) throw new Error("A comic file is required");

  const fileId = createFileId();
  const totalChunks = Math.ceil(file.size / CHUNK_SIZE_BYTES);
  const workerCount = Math.max(1, Math.min(Number(concurrentChunks) || 1, totalChunks));
  let uploaded = 0;

  onStatus("initialising");
  onProgress(0);
  await api.post("/api/comics/upload/init", {
    fileId,
    filename: file.name,
    totalChunks,
    metadata: {
      title: metadata.title,
      author: metadata.author || "",
      publisher: metadata.publisher || "",
      description: metadata.description || "",
      tags: metadata.tags || [],
    },
  }, { signal });

  onStatus("uploading");
  let nextChunk = 0;
  const uploadNext = async () => {
    while (nextChunk < totalChunks) {
      if (signal?.aborted) throw new DOMException("Upload cancelled", "AbortError");
      const chunkIndex = nextChunk;
      nextChunk += 1;
      const start = chunkIndex * CHUNK_SIZE_BYTES;
      const formData = new FormData();
      formData.append("fileId", fileId);
      formData.append("chunkIndex", String(chunkIndex));
      formData.append("totalChunks", String(totalChunks));
      formData.append("chunk", file.slice(start, Math.min(file.size, start + CHUNK_SIZE_BYTES)), file.name);

      await api.post("/api/comics/upload/chunk", formData, { signal });
      uploaded += 1;
      onProgress(Math.round((uploaded / totalChunks) * 90));
    }
  };

  const keepAlive = setInterval(() => {
    if (!sessionManager.checkInProgress) sessionManager.pingKeepAlive();
  }, 60000);

  try {
    await Promise.all(Array.from({ length: workerCount }, uploadNext));
    onStatus("completing");
    onProgress(95);
    const result = await api.post("/api/comics/upload/complete", { fileId }, { signal });
    onProgress(100);
    onStatus("done");
    return result;
  } finally {
    clearInterval(keepAlive);
  }
}

export function useChunkedUpload({ concurrentChunks = 5 } = {}) {
  const [status, setStatus] = useState("idle");
  const [progress, setProgress] = useState(0);
  const [comic, setComic] = useState(null);
  const [error, setError] = useState(null);
  const controllerRef = useRef(null);

  const start = useCallback(async (file, metadata, callbacks = {}) => {
    const controller = new AbortController();
    controllerRef.current = controller;
    setComic(null);
    setError(null);

    try {
      const result = await uploadComicInChunks({
        file,
        metadata,
        concurrentChunks,
        signal: controller.signal,
        onProgress: (value) => {
          setProgress(value);
          callbacks.onProgress?.(value);
        },
        onStatus: (value) => {
          setStatus(value);
          callbacks.onStatus?.(value);
        },
      });
      setComic(result.comic);
      return result;
    } catch (uploadError) {
      const normalizedError = uploadError.name === "AbortError"
        ? new Error("Upload cancelled")
        : uploadError;
      setStatus("error");
      setError(normalizedError);
      throw normalizedError;
    } finally {
      controllerRef.current = null;
    }
  }, [concurrentChunks]);

  const cancel = useCallback(() => controllerRef.current?.abort(), []);

  return { start, cancel, status, progress, comic, error };
}
