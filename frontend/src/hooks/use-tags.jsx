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

/**
 * Tag search, answered from the cache when it can be.
 *
 * The local pass runs first so typing feels immediate, and a cache filled
 * recently in the same context is treated as the answer. The admin context is
 * part of that test: admin search sees every account's tags, so a cache filled
 * outside it would silently under-report.
 */
function useTagSearch({ tagsRef, lastFetchedRef, lastFetchedAdminContextRef, cacheOwnerRef, owner, ownerRef }) {
  return useCallback(async (query, isAdminContext = false) => {
    if (!query || query.trim().length < 2) {
      return [];
    }

    const hasCurrentAccountCache = owner !== null && cacheOwnerRef.current === owner;
    const localResults = fuzzyFilter(hasCurrentAccountCache ? tagsRef.current : EMPTY_TAGS, query, ['name']);

    // If we have local results and they were fetched recently, use them
    if (
      hasCurrentAccountCache
      && lastFetchedRef.current
      && lastFetchedAdminContextRef.current === isAdminContext
      && (Date.now() - lastFetchedRef.current) < TAG_CACHE_TIME
    ) {
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
  }, [cacheOwnerRef, lastFetchedAdminContextRef, lastFetchedRef, owner, ownerRef, tagsRef]);
}

export function TagProvider({ children }) {
  const [tags, setTags] = useState([]);
  const [isLoading, setIsLoading] = useState(false);
  const [lastFetched, setLastFetched] = useState(null);
  const [cacheOwner, setCacheOwner] = useState(null);
  const tagsRef = useRef([]);
  const lastFetchedRef = useRef(null);
  const lastFetchedAdminContextRef = useRef(null);
  const cacheOwnerRef = useRef(null);
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

  const requestTags = useCallback((force = false, isAdminContext = false) => {
    if (owner === null) return Promise.resolve([]);

    if (
      !force
      && cacheOwnerRef.current === owner
      && lastFetchedRef.current !== null
      && lastFetchedAdminContextRef.current === isAdminContext
      && (Date.now() - lastFetchedRef.current) < TAG_CACHE_TIME
    ) {
      return Promise.resolve(tagsRef.current);
    }

    const existingRequest = inFlightRequestsRef.current.get(isAdminContext);
    if (existingRequest?.requestedFor === owner) return existingRequest.promise;

    const requestedFor = owner;
    const url = isAdminContext ? '/api/tags?adminContext=true' : '/api/tags';
    const promise = api.get(url).then((data) => {
      if (!mountedRef.current || requestedFor !== ownerRef.current) return [];

      const fetchedTags = data.tags || [];
      const fetchedAt = Date.now();
      tagsRef.current = fetchedTags;
      lastFetchedRef.current = fetchedAt;
      lastFetchedAdminContextRef.current = isAdminContext;
      cacheOwnerRef.current = requestedFor;
      setTags(fetchedTags);
      setLastFetched(fetchedAt);
      setCacheOwner(requestedFor);
      return fetchedTags;
    }).finally(() => {
      if (inFlightRequestsRef.current.get(isAdminContext)?.promise === promise) {
        inFlightRequestsRef.current.delete(isAdminContext);
      }
    });

    inFlightRequestsRef.current.set(isAdminContext, { requestedFor, promise });
    return promise;
  }, [owner]);

  // Function to fetch all tags
  const fetchTags = useCallback(async (force = false, isAdminContext = false) => {
    if (owner === null) return [];

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
      return [];
    } finally {
      setIsLoading(false);
    }
  }, [owner, requestTags, toast]);

  const searchTags = useTagSearch({
    tagsRef,
    lastFetchedRef,
    lastFetchedAdminContextRef,
    cacheOwnerRef,
    owner,
    ownerRef,
  });

  // Function to add a tag to the local cache after creation
  const addTagToCache = useCallback((newTag) => {
    setTags(prevTags => {
      // Check if tag already exists
      if (prevTags.some(tag => tag.id === newTag.id)) {
        return prevTags;
      }
      const updatedTags = [...prevTags, newTag];
      tagsRef.current = updatedTags;
      return updatedTags;
    });
  }, []);

  // Prefetch on mount and when the user changes without showing an event-level
  // loading state. Consumers share this request through requestTags.
  useEffect(() => {
    if (owner === null) return undefined;

    const path = window.location.pathname;
    if (!(path.startsWith('/dashboard') || path.startsWith('/admin') || path.startsWith('/upload'))) {
      return undefined;
    }

    void requestTags()
      .catch((error) => {
        logger.error('Error fetching tags:', error);
      });
    return undefined;
  }, [owner, requestTags]);

  // Logging out invalidates the request cache. State from the last account is
  // retained only as inert data and is hidden by the owner check below.
  useEffect(() => {
    if (owner !== null) return;
    tagsRef.current = [];
    lastFetchedRef.current = null;
    lastFetchedAdminContextRef.current = null;
    cacheOwnerRef.current = null;
  }, [owner]);

  // The context value
  const isAdminContext = useCallback(() => window.location.pathname.startsWith('/admin'), []);

  // Signed out means no tags, whatever the last account left behind.
  const hasCurrentAccountCache = owner !== null && cacheOwner === owner;
  const visibleTags = hasCurrentAccountCache ? tags : EMPTY_TAGS;
  const visibleLastFetched = hasCurrentAccountCache ? lastFetched : null;

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
