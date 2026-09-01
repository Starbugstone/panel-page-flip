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
 * device can say so.
 */
export function useReaderProgress({ comic, comicId, pageCount, currentPage, toast, onSaved }) {
  const abortController = useRef(null);
  const revisionRef = useRef(0);
  const lastSavedPage = useRef(null);
  const sessionComic = useRef(null);
  const isMountedRef = useRef(true);

  useEffect(() => {
    isMountedRef.current = true;
    return () => { isMountedRef.current = false; };
  }, []);

  const save = useCallback(async (pageToSave) => {
    if (!comicId || !comic) return;
    abortController.current?.abort();
    const controller = new AbortController();
    abortController.current = controller;
    const revision = ++revisionRef.current;

    try {
      const response = await api.post(
        `/api/comics/${comicId}/progress`,
        { currentPage: pageToSave, revision },
        { signal: controller.signal, keepalive: true }
      );
      applySavedProgress(response, revisionRef, comicId, onSaved);
    } catch (error) {
      reportSaveError(error, controller, isMountedRef.current, toast);
    } finally {
      if (abortController.current === controller) abortController.current = null;
    }
  }, [comic, comicId, onSaved, toast]);

  useEffect(() => {
    // A different comic's revision and last-saved page have nothing to do with
    // this one, and both have to be put back before the save below reads them —
    // otherwise the page a reader resumes at is mistaken for one already saved.
    if (comic && sessionComic.current !== comic) {
      sessionComic.current = comic;
      lastSavedPage.current = null;
      revisionRef.current = comic?.readingProgress?.revision || 0;
    }

    if (comic && comicId && pageCount > 0 && lastSavedPage.current !== currentPage) {
      lastSavedPage.current = currentPage;
      void save(currentPage + 1);
    }
  }, [comic, comicId, currentPage, pageCount, save]);
}
