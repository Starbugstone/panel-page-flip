import { afterEach, describe, expect, it, vi } from "vitest";

const api = vi.hoisted(() => ({ post: vi.fn() }));

vi.mock("@/lib/api", () => ({ api }));
vi.mock("@/lib/session-manager", () => ({
  default: { checkInProgress: false, pingKeepAlive: vi.fn() },
}));

import { createUploadRequestPool, uploadComicInChunks } from "./use-chunked-upload";
import { CHUNK_SIZE_BYTES } from "@/lib/comic-upload";

const comic = (name) => ({
  name,
  size: CHUNK_SIZE_BYTES * 4,
  slice: () => new Blob([new Uint8Array(1)]),
});

describe("chunked upload request concurrency", () => {
  afterEach(() => {
    api.post.mockReset();
  });

  it("shares one request ceiling across simultaneous files, including completion", async () => {
    let active = 0;
    let maximumActive = 0;
    api.post.mockImplementation(async (path) => {
      active += 1;
      maximumActive = Math.max(maximumActive, active);
      await new Promise((resolve) => setTimeout(resolve, 2));
      active -= 1;

      return path.endsWith("/complete") ? { comic: { id: 1 } } : {};
    });

    const requestPool = createUploadRequestPool(2);
    await Promise.all([
      uploadComicInChunks({ file: comic("one.cbz"), metadata: { title: "One" }, concurrentChunks: 5, requestPool }),
      uploadComicInChunks({ file: comic("two.cbz"), metadata: { title: "Two" }, concurrentChunks: 5, requestPool }),
    ]);

    expect(maximumActive).toBe(2);
    expect(api.post.mock.calls.filter(([path]) => path.endsWith("/complete"))).toHaveLength(2);
  });

  it("removes an aborted request while it is waiting for capacity", async () => {
    let releaseFirst;
    const pool = createUploadRequestPool(1);
    const first = pool(() => new Promise((resolve) => { releaseFirst = resolve; }));
    const controller = new AbortController();
    const waiting = pool(() => Promise.resolve("too late"), { signal: controller.signal });

    controller.abort();

    await expect(waiting).rejects.toMatchObject({ name: "AbortError" });
    releaseFirst("done");
    await expect(first).resolves.toBe("done");
  });

  it("aborts sibling chunks after a failure and preserves the server error", async () => {
    const failure = new Error("Storage is full");
    const siblingSignals = [];
    api.post.mockImplementation(async (path, data, { signal }) => {
      if (!path.endsWith("/chunk")) return {};
      if (data.get("chunkIndex") === "0") throw failure;
      siblingSignals.push(signal);
      return new Promise((resolve, reject) => {
        signal.addEventListener("abort", () => reject(new DOMException("Aborted", "AbortError")), { once: true });
      });
    });

    await expect(uploadComicInChunks({
      file: comic("failed.cbz"), metadata: { title: "Failed" }, concurrentChunks: 2,
    })).rejects.toBe(failure);

    expect(siblingSignals).toHaveLength(1);
    expect(siblingSignals[0].aborted).toBe(true);
    expect(api.post.mock.calls.some(([path]) => path.endsWith("/complete"))).toBe(false);
  });

  it("uses the negotiated chunk size and reports progress by bytes", async () => {
    const file = new File([new Uint8Array(CHUNK_SIZE_BYTES * 2 + 1)], "comic.cbz");
    const onProgress = vi.fn();
    api.post.mockResolvedValue({ comic: { id: 1 } });

    await uploadComicInChunks({ file, metadata: { title: "Comic" }, chunkSize: CHUNK_SIZE_BYTES * 2, onProgress });

    expect(api.post.mock.calls[0][1].totalChunks).toBe(2);
    const chunks = api.post.mock.calls.filter(([path]) => path.endsWith("/chunk"));
    expect(chunks.map(([, data]) => data.get("chunk").size)).toEqual([CHUNK_SIZE_BYTES * 2, 1]);
    expect(onProgress.mock.calls.map(([value]) => value)).toEqual([0, 90, 95, 100]);
  });
});
