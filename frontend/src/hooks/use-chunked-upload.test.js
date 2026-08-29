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
});
