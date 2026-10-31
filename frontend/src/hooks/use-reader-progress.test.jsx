import { act, renderHook, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { api } from "@/lib/api";
import { useReaderProgress } from "./use-reader-progress";

vi.mock("@/lib/api", () => ({ api: { post: vi.fn() } }));

const toast = vi.fn();
const onSaved = vi.fn();

const comicWith = (pageCount, readingProgress = null) => ({ id: 42, pageCount, readingProgress });

const renderProgress = (initialProps) => renderHook(
  ({ comic, currentPage, currentUnit }) => useReaderProgress({
    comic, comicId: "42", pageCount: comic.pageCount, currentPage, currentUnit, toast, onSaved,
  }),
  { initialProps },
);

const saves = () => vi.mocked(api.post).mock.calls.map(([, body]) => body);

/** What the server answers when it applied the save it was sent. */
const applied = (_path, { currentPage, revision, completed = false }) => Promise.resolve({
  progress: { currentPage, revision, completed },
});

describe("useReaderProgress", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(api.post).mockImplementation(applied);
  });

  describe("marking a comic finished", () => {
    it("reports completion for a final two-page spread, saved under its first page", async () => {
      const comic = comicWith(10);
      const { rerender } = renderProgress({ comic, currentPage: 6, currentUnit: [6, 7] });
      await waitFor(() => expect(saves()).toHaveLength(1));
      expect(saves()[0]).toEqual({ currentPage: 7, revision: 1 });

      rerender({ comic, currentPage: 8, currentUnit: [8, 9] });

      await waitFor(() => expect(saves()).toHaveLength(2));
      expect(saves()[1]).toEqual({ currentPage: 9, revision: 2, completed: true });
    });

    it("finishes a comic resumed on its final spread, as an earlier finish was saved", async () => {
      renderProgress({
        comic: comicWith(10, { currentPage: 9, revision: 4, completed: false }),
        currentPage: 8,
        currentUnit: [8, 9],
      });

      await waitFor(() => expect(saves()).toEqual([{ currentPage: 9, revision: 5, completed: true }]));
    });

    it("does not finish a comic that one opening spread happens to cover", async () => {
      renderProgress({ comic: comicWith(2), currentPage: 0, currentUnit: [0, 1] });

      await waitFor(() => expect(saves()).toEqual([{ currentPage: 1, revision: 1 }]));
    });

    it("does not finish a comic opened on its cover", async () => {
      renderProgress({ comic: comicWith(10), currentPage: 0, currentUnit: [0] });

      await waitFor(() => expect(saves()).toEqual([{ currentPage: 1, revision: 1 }]));
    });

    // Single-page and continuous reading both show only the current page.
    it("leaves one-page-at-a-time reading unfinished until the final page", async () => {
      const comic = comicWith(10);
      const { rerender } = renderProgress({ comic, currentPage: 8, currentUnit: [8] });
      await waitFor(() => expect(saves()).toEqual([{ currentPage: 9, revision: 1 }]));

      rerender({ comic, currentPage: 9, currentUnit: [9] });

      await waitFor(() => expect(saves()[1]).toEqual({ currentPage: 10, revision: 2, completed: true }));
    });

    it("saves once when the unit reaches the final page without a page turn", async () => {
      const comic = comicWith(10);
      const { rerender } = renderProgress({ comic, currentPage: 8, currentUnit: [8] });
      await waitFor(() => expect(saves()).toHaveLength(1));

      rerender({ comic, currentPage: 8, currentUnit: [8, 9] });
      await waitFor(() => expect(saves()).toHaveLength(2));
      expect(saves()[1]).toEqual({ currentPage: 9, revision: 2, completed: true });

      rerender({ comic, currentPage: 8, currentUnit: [8, 9] });
      rerender({ comic, currentPage: 8, currentUnit: [8] });
      rerender({ comic, currentPage: 8, currentUnit: [8, 9] });
      await act(async () => {});
      expect(saves()).toHaveLength(2);
    });
  });

  describe("losing a revision conflict", () => {
    it("sends the overruled page once more above the revision the server kept", async () => {
      vi.mocked(api.post)
        .mockResolvedValueOnce({ progress: { currentPage: 20, revision: 30, completed: false } })
        .mockImplementationOnce(applied);

      renderProgress({ comic: comicWith(40, { currentPage: 5, revision: 3 }), currentPage: 5, currentUnit: [5] });

      await waitFor(() => expect(saves()).toEqual([
        { currentPage: 6, revision: 4 },
        { currentPage: 6, revision: 31 },
      ]));
      await waitFor(() => expect(onSaved).toHaveBeenCalledTimes(1));
      expect(onSaved).toHaveBeenCalledWith("42", { currentPage: 6, revision: 31, completed: false });
    });

    it("recognises a loss to a save that carried the same revision", async () => {
      vi.mocked(api.post)
        .mockResolvedValueOnce({ progress: { currentPage: 20, revision: 4, completed: false } })
        .mockImplementationOnce(applied);

      renderProgress({ comic: comicWith(40, { currentPage: 5, revision: 3 }), currentPage: 5, currentUnit: [5] });

      await waitFor(() => expect(saves()[1]).toEqual({ currentPage: 6, revision: 5 }));
    });

    it("keeps the completion when retrying", async () => {
      vi.mocked(api.post)
        .mockResolvedValueOnce({ progress: { currentPage: 9, revision: 30, completed: false } })
        .mockImplementationOnce(applied);

      renderProgress({ comic: comicWith(10), currentPage: 8, currentUnit: [8, 9] });

      await waitFor(() => expect(saves()[1]).toEqual({ currentPage: 9, revision: 31, completed: true }));
    });

    it("stops after one retry, however often it is overruled", async () => {
      vi.mocked(api.post).mockImplementation((_path, { revision }) => Promise.resolve({
        progress: { currentPage: 20, revision: revision + 10, completed: false },
      }));

      renderProgress({ comic: comicWith(40), currentPage: 5, currentUnit: [5] });

      await waitFor(() => expect(onSaved).toHaveBeenCalledTimes(1));
      expect(saves()).toHaveLength(2);
    });

    it("does not retry when the other save recorded the same position", async () => {
      vi.mocked(api.post).mockResolvedValue({ progress: { currentPage: 6, revision: 30, completed: false } });

      renderProgress({ comic: comicWith(40), currentPage: 5, currentUnit: [5] });

      await waitFor(() => expect(onSaved).toHaveBeenCalledTimes(1));
      expect(saves()).toHaveLength(1);
    });

    it("continues above the retried revision on the next page turn", async () => {
      vi.mocked(api.post)
        .mockResolvedValueOnce({ progress: { currentPage: 20, revision: 30, completed: false } })
        .mockImplementation(applied);
      const comic = comicWith(40);
      const { rerender } = renderProgress({ comic, currentPage: 5, currentUnit: [5] });
      await waitFor(() => expect(saves()).toHaveLength(2));

      rerender({ comic, currentPage: 6, currentUnit: [6] });

      await waitFor(() => expect(saves()[2]).toEqual({ currentPage: 7, revision: 32 }));
    });

    it("does not retry a page the reader has already turned away from", async () => {
      let answerFirst;
      vi.mocked(api.post)
        .mockImplementationOnce(() => new Promise((resolve) => { answerFirst = resolve; }))
        .mockImplementation(applied);
      const comic = comicWith(40);
      const { rerender } = renderProgress({ comic, currentPage: 5, currentUnit: [5] });
      await waitFor(() => expect(saves()).toHaveLength(1));

      rerender({ comic, currentPage: 6, currentUnit: [6] });
      await waitFor(() => expect(saves()).toHaveLength(2));
      await act(async () => {
        answerFirst({ progress: { currentPage: 20, revision: 30, completed: false } });
      });

      expect(saves().map(({ currentPage }) => currentPage)).toEqual([6, 7]);
    });
  });
});
