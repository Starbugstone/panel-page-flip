import { useCallback, useRef, useState } from "react";
import { api } from "@/lib/api";
import { CHUNK_SIZE_BYTES } from "@/lib/comic-upload";
import sessionManager from "@/lib/session-manager";

function createFileId() {
  const suffix = globalThis.crypto?.randomUUID?.()
    || `${Date.now()}-${Math.random().toString(36).slice(2)}`;
  return `upload-${suffix}`;
}

/**
 * One request budget shared by every file in a batch.
 *
 * `MAX_CONCURRENT_UPLOADS` is the number of upload requests the server can
 * absorb at once, not a multiplier per file. Bulk upload runs two files in
 * parallel; giving each one its own pool used to turn a limit of five into ten
 * simultaneous PHP requests and exhaust the five-worker Docker FPM pool.
 */
export function createUploadRequestPool(limit) {
  const capacity = Math.max(1, Math.floor(Number(limit) || 1));
  const waiting = [];
  let active = 0;

  const dispatch = () => {
    while (active < capacity && waiting.length > 0) {
      const entry = waiting.shift();
      if (entry.signal?.aborted) {
        entry.cleanup();
        entry.reject(new DOMException("Upload cancelled", "AbortError"));
        continue;
      }

      active += 1;
      entry.cleanup();
      Promise.resolve()
        .then(entry.request)
        .then(
          (value) => {
            active -= 1;
            entry.resolve(value);
            dispatch();
          },
          (error) => {
            active -= 1;
            entry.reject(error);
            dispatch();
          }
        );
    }
  };

  return (request, { signal } = {}) => new Promise((resolve, reject) => {
    if (signal?.aborted) {
      reject(new DOMException("Upload cancelled", "AbortError"));
      return;
    }

    const entry = { request, resolve, reject, signal, cleanup: () => {} };
    const cancelWaitingRequest = () => {
      const index = waiting.indexOf(entry);
      if (index === -1) return;
      waiting.splice(index, 1);
      reject(new DOMException("Upload cancelled", "AbortError"));
    };
    entry.cleanup = () => signal?.removeEventListener("abort", cancelWaitingRequest);
    signal?.addEventListener("abort", cancelWaitingRequest, { once: true });
    waiting.push(entry);
    dispatch();
  });
}

export async function uploadComicInChunks({
  file,
  metadata,
  concurrentChunks = 4,
  requestPool,
  signal,
  onProgress = () => {},
  onStatus = () => {},
}) {
  if (!file) throw new Error("A comic file is required");

  const fileId = createFileId();
  const totalChunks = Math.ceil(file.size / CHUNK_SIZE_BYTES);
  const workerCount = Math.max(1, Math.min(Number(concurrentChunks) || 1, totalChunks));
  const runRequest = requestPool || createUploadRequestPool(concurrentChunks);
  let uploaded = 0;

  onStatus("initialising");
  onProgress(0);
  await runRequest(() => api.post("/api/comics/upload/init", {
    fileId,
    filename: file.name,
    totalChunks,
    metadata: {
      title: metadata.title,
      author: metadata.author || "",
      publisher: metadata.publisher || "",
      description: metadata.description || "",
      tags: metadata.tags || [],
      folderId: metadata.folderId ?? null,
    },
  }, { signal }), { signal });

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

      await runRequest(() => api.post("/api/comics/upload/chunk", formData, { signal }), { signal });
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
    const result = await runRequest(
      () => api.post("/api/comics/upload/complete", { fileId }, { signal }),
      { signal }
    );
    onProgress(100);
    onStatus("done");
    return result;
  } finally {
    clearInterval(keepAlive);
  }
}

export function useChunkedUpload({ concurrentChunks = 4 } = {}) {
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
