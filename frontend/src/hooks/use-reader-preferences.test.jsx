import { act, renderHook, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { useReaderPreferences } from "./use-reader-preferences";
import { api } from "@/lib/api";
import { DEFAULT_READER_PREFERENCES } from "@/lib/reader-preferences";

vi.mock("@/lib/api", () => ({ api: { get: vi.fn(), put: vi.fn(), delete: vi.fn() } }));
vi.mock("@/lib/logger", () => ({ logger: { error: vi.fn(), warn: vi.fn() } }));

describe("reader preference persistence", () => {
  beforeEach(() => {
    vi.mocked(api.get).mockReset();
    vi.mocked(api.get).mockResolvedValue({ preferences: DEFAULT_READER_PREFERENCES });
    vi.mocked(api.put).mockReset();
    vi.mocked(api.delete).mockReset();
  });

  it("marks local defaults as unsynced when account preferences cannot be loaded", async () => {
    const toast = vi.fn();
    vi.mocked(api.get).mockRejectedValue(new Error("offline"));
    const { result } = renderHook(() => useReaderPreferences(toast));

    await waitFor(() => expect(result.current.isLoaded).toBe(true));
    expect(result.current.hasSyncError).toBe(true);
    expect(result.current.preferences).toEqual(DEFAULT_READER_PREFERENCES);
    expect(toast).toHaveBeenCalledWith(expect.objectContaining({ title: "Using default reader settings" }));
  });

  it("keeps an honest error state when the final account save fails", async () => {
    const toast = vi.fn();
    vi.mocked(api.put).mockRejectedValue(new Error("offline"));
    const { result } = renderHook(() => useReaderPreferences(toast));
    await waitFor(() => expect(result.current.isLoaded).toBe(true));

    act(() => result.current.changeSettings({ fit: "width" }));

    await waitFor(() => expect(result.current.hasSyncError).toBe(true));
    expect(result.current.preferences.settings.fit).toBe("width");
    expect(toast).toHaveBeenCalledWith(expect.objectContaining({ title: "Reader setting not saved" }));
  });

  it("does not report a superseded failure when the newer full replacement succeeds", async () => {
    const toast = vi.fn();
    let rejectFirst;
    vi.mocked(api.put)
      .mockImplementationOnce(() => new Promise((_resolve, reject) => { rejectFirst = reject; }))
      .mockImplementation((_path, body) => Promise.resolve(body));
    const { result } = renderHook(() => useReaderPreferences(toast));
    await waitFor(() => expect(result.current.isLoaded).toBe(true));

    act(() => result.current.changeSettings({ fit: "width" }));
    await waitFor(() => expect(api.put).toHaveBeenCalledTimes(1));
    act(() => result.current.changeSettings({ direction: "rtl" }));
    act(() => rejectFirst(new Error("superseded")));

    await waitFor(() => expect(result.current.isSaving).toBe(false));
    expect(api.put).toHaveBeenCalledTimes(2);
    expect(result.current.hasSyncError).toBe(false);
    expect(result.current.preferences.settings).toMatchObject({ fit: "width", direction: "rtl" });
    expect(toast).not.toHaveBeenCalled();
  });
});
