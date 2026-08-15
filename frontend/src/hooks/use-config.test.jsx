import { renderHook, waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

const auth = vi.hoisted(() => ({ user: { id: 1, email: "a@b.test" } }));
const api = vi.hoisted(() => ({ get: vi.fn() }));

vi.mock("@/hooks/use-auth", () => ({ useAuth: () => auth }));
vi.mock("@/lib/api", () => ({ api }));
vi.mock("@/lib/logger", () => ({
  logger: { log: vi.fn(), warn: vi.fn(), error: vi.fn() },
}));

import { useConfig } from "./use-config";

describe("useConfig", () => {
  afterEach(() => {
    api.get.mockReset();
    auth.user = { id: 1, email: "a@b.test" };
  });

  it("keeps the safe defaults until the server answers", () => {
    api.get.mockReturnValue(new Promise(() => {}));

    const { result } = renderHook(() => useConfig());

    expect(result.current.isLoading).toBe(true);
    expect(result.current.config.upload.comicFormats).toEqual(["cbz"]);
    expect(result.current.config.metadataProviders).toEqual([]);
  });

  it("uses the server configuration once it arrives", async () => {
    api.get.mockResolvedValue({
      upload: { maxConcurrentUploads: 2, comicFormats: ["cbz", "pdf"] },
      metadataProviders: [{ key: "comicvine", label: "Comic Vine" }],
    });

    const { result } = renderHook(() => useConfig());

    await waitFor(() => {
      expect(result.current.isLoading).toBe(false);
    });
    expect(result.current.config.upload.maxConcurrentUploads).toBe(2);
    expect(result.current.config.metadataProviders).toEqual([
      { key: "comicvine", label: "Comic Vine" },
    ]);
  });

  it("does not fetch when nobody is signed in", () => {
    auth.user = null;
    const { result } = renderHook(() => useConfig());

    expect(api.get).not.toHaveBeenCalled();
    expect(result.current.isLoading).toBe(false);
    expect(result.current.config.upload.comicFormats).toEqual(["cbz"]);
  });
});
