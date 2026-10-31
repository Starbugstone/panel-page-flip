import { useCallback, useEffect, useRef } from "react";

import { api } from "@/lib/api";
import { logger } from "@/lib/logger";

function applySavedProgress(response, revisionRef, comicId, onSaved) {
  const savedProgress = response?.progress;
  const savedRevision = savedProgress?.revision;

  if (typeof savedRevision === "number" && savedRevision > revisionRef.current) {
    revisionRef.current = savedRevision;
  }
  if (savedProgress) onSaved(comicId, savedProgress);
}

/**
 * Whether the server kept another save instead of this one.
 *
 * It applies a save only above the stored revision and answers with whatever it
 * kept, so two savers sending the same revision is a loss as well. Another save
 * that recorded the same position has lost this one nothing.
 */
function wasOverruled(savedProgress, sent) {
  if (typeof savedProgress?.revision !== "number" || savedProgress.revision < sent.revision) return false;

  return savedProgress.currentPage !== sent.currentPage || (sent.completed && !savedProgress.completed);
}

function isRetryableNetworkError(error) {
  return error.name === "TypeError" && error.message.includes("Failed to fetch");
}

function reportSaveError(error, controller, isMounted, toast) {
  if (error.name === "AbortError" || controller.signal.aborted) return;

  // Losing the network is not something to interrupt reading over; the next
  // page turn saves this page and the one after it together.
  if (isRetryableNetworkError(error)) {
    logger.warn("Network error when saving reading progress - will retry on next page change");
    return;
  }

  logger.error("Failed to save reading progress:", error);
  if (!isMounted) return;

  toast({
    title: "Error saving progress",
    description: error.message || "Could not save your reading progress. Please try again.",
    variant: "destructive",
  });
}

/**
 * Where the reader got to, kept on the server.
 *
 * Every page turn supersedes the one before it, so a save in flight is aborted
 * rather than raced: the last page turned to is the only one worth recording,
 * and two answers arriving out of order would record the wrong one. The
 * revision travels with each save so a server that has heard from another
 * device can say so; the overruled save is sent once more above that revision.
 *
 * A save reports the comic finished once the final page is in the visible unit,
 * because a closing spread is saved under its first page and never reaches the
 * final page number. The first page does not count: a comic short enough to fit
 * one spread would be finished by opening it. Any later position was reached by
 * reading, in this session or the one being resumed.
 */
export function useReaderProgress({ comic, comicId, pageCount, currentPage, currentUnit, toast, onSaved }) {
  const abortController = useRef(null);
  const revisionRef = useRef(0);
  const lastSavedPage = useRef(null);
  const completionSaved = useRef(false);
  const sessionComic = useRef(null);
  const isMountedRef = useRef(true);

  useEffect(() => {
    isMountedRef.current = true;
    return () => { isMountedRef.current = false; };
  }, []);

  const save = useCallback(async (pageToSave, completed) => {
    if (!comicId || !comic) return;
    abortController.current?.abort();
    const controller = new AbortController();
    abortController.current = controller;

    const send = (revision) => api.post(
      `/api/comics/${comicId}/progress`,
      { currentPage: pageToSave, revision, ...(completed ? { completed } : {}) },
      { signal: controller.signal, keepalive: true }
    );

    try {
      const revision = ++revisionRef.current;
      let response = await send(revision);
      // A later page turn would have aborted this save, so its page is still the
      // one wanted. Only one retry per save: two devices can each overrule the
      // other once, but neither answer can set off another round.
      if (wasOverruled(response?.progress, { currentPage: pageToSave, completed, revision }) && !controller.signal.aborted) {
        const retryRevision = response.progress.revision + 1;
        revisionRef.current = Math.max(revisionRef.current, retryRevision);
        response = await send(retryRevision);
      }
      applySavedProgress(response, revisionRef, comicId, onSaved);
    } catch (error) {
      reportSaveError(error, controller, isMountedRef.current, toast);
    } finally {
      if (abortController.current === controller) abortController.current = null;
    }
  }, [comic, comicId, onSaved, toast]);

  const showsFinalPage = currentPage > 0 && currentUnit.includes(pageCount - 1);

  useEffect(() => {
    // A different comic's revision and last-saved page have nothing to do with
    // this one, and both have to be put back before the save below reads them —
    // otherwise the page a reader resumes at is mistaken for one already saved.
    if (comic && sessionComic.current !== comic) {
      sessionComic.current = comic;
      lastSavedPage.current = null;
      completionSaved.current = Boolean(comic?.readingProgress?.completed);
      revisionRef.current = comic?.readingProgress?.revision || 0;
    }

    // The unit can reach the final page without a page turn, when the saved
    // two-page preference arrives or the device is rotated.
    const newlyFinished = showsFinalPage && !completionSaved.current;
    if (comic && comicId && pageCount > 0 && (lastSavedPage.current !== currentPage || newlyFinished)) {
      lastSavedPage.current = currentPage;
      if (showsFinalPage) completionSaved.current = true;
      void save(currentPage + 1, showsFinalPage);
    }
  }, [comic, comicId, currentPage, pageCount, save, showsFinalPage]);
}
