import { useCallback, useEffect, useRef, useState } from "react";

import {
  createReaderPageUrl,
  isPageAtVariant,
  isUsableImage,
  withForcedReload,
} from "@/lib/reader-pages";

/**
 * The reader's page images: what is decoded, what is in flight, and what may be
 * thrown away.
 *
 * This is deliberately only the *mechanism*. Which pages are worth having is a
 * question about reading mode, spreads and the preload window, and it stays
 * with the reader — {@link queuePages} is handed an order that has already been
 * decided. Mixing the two is what made this hard to follow while it lived
 * inline: the rules for what to fetch were interleaved with the bookkeeping for
 * fetching it.
 *
 * Two things are worth knowing before changing anything here.
 *
 * The cache is mirrored into a ref as well as state. State is what renders; the
 * ref is what the load path reads, because a page turn asks "is this already
 * decoded" between renders and a stale closure would answer for the page before
 * last — fetching an image that is already in memory, or worse, deciding a
 * fresh one is redundant.
 *
 * Every in-flight load is cancellable and cancellation is identity-checked. A
 * reader who turns four pages quickly leaves three `Image` objects whose
 * `onload` will still fire; without the `isCurrent()` guard the last one to
 * arrive wins rather than the one that was asked for.
 */
/**
 * The three things done to the cache once pages are in it: filling it ahead of
 * the reader, emptying what has been read past, and forcing one page back.
 *
 * Background fetches drain one at a time. A queue that started every page at
 * once would put the whole preload window in front of the page somebody is
 * actually waiting for.
 */
function usePageCacheMaintenance({
  isPageReady, loadPage, loadingPagesRef, loadQueueRef, isDrainingRef,
  loadedVariantsRef, updateImageCache, updateLoadedVariants,
}) {
  const queuePages = useCallback((orderedPageIndexes, variant) => {
    loadQueueRef.current = orderedPageIndexes.filter((pageIndex) => (
      !isPageReady(pageIndex, variant) && !loadingPagesRef.current[pageIndex]
    )).map((pageIndex) => () => loadPage(pageIndex, variant, { priority: "low" }));

    const drain = () => {
      if (isDrainingRef.current || loadQueueRef.current.length === 0) return;
      isDrainingRef.current = true;
      const next = loadQueueRef.current.shift();
      next().finally(() => {
        isDrainingRef.current = false;
        drain();
      });
    };
    drain();
  }, [isDrainingRef, isPageReady, loadPage, loadQueueRef, loadingPagesRef]);

  /** Drop decoded pages outside the window, so a long comic does not grow without bound. */
  const evictOutside = useCallback((start, end) => {
    const isOutside = (key) => Number(key) < start || Number(key) > end;
    const without = (previous) => {
      const next = { ...previous };
      const removed = Object.keys(next).filter(isOutside);
      removed.forEach((key) => { delete next[key]; });
      return removed.length > 0 ? next : previous;
    };

    updateImageCache((previous) => {
      Object.keys(previous).filter(isOutside).forEach((key) => { delete loadedVariantsRef.current[key]; });
      return without(previous);
    });
    updateLoadedVariants(without);
  }, [loadedVariantsRef, updateImageCache, updateLoadedVariants]);

  /** Throw one page away and fetch it again from the server rather than the cache. */
  const retryPage = useCallback((pageIndex, variant) => {
    loadingPagesRef.current[pageIndex]?.cancel?.();
    delete loadedVariantsRef.current[pageIndex];
    const withoutPage = (previous) => {
      const next = { ...previous };
      delete next[pageIndex];
      return next;
    };
    updateLoadedVariants(withoutPage);
    updateImageCache(withoutPage);

    return loadPage(pageIndex, variant, { force: true });
  }, [loadPage, loadedVariantsRef, loadingPagesRef, updateImageCache, updateLoadedVariants]);

  return { queuePages, evictOutside, retryPage };
}

