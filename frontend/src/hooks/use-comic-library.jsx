import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { useAuth } from './use-auth';
import { useToast } from './use-toast';
import { api } from '@/lib/api';
import { logger } from '@/lib/logger';
import { fuzzyFilter } from '@/lib/fuzzy-search';
import {
  applyProgressUpdate,
  isLibraryStale,
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
  const [isLoading, setIsLoading] = useState(false);
  const [isRefreshing, setIsRefreshing] = useState(false);
  // False until a load has finished, so the dashboard shows its skeleton on the
  // very first paint rather than flashing "no comics in your library yet".
  const [hasLoaded, setHasLoaded] = useState(false);
  const [error, setError] = useState(null);
  const { user } = useAuth();
  const { toast } = useToast();

  // Mirrors of the state that loadLibrary reads, so the callback can stay
  // stable and not restart the effect that calls it on every render.
  const comicsRef = useRef([]);
  const requestKeyRef = useRef(null);
  const fetchedAtRef = useRef(null);

  const storeComics = useCallback((nextComics) => {
    comicsRef.current = nextComics;
    setComics(nextComics);
  }, []);

  const resetLibrary = useCallback(() => {
    requestKeyRef.current = null;
    fetchedAtRef.current = null;
    storeComics([]);
    setError(null);
    setIsLoading(false);
    setIsRefreshing(false);
    setHasLoaded(false);
  }, [storeComics]);

  /**
   * Fetch a library list, showing whatever is already cached for the same
   * request in the meantime.
   *
   * Returns the comics that ended up displayed.
   */
  const loadLibrary = useCallback(async ({ url = '/api/comics', fuzzyQuery = '', force = false } = {}) => {
    const key = libraryRequestKey(url, fuzzyQuery);
    const hasCached = requestKeyRef.current === key && fetchedAtRef.current !== null;

    if (hasCached && !force && !isLibraryStale(fetchedAtRef.current)) {
      return comicsRef.current;
    }

    // Claim the request before awaiting so a response that has been superseded
    // by a newer search can be recognised and dropped.
    requestKeyRef.current = key;

    if (hasCached) {
      // Cards stay on screen; only the quiet refresh indicator changes.
      setIsRefreshing(true);
    } else {
      storeComics([]);
      setIsLoading(true);
    }
    setError(null);

    try {
      const data = await api.get(url);
      if (requestKeyRef.current !== key) {
        return comicsRef.current;
      }

      const fetched = fuzzyFilter(normaliseComics(data.comics), fuzzyQuery, FUZZY_FIELDS);
      fetchedAtRef.current = Date.now();
      storeComics(fetched);
      return fetched;
    } catch (err) {
      if (requestKeyRef.current !== key) {
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

      // A failed background refresh must not replace comics that are already on
      // screen with an error screen; the cached list is still the best answer.
      if (!hasCached) {
        fetchedAtRef.current = null;
        storeComics([]);
        setError(message);
      }

      return comicsRef.current;
    } finally {
      if (requestKeyRef.current === key) {
        setIsLoading(false);
        setIsRefreshing(false);
        setHasLoaded(true);
      }
    }
  }, [storeComics, toast]);

  /**
   * Record a reading position the reader has just saved, so returning to the
   * library shows the new page immediately instead of after a round trip.
   */
  const updateComicProgress = useCallback((comicId, progress) => {
    const next = applyProgressUpdate(comicsRef.current, comicId, progress);
    if (next !== comicsRef.current) {
      storeComics(next);
    }
  }, [storeComics]);

  const removeComicsFromLibrary = useCallback((comicIds) => {
    const next = removeComics(comicsRef.current, comicIds);
    if (next !== comicsRef.current) {
      storeComics(next);
    }
  }, [storeComics]);

  // One user's library must never be shown to the next one.
  const lastUserIdRef = useRef(null);
  useEffect(() => {
    const userId = user?.id ?? null;
    if (lastUserIdRef.current === userId) return;

    lastUserIdRef.current = userId;
    resetLibrary();
  }, [user, resetLibrary]);

  const value = useMemo(() => ({
    comics,
    isLoading,
    isRefreshing,
    hasLoaded,
    error,
    loadLibrary,
    updateComicProgress,
    removeComicsFromLibrary,
    resetLibrary,
  }), [comics, isLoading, isRefreshing, hasLoaded, error, loadLibrary, updateComicProgress, removeComicsFromLibrary, resetLibrary]);

  return <ComicLibraryContext.Provider value={value}>{children}</ComicLibraryContext.Provider>;
}

export function useComicLibrary() {
  const context = useContext(ComicLibraryContext);
  if (context === undefined) {
    throw new Error('useComicLibrary must be used within a ComicLibraryProvider');
  }
  return context;
}
