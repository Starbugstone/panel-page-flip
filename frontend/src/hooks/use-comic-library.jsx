import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { useAuth } from './use-auth';
import { useToast } from './use-toast';
import { api } from '@/lib/api';
import { logger } from '@/lib/logger';
import { fuzzyFilter } from '@/lib/fuzzy-search';
import {
  applyProgressUpdate,
  createMutationLog,
  libraryRequestKey,
  normaliseComics,
  removeComics,
} from '@/lib/comic-library';

const FUZZY_FIELDS = ['title', 'author', 'publisher', 'description', 'tags'];

const ComicLibraryContext = createContext(undefined);

/**
 * Holds the comic library outside the Dashboard so leaving for the reader and
 * coming back does not throw the list away and rebuild it from an empty state.
 *
 * Metadata only. The covers themselves are cached by the browser against their
 * versioned URLs.
 */
export function ComicLibraryProvider({ children }) {
  const [comics, setComics] = useState([]);
  // Starts true: nothing has been loaded yet, so the dashboard shows its
  // skeleton on the very first paint instead of flashing "no comics yet".
  const [isLoading, setIsLoading] = useState(true);
  const [isRefreshing, setIsRefreshing] = useState(false);
  const [error, setError] = useState(null);
  const { user } = useAuth();
  const { toast } = useToast();

  // Mirrors of the state loadLibrary reads, so the callback can stay stable and
  // not restart the effect that calls it on every render.
  const comicsRef = useRef([]);
  // The request most recently started, and the one whose comics are on screen.
  // They differ while a load is in flight.
  const activeKeyRef = useRef(null);
  const displayedKeyRef = useRef(null);
  // Two loads can share a key — the dashboard asks for '/api/comics' again after
  // an upload or a delete — so the key alone cannot tell a stale response from
  // the current one. Every load also takes a number, and only the latest number
  // is allowed to write.
  const requestIdRef = useRef(0);
  // Deletions and saved reading positions that happened while a fetch was out.
  // The response predates them, so they are replayed over it.
  const mutationLogRef = useRef(null);
  if (mutationLogRef.current === null) {
    mutationLogRef.current = createMutationLog();
  }

  const storeComics = useCallback((nextComics) => {
    comicsRef.current = nextComics;
    setComics(nextComics);
  }, []);

  const resetLibrary = useCallback(() => {
    requestIdRef.current += 1;
    mutationLogRef.current.reset();
    activeKeyRef.current = null;
    displayedKeyRef.current = null;
    storeComics([]);
    setError(null);
    setIsLoading(true);
    setIsRefreshing(false);
  }, [storeComics]);

  /**
   * Fetch a library list.
   *
   * Every call goes to the server, so an upload or an edit made elsewhere is
   * never missed. What changes is the wait: when the comics already on screen
   * answer this same request they stay put and the fetch happens behind them,
   * which is what stops a return from the reader clearing the cards.
   */
  const loadLibrary = useCallback(async ({ url = '/api/comics', fuzzyQuery = '' } = {}) => {
    const key = libraryRequestKey(url, fuzzyQuery);
    const showsThisList = displayedKeyRef.current === key;

    // Claim the request before awaiting so a response that has been superseded
    // by a newer one can be recognised and dropped.
    activeKeyRef.current = key;
    const requestId = requestIdRef.current + 1;
    requestIdRef.current = requestId;
    const isCurrent = () => activeKeyRef.current === key && requestIdRef.current === requestId;
    const mutationMark = mutationLogRef.current.beginLoad();

    if (showsThisList) {
      setIsRefreshing(true);
    } else {
      setIsLoading(true);
    }
    setError(null);

    try {
      const data = await api.get(url);
      if (!isCurrent()) {
        return comicsRef.current;
      }

      // Over the response, not under it: a comic deleted while this was in
      // flight must not come back, and a page saved in the reader must not be
      // rewound to whatever the server knew when it answered.
      const fetched = mutationLogRef.current.rebase(
        fuzzyFilter(normaliseComics(data.comics), fuzzyQuery, FUZZY_FIELDS),
        mutationMark
      );
      displayedKeyRef.current = key;
      storeComics(fetched);
      return fetched;
    } catch (err) {
      if (!isCurrent()) {
        return comicsRef.current;
      }

      logger.error('Failed to load comics:', err);
      const message = err.status === 429
        ? `Search rate limit exceeded. Please wait ${err.data?.retryAfter || 60} seconds before trying again.`
        : err.message || 'Could not load comics.';
      toast({
        title: err.status === 429 ? 'Rate limit exceeded' : 'Error',
        description: message,
        variant: 'destructive',
      });

      // A refresh that fails must not replace comics that are already on screen
      // with an error page; what is displayed is still the best answer there is.
      if (!showsThisList) {
        displayedKeyRef.current = null;
        storeComics([]);
        setError(message);
      }

      return comicsRef.current;
    } finally {
      mutationLogRef.current.endLoad();
      if (isCurrent()) {
        setIsLoading(false);
        setIsRefreshing(false);
      }
    }
  }, [storeComics, toast]);

  /**
   * Record a reading position the reader has just saved, so returning to the
   * library shows the new page immediately instead of after a round trip.
   */
  const updateComicProgress = useCallback((comicId, progress) => {
    const mutate = (comics) => applyProgressUpdate(comics, comicId, progress);
    mutationLogRef.current.record(mutate);

    const next = mutate(comicsRef.current);
    if (next !== comicsRef.current) {
      storeComics(next);
    }
  }, [storeComics]);

  const removeComicsFromLibrary = useCallback((comicIds) => {
    const mutate = (comics) => removeComics(comics, comicIds);
    mutationLogRef.current.record(mutate);

    const next = mutate(comicsRef.current);
    if (next !== comicsRef.current) {
      storeComics(next);
    }
  }, [storeComics]);

  // Drop one session's library on the way out, so it is not still in memory for
  // whoever logs in next.
  //
  // Logging out is the only moment worth doing this. Logging in cannot need it,
  // because reaching the login page means a logged-out state cleared the store
  // already — and clearing here on the way *in* would be unsafe: effects run
  // child-first, so it would fire after the dashboard had already asked for its
  // library and would strand it on an empty screen.
  const wasLoggedInRef = useRef(false);
  useEffect(() => {
    if (user) {
      wasLoggedInRef.current = true;
      return;
    }
    if (!wasLoggedInRef.current) return;

    wasLoggedInRef.current = false;
    resetLibrary();
  }, [user, resetLibrary]);

  const value = useMemo(() => ({
    comics,
    isLoading,
    isRefreshing,
    error,
    loadLibrary,
    updateComicProgress,
    removeComicsFromLibrary,
  }), [comics, isLoading, isRefreshing, error, loadLibrary, updateComicProgress, removeComicsFromLibrary]);

  return <ComicLibraryContext.Provider value={value}>{children}</ComicLibraryContext.Provider>;
}

export function useComicLibrary() {
  const context = useContext(ComicLibraryContext);
  if (context === undefined) {
    throw new Error('useComicLibrary must be used within a ComicLibraryProvider');
  }
  return context;
}
