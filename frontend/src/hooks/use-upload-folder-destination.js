import { useState } from "react";
import { useSearchParams } from "react-router-dom";

const NUMERIC_FOLDER_ID = /^\d+$/;

export function parseUploadFolderId(value) {
  return value && NUMERIC_FOLDER_ID.test(value) ? Number(value) : null;
}

export function resolveUploadFolderId(folderId, folders, foldersLoading) {
  if (folderId == null) return null;
  if (foldersLoading) return folderId;

  return folders.some((folder) => Number(folder.id) === folderId) ? folderId : null;
}

/** Owns the folder query parameter and validates it once folder data arrives. */
export function useUploadFolderDestination(folders, foldersLoading) {
  const [searchParams] = useSearchParams();
  const [folderId, setFolderId] = useState(() => parseUploadFolderId(searchParams.get("folder")));
  const selectedFolderId = resolveUploadFolderId(folderId, folders, foldersLoading);

  return { selectedFolderId, setFolderId };
}
