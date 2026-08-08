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

export function TagProvider({ children }) {
  const [tags, setTags] = useState([]);
  const [isLoading, setIsLoading] = useState(false);
  const [lastFetched, setLastFetched] = useState(null);
  const tagsRef = useRef([]);
  const lastFetchedRef = useRef(null);
  const lastFetchedAdminContextRef = useRef(null);
  const { user } = useAuth();
  const { toast } = useToast();
  // Who is signed in *now*, readable from an async callback that closed over an
  // earlier value. Written after commit rather than during render, which is
  // what a ref is allowed to do.
  const userRef = useRef(user);
  useEffect(() => { userRef.current = user; }, [user]);

  // Function to fetch all tags
  const fetchTags = useCallback(async (force = false, isAdminContext = false) => {
    // Skip if not logged in
    if (!user) {
      return [];
    }

    // If we have tags and they were fetched recently (within 5 minutes), use cached version
    // unless force refresh is requested
    const CACHE_TIME = 5 * 60 * 1000; // 5 minutes in milliseconds
    if (
      !force
      && tagsRef.current.length > 0
      && lastFetchedRef.current
      && lastFetchedAdminContextRef.current === isAdminContext
      && (Date.now() - lastFetchedRef.current) < CACHE_TIME
    ) {
      return tagsRef.current;
    }

    // Only the session that asked may answer. Without this a slow response for
    // the previous account lands after another user has signed in and shows
    // them tags that were never theirs.
    const requestedFor = user;
    setIsLoading(true);
    try {
      // Only pass adminContext when we're explicitly in the admin section
      const url = isAdminContext 
        ? '/api/tags?adminContext=true' 
        : '/api/tags';
      
      const data = await api.get(url);
      const fetchedTags = data.tags || [];
      
      if (requestedFor !== userRef.current) return [];

      const fetchedAt = Date.now();
      tagsRef.current = fetchedTags;
      lastFetchedRef.current = fetchedAt;
      lastFetchedAdminContextRef.current = isAdminContext;
      setTags(fetchedTags);
      setLastFetched(fetchedAt);
      return fetchedTags;
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
  }, [toast, user]);

  // Function to search tags (using cache when possible)
  const searchTags = useCallback(async (query, isAdminContext = false) => {
    if (!query || query.trim().length < 2) {
      return [];
    }

    // Try to search locally first for immediate feedback
    const localResults = fuzzyFilter(tagsRef.current, query, ['name']);
    
    // If we have local results and they were fetched recently, use them
    const CACHE_TIME = 5 * 60 * 1000; // 5 minutes
    if (
      lastFetchedRef.current
      && lastFetchedAdminContextRef.current === isAdminContext
      && (Date.now() - lastFetchedRef.current) < CACHE_TIME
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
      return data.tags || [];
    } catch (error) {
      logger.error('Error searching tags:', error);
      // Fall back to local results if API fails
      return localResults;
    }
  }, []);

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

  // Prefetch on mount and when the user changes.
  //
  // fetchTags is the right thing for a consumer to call from an event handler,
  // but not from here: it flips isLoading synchronously, so mounting the
  // provider rendered twice before a request had even left. This path issues
  // the request directly and applies the result once it arrives, and ignores a
  // response that lands after the account changed.
  useEffect(() => {
    if (!user) return undefined;

    const path = window.location.pathname;
    if (!(path.startsWith('/dashboard') || path.startsWith('/admin') || path.startsWith('/upload'))) {
      return undefined;
    }

    let ignore = false;
    api.get('/api/tags')
      .then((data) => {
        if (ignore) return;
        const fetchedTags = data.tags || [];
        const fetchedAt = Date.now();
        tagsRef.current = fetchedTags;
        lastFetchedRef.current = fetchedAt;
        lastFetchedAdminContextRef.current = false;
        setTags(fetchedTags);
        setLastFetched(fetchedAt);
      })
      .catch((error) => {
        logger.error('Error fetching tags:', error);
      });

    return () => { ignore = true; };
  }, [user]);

  // Logging out empties the cache. The refs are the cache itself, so they are
  // cleared here; what is handed to consumers is derived below rather than
  // being a third copy that has to be set back to empty in step.
  useEffect(() => {
    if (user) return;
    tagsRef.current = [];
    lastFetchedRef.current = null;
    lastFetchedAdminContextRef.current = null;
  }, [user]);

  // The context value
  const isAdminContext = useCallback(() => window.location.pathname.startsWith('/admin'), []);

  // Signed out means no tags, whatever the last account left behind.
  const visibleTags = user ? tags : EMPTY_TAGS;
  const visibleLastFetched = user ? lastFetched : null;

  const value = useMemo(() => ({
    tags: visibleTags,
    isLoading,
    fetchTags,
    searchTags,
    addTagToCache,
    lastFetched: visibleLastFetched,
    // Helper function to determine if we're in admin context
    isAdminContext,
  }), [addTagToCache, fetchTags, isAdminContext, isLoading, visibleLastFetched, searchTags, visibleTags]);

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
