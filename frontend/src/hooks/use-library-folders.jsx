import { useCallback, useEffect, useState } from "react";
import { api } from "@/lib/api";

export function useLibraryFolders() {
  const [folders, setFolders] = useState([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState(null);

  const refresh = useCallback(async () => {
    setIsLoading(true);
    try {
      const result = await api.get("/api/library/folders");
      setFolders(result.folders || []);
      setError(null);
      return result.folders || [];
    } catch (requestError) {
      setError(requestError.message || "Could not load folders");
      throw requestError;
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    let active = true;
    api.get("/api/library/folders").then((result) => {
      if (!active) return;
      setFolders(result.folders || []);
      setError(null);
    }).catch((requestError) => {
      if (active) setError(requestError.message || "Could not load folders");
    }).finally(() => {
      if (active) setIsLoading(false);
    });
    return () => { active = false; };
  }, []);

  const createFolder = useCallback(async (name, parentId = null) => {
    const result = await api.post("/api/library/folders", { name, parentId });
    setFolders((current) => [...current, result.folder]);
    return result.folder;
  }, []);

  const updateFolder = useCallback(async (id, changes) => {
    const result = await api.patch(`/api/library/folders/${id}`, changes);
    setFolders((current) => current.map((folder) => folder.id === id ? result.folder : folder));
    return result.folder;
  }, []);

  const deleteFolder = useCallback(async (id, confirm = false) => {
    const result = await api.delete(`/api/library/folders/${id}`, { body: { confirm } });
    await refresh();
    return result;
  }, [refresh]);

  const moveComics = useCallback(async (comicIds, folderId) => {
    return api.post("/api/library/folders/move-comics", { comicIds, folderId });
  }, []);

  return { folders, isLoading, error, refresh, createFolder, updateFolder, deleteFolder, moveComics };
}
