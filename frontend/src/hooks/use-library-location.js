import { useCallback, useEffect } from "react";
import { useSearchParams } from "react-router-dom";
import { resolveLibraryLocation } from "@/lib/library-view";

/**
 * Where the library is pointing, and the only two ways to move it.
 *
 * Navigating always replaces the whole query string rather than merging into
 * it: a folder and a view are alternative ways of choosing comics, and a URL
 * carrying both would have to pick one anyway.
 *
 * A folder the URL names but the tree does not have is dropped back to the
 * root, on a timer rather than during render, because navigating is a state
 * change and React will not accept one mid-render.
 */
export function useLibraryLocation({ folders, foldersLoading, onNavigate }) {
  const [searchParams, setSearchParams] = useSearchParams();
  const location = resolveLibraryLocation(searchParams, folders, foldersLoading);

  const navigateFolder = useCallback((folderId) => {
    const next = new URLSearchParams();
    next.set("folder", folderId == null ? "root" : String(folderId));
    setSearchParams(next);
    onNavigate?.();
  }, [onNavigate, setSearchParams]);

  const navigateView = useCallback((view) => {
    const next = new URLSearchParams();
    if (view !== "all") next.set("view", view);
    setSearchParams(next);
    onNavigate?.();
  }, [onNavigate, setSearchParams]);

  const { invalidFolder } = location;
  useEffect(() => {
    if (foldersLoading || !invalidFolder) return undefined;
    const timer = window.setTimeout(() => navigateFolder(null), 0);
    return () => window.clearTimeout(timer);
  }, [foldersLoading, invalidFolder, navigateFolder]);

  return { ...location, navigateFolder, navigateView };
}
