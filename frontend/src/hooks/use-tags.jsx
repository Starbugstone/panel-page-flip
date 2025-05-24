import { createContext, useContext, useState, useEffect, useCallback } from 'react';
import { useAuth } from './use-auth';
import { useToast } from './use-toast';

// Create the context
const TagContext = createContext(undefined);

export function TagProvider({ children }) {
  const [tags, setTags] = useState([]);
  const [isLoading, setIsLoading] = useState(false);
  const [lastFetched, setLastFetched] = useState(null);
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
    if (!force && tags.length > 0 && lastFetched && (Date.now() - lastFetched) < CACHE_TIME) {
      return tags;
    }

    setIsLoading(true);
    try {
      // Only pass adminContext when we're explicitly in the admin section
      const url = isAdminContext 
        ? '/api/tags?adminContext=true' 
        : '/api/tags';
      
      const response = await fetch(url);
      if (!response.ok) {
        throw new Error('Failed to fetch tags');
      }
      
      const data = await response.json();
      const fetchedTags = data.tags || [];
      
      setTags(fetchedTags);
      setLastFetched(Date.now());
      return fetchedTags;
    } catch (error) {
      console.error('Error fetching tags:', error);
      toast({
        title: 'Error',
        description: 'Failed to load tags. Some tag suggestions may not be available.',
        variant: 'destructive',
      });
      return [];
    } finally {
      setIsLoading(false);
    }
  }, [user, tags, lastFetched, toast]);

  // Function to search tags (using cache when possible)
  const searchTags = useCallback(async (query, isAdminContext = false) => {
    if (!query || query.trim().length < 2) {
      return [];
    }

    // Try to search locally first for immediate feedback
    const lowercaseQuery = query.toLowerCase().trim();
    const localResults = tags
      .filter(tag => tag.name.toLowerCase().includes(lowercaseQuery))
      .map(tag => ({ id: tag.id, name: tag.name }));
    
    // If we have local results and they were fetched recently, use them
    const CACHE_TIME = 5 * 60 * 1000; // 5 minutes
    if (localResults.length > 0 && lastFetched && (Date.now() - lastFetched) < CACHE_TIME) {
      return localResults;
    }

    // Otherwise, fetch from the server
    try {
      // Only pass adminContext when we're explicitly in the admin section
      const url = isAdminContext 
        ? `/api/tags/search?q=${encodeURIComponent(query.trim())}&adminContext=true` 
        : `/api/tags/search?q=${encodeURIComponent(query.trim())}`;
      
      const response = await fetch(url);
      if (!response.ok) {
        throw new Error('Failed to search tags');
      }
      
      const data = await response.json();
      return data.tags || [];
    } catch (error) {
      console.error('Error searching tags:', error);
      // Fall back to local results if API fails
      return localResults;
    }
  }, [tags, lastFetched]);

  // Function to add a tag to the local cache after creation
  const addTagToCache = useCallback((newTag) => {
    setTags(prevTags => {
      // Check if tag already exists
      if (prevTags.some(tag => tag.id === newTag.id)) {
        return prevTags;
      }
      return [...prevTags, newTag];
    });
  }, []);

  // Load tags on initial mount and when user changes
  useEffect(() => {
    if (user) {
      fetchTags();
    } else {
      setTags([]);
      setLastFetched(null);
    }
  }, [user, fetchTags]);

  // The context value
  const value = {
    tags,
    isLoading,
    fetchTags,
    searchTags,
    addTagToCache,
    lastFetched,
    // Helper function to determine if we're in admin context
    isAdminContext: () => {
      return window.location.pathname.startsWith('/admin');
    }
  };

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
