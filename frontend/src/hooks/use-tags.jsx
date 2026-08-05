import { createContext, useContext, useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { useAuth } from './use-auth';
import { useToast } from './use-toast';
import { api } from '@/lib/api';
import { logger } from '@/lib/logger';
import { fuzzyFilter } from '@/lib/fuzzy-search';

// Create the context
const TagContext = createContext(undefined);

export function TagProvider({ children }) {
  const [tags, setTags] = useState([]);
  const [isLoading, setIsLoading] = useState(false);
  const [lastFetched, setLastFetched] = useState(null);
  const tagsRef = useRef([]);
  const lastFetchedRef = useRef(null);
  const lastFetchedAdminContextRef = useRef(null);
  const { user } = useAuth();
  const { toast } = useToast();

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

    setIsLoading(true);
    try {
      // Only pass adminContext when we're explicitly in the admin section
      const url = isAdminContext 
        ? '/api/tags?adminContext=true' 
        : '/api/tags';
      
      const data = await api.get(url);
      const fetchedTags = data.tags || [];
      
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

  // Load tags on initial mount and when user changes
  useEffect(() => {
    if (user) {
      // Only fetch tags on specific pages where they're needed
      const path = window.location.pathname;
      if (path.startsWith('/dashboard') || path.startsWith('/admin') || path.startsWith('/upload')) {
        fetchTags();
      }
    } else {
      tagsRef.current = [];
      lastFetchedRef.current = null;
      lastFetchedAdminContextRef.current = null;
      setTags([]);
      setLastFetched(null);
    }
  }, [user, fetchTags]);

  // The context value
  const isAdminContext = useCallback(() => window.location.pathname.startsWith('/admin'), []);

  const value = useMemo(() => ({
    tags,
    isLoading,
    fetchTags,
    searchTags,
    addTagToCache,
    lastFetched,
    // Helper function to determine if we're in admin context
    isAdminContext,
  }), [addTagToCache, fetchTags, isAdminContext, isLoading, lastFetched, searchTags, tags]);

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