export function usePageImageCache({ comicId, pageCount }) {
  const [imageCache, setImageCache] = useState({});
  const [loadedVariants, setLoadedVariants] = useState({});

  const imageCacheRef = useRef({});
  const loadedVariantsRef = useRef({});
  const loadingPagesRef = useRef({});
  const loadQueueRef = useRef([]);
  const isDrainingRef = useRef(false);

  const updateImageCache = useCallback((update) => {
    setImageCache((previous) => {
      const next = typeof update === "function" ? update(previous) : update;
      imageCacheRef.current = next;
      return next;
    });
  }, []);

  const updateLoadedVariants = useCallback((update) => {
    setLoadedVariants((previous) => (typeof update === "function" ? update(previous) : update));
  }, []);

  const cancelAll = useCallback(() => {
    loadQueueRef.current = [];
    Object.values(loadingPagesRef.current).forEach((entry) => entry.cancel?.());
  }, []);

  useEffect(() => cancelAll, [cancelAll]);

  /** Forget everything, for a different comic or a mode that renders its own images. */
  const reset = useCallback(() => {
    cancelAll();
    loadingPagesRef.current = {};
    loadedVariantsRef.current = {};
    updateImageCache({});
    updateLoadedVariants({});
  }, [cancelAll, updateImageCache, updateLoadedVariants]);

  const isPageReady = useCallback((pageIndex, variant) => isPageAtVariant(
    imageCacheRef.current[pageIndex],
    loadedVariantsRef.current[pageIndex],
    variant
  ), []);

  const cancelLoadsExcept = useCallback((keepPages) => {
    // Cancelling settles the old drain. Empty its queue first so its finally
    // callback cannot start a stale preload ahead of the new visible page.
    loadQueueRef.current = [];
    const keep = new Set(keepPages);
    Object.entries(loadingPagesRef.current).forEach(([key, entry]) => {
      if (!keep.has(Number(key))) entry.cancel?.();
    });
  }, []);

  const loadPage = useCallback((pageIndex, variant, { force = false, priority = "high" } = {}) => {
    if (pageIndex < 0 || pageIndex >= pageCount) return Promise.resolve(null);
    if (!force && isPageReady(pageIndex, variant)) return Promise.resolve(imageCacheRef.current[pageIndex]);
    const existing = loadingPagesRef.current[pageIndex];
    if (existing?.variant === variant && !force) return existing.promise;
    existing?.cancel?.();

    let settle = () => {};
    const img = new Image();
    img.decoding = "async";
    img.fetchPriority = priority;
    const entry = {
      variant,
      img,
      promise: null,
      cancel: () => {
        if (loadingPagesRef.current[pageIndex] !== entry) return;
        img.onload = null;
        img.onerror = null;
        try { img.src = ""; } catch { /* Some image shims expose a read-only src. */ }
        delete loadingPagesRef.current[pageIndex];
        settle(null);
      },
    };
    const isCurrent = () => loadingPagesRef.current[pageIndex] === entry;
    entry.promise = new Promise((resolve) => { settle = resolve; });
    loadingPagesRef.current[pageIndex] = entry;
    updateImageCache((previous) => (isUsableImage(previous[pageIndex]) ? previous : { ...previous, [pageIndex]: "loading" }));
    img.onload = () => {
      if (!isCurrent()) return settle(null);
      loadedVariantsRef.current[pageIndex] = variant;
      updateLoadedVariants((previous) => ({ ...previous, [pageIndex]: variant }));
      updateImageCache((previous) => ({ ...previous, [pageIndex]: img }));
      delete loadingPagesRef.current[pageIndex];
      settle(img);
    };
    img.onerror = () => {
      if (!isCurrent()) return settle(null);
      updateImageCache((previous) => (isUsableImage(previous[pageIndex]) ? previous : { ...previous, [pageIndex]: "failed" }));
      delete loadingPagesRef.current[pageIndex];
      settle(null);
    };
    const stableUrl = createReaderPageUrl(comicId, pageIndex + 1, variant);
    img.src = force ? withForcedReload(stableUrl) : stableUrl;
    return entry.promise;
  }, [comicId, isPageReady, pageCount, updateImageCache, updateLoadedVariants]);

  /**
   * Fetch the given pages one at a time, in order.
   *
   * Serially on purpose: these are pages nobody is looking at yet, and letting
   * a dozen of them race the page the reader is actually waiting for is how
   * preloading makes a reader feel slower.
   */
  const { queuePages, evictOutside, retryPage } = usePageCacheMaintenance({
    isPageReady, loadPage, loadingPagesRef, loadQueueRef, isDrainingRef,
    loadedVariantsRef, updateImageCache, updateLoadedVariants,
  });

  return {
    imageCache,
    imageCacheRef,
    loadedVariants,
    isPageReady,
    loadPage,
    cancelLoadsExcept,
    queuePages,
    evictOutside,
    retryPage,
    reset,
  };
}
