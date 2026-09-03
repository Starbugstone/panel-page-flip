import { createContext, useContext, useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { useAuth } from './use-auth';
import { useToast } from './use-toast';
import { api } from '@/lib/api';
import { logger } from '@/lib/logger';
import { fuzzyFilter } from '@/lib/fuzzy-search';

// Create the context
const TagContext = createContext(undefined);

// Stable identity so a signed-out render does not produce a new context value.
const EMPTY_TAGS = [];
const TAG_CACHE_TIME = 5 * 60 * 1000;

function tagCacheOwner(user) {
  if (!user) return null;
  if (user.id != null) return `id:${user.id}`;
  return user.email ? `email:${user.email}` : null;
}

const emptyCache = () => ({ owner: null, tags: EMPTY_TAGS, fetchedAt: null, adminContext: null });

/**
 * Whether the cache can answer for this account and section without asking the
 * server again. The admin context is part of the test: admin search sees every
 * account's tags, so a cache filled outside it would silently under-report.
 */
function isCacheFresh(cache, owner, isAdminContext) {
  return owner !== null
    && cache.owner === owner
    && cache.fetchedAt !== null
    && cache.adminContext === isAdminContext
    && (Date.now() - cache.fetchedAt) < TAG_CACHE_TIME;
}

/**
 * Tag search, answered from the cache when it can be.
 *
 * The local pass runs first so typing feels immediate, and a cache filled
 * recently in the same context is treated as the answer.
 */
function useTagSearch({ cacheRef, owner, ownerRef }) {
  return useCallback(async (query, isAdminContext = false) => {
    if (!query || query.trim().length < 2) {
      return [];
    }

    const cache = cacheRef.current;
    const ownedTags = owner !== null && cache.owner === owner ? cache.tags : EMPTY_TAGS;
    const localResults = fuzzyFilter(ownedTags, query, ['name']);

    if (isCacheFresh(cache, owner, isAdminContext)) {
      return localResults;
    }

    // Otherwise, fetch from the server
    try {
      // Only pass adminContext when we're explicitly in the admin section
      const url = isAdminContext
        ? `/api/tags/search?q=${encodeURIComponent(query.trim())}&adminContext=true`
        : `/api/tags/search?q=${encodeURIComponent(query.trim())}`;

      const data = await api.get(url);
      if (owner !== ownerRef.current) return [];
      return data.tags || [];
    } catch (error) {
      logger.error('Error searching tags:', error);
      if (owner !== ownerRef.current) return [];
      // Fall back to local results if API fails
      return localResults;
    }
  }, [cacheRef, owner, ownerRef]);
}

export function TagProvider({ children }) {
  // Rendered copy of the cache below, so a signed-out or newly signed-in render
  // never shows the last account's tags.
  const [visible, setVisible] = useState(emptyCache);
  const [isLoading, setIsLoading] = useState(false);
  const cacheRef = useRef(emptyCache());
  const inFlightRequestsRef = useRef(new Map());
  const mountedRef = useRef(true);
  const { user } = useAuth();
  const { toast } = useToast();
  const owner = tagCacheOwner(user);
  const ownerRef = useRef(owner);
  useEffect(() => { ownerRef.current = owner; }, [owner]);

  useEffect(() => {
    mountedRef.current = true;
    return () => { mountedRef.current = false; };
  }, []);

  const publishCache = useCallback((next) => {
    cacheRef.current = next;
    setVisible(next);
  }, []);

  const requestTags = useCallback((force = false, isAdminContext = false) => {
    if (owner === null) return Promise.resolve(EMPTY_TAGS);

    if (!force && isCacheFresh(cacheRef.current, owner, isAdminContext)) {
      return Promise.resolve(cacheRef.current.tags);
    }

    const existingRequest = inFlightRequestsRef.current.get(isAdminContext);
    if (existingRequest?.requestedFor === owner) return existingRequest.promise;

    const requestedFor = owner;
    const url = isAdminContext ? '/api/tags?adminContext=true' : '/api/tags';
    const promise = api.get(url).then((data) => {
      if (!mountedRef.current || requestedFor !== ownerRef.current) return EMPTY_TAGS;

      const fetchedTags = data.tags || [];
      publishCache({
        owner: requestedFor,
        tags: fetchedTags,
        fetchedAt: Date.now(),
        adminContext: isAdminContext,
      });
      return fetchedTags;
    }).finally(() => {
      if (inFlightRequestsRef.current.get(isAdminContext)?.promise === promise) {
        inFlightRequestsRef.current.delete(isAdminContext);
      }
    });

    inFlightRequestsRef.current.set(isAdminContext, { requestedFor, promise });
    return promise;
  }, [owner, publishCache]);

  // Function to fetch all tags
  const fetchTags = useCallback(async (force = false, isAdminContext = false) => {
    if (owner === null) return EMPTY_TAGS;

    setIsLoading(true);
    try {
      return await requestTags(force, isAdminContext);
    } catch (error) {
      logger.error('Error fetching tags:', error);
      // Only show toast for non-auth errors
      if (error.message !== 'Failed to fetch tags') {
        toast({
          title: 'Error',
          description: 'Failed to load tags. Some tag suggestions may not be available.',
          variant: 'destructive',
        });
      }
      return EMPTY_TAGS;
    } finally {
      setIsLoading(false);
    }
  }, [owner, requestTags, toast]);

  const searchTags = useTagSearch({ cacheRef, owner, ownerRef });

  /**
   * A tag just created here joins the cache without a round trip. A cache
   * belonging to nobody — or to the account before this one — is replaced
   * rather than added to, so the new tag is visible without carrying anything
   * that was never this account's.
   */
  const addTagToCache = useCallback((newTag) => {
    if (owner === null) return;

    const cache = cacheRef.current;
    const known = cache.owner === owner ? cache.tags : EMPTY_TAGS;
    if (known.some((tag) => tag.id === newTag.id)) return;

    publishCache({
      ...cache,
      owner,
      tags: [...known, newTag],
      // Still unfetched for this account: one known tag is not the list.
      fetchedAt: cache.owner === owner ? cache.fetchedAt : null,
    });
  }, [owner, publishCache]);

  // Prefetch on mount and when the user changes without showing an event-level
  // loading state. Consumers share this request through requestTags.
  useEffect(() => {
    if (owner === null) return;

    const path = window.location.pathname;
    if (!(path.startsWith('/dashboard') || path.startsWith('/admin') || path.startsWith('/upload'))) {
      return;
    }

    void requestTags()
      .catch((error) => {
        logger.error('Error fetching tags:', error);
      });
  }, [owner, requestTags]);

  // Logging out invalidates the cache. The rendered copy is left alone because
  // it is already inert: every read of it is gated on the cache belonging to
  // the account currently signed in.
  useEffect(() => {
    if (owner === null) cacheRef.current = emptyCache();
  }, [owner]);

  // The context value
  const isAdminContext = useCallback(() => window.location.pathname.startsWith('/admin'), []);

  // Signed out means no tags, whatever the last account left behind.
  const hasCurrentAccountCache = owner !== null && visible.owner === owner;
  const visibleTags = hasCurrentAccountCache ? visible.tags : EMPTY_TAGS;
  const visibleLastFetched = hasCurrentAccountCache ? visible.fetchedAt : null;

  const value = useMemo(() => ({
    tags: visibleTags,
    isLoading,
    fetchTags,
    requestTags,
    searchTags,
    addTagToCache,
    lastFetched: visibleLastFetched,
    // Helper function to determine if we're in admin context
    isAdminContext,
  }), [addTagToCache, fetchTags, isAdminContext, isLoading, requestTags, visibleLastFetched, searchTags, visibleTags]);

  return <TagContext.Provider value={value}>{children}</TagContext.Provider>;
}

// Hook to use the tag context
export function useTags() {
  const context = useContext(TagContext);
  if (context === undefined) {
    throw new Error('useTags must be used within a TagProvider');
  }
  return context;
}
