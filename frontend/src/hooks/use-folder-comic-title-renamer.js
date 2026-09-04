import { useEffect, useMemo, useRef, useState } from "react";
import { useToast } from "@/hooks/use-toast.js";
import { api } from "@/lib/api";
import {
  applyComicTitleRenamePreview,
  planComicTitleRenames,
} from "@/lib/comic-title-renaming";

const MAX_FOLDER_TITLE_RENAMES = 5000;

const renamePayload = (renames, reverse = false) => ({
  updates: renames.map((rename) => ({
    id: rename.id,
    currentTitle: reverse ? rename.title : rename.originalTitle,
    title: reverse ? rename.originalTitle : rename.title,
  })),
});

/** A folder-scoped preview, its atomic save, and the one-session undo. */
export function useFolderComicTitleRenamer({ locationKey, comics, refreshCurrent }) {
  const { toast } = useToast();
  const [storedSession, setStoredSession] = useState(null);
  const lastLocationKey = useRef(locationKey);
  const session = storedSession?.locationKey === locationKey ? storedSession : null;

  useEffect(() => {
    if (lastLocationKey.current === locationKey) return undefined;

    lastLocationKey.current = locationKey;
    let cancelled = false;
    queueMicrotask(() => {
      if (!cancelled) setStoredSession(null);
    });

    return () => {
      cancelled = true;
    };
  }, [locationKey]);

  const startPreview = () => {
    const renames = planComicTitleRenames(comics);
    if (renames.length === 0) {
      toast({
        title: "Nothing to rename",
        description: "No editable numbered series in this folder needs additional zeroes.",
      });
      return;
    }
    if (renames.length > MAX_FOLDER_TITLE_RENAMES) {
      toast({
        title: "Too many titles to rename at once",
        description: `A single rename can change up to ${MAX_FOLDER_TITLE_RENAMES.toLocaleString()} comic titles.`,
        variant: "destructive",
      });
      return;
    }

    setStoredSession({ locationKey, phase: "preview", count: renames.length, renames, isSaving: false });
  };

  const accept = async () => {
    if (!session || session.phase !== "preview" || session.isSaving) return;
    setStoredSession({ ...session, isSaving: true });
    try {
      await api.patch("/api/comics/titles", renamePayload(session.renames));
      setStoredSession({ ...session, phase: "accepted", isSaving: false });
      await refreshCurrent();
      toast({
        title: "Comics renamed",
        description: `${session.count} comic title${session.count === 1 ? "" : "s"} updated. You can still undo this while you remain in the folder.`,
      });
    } catch (requestError) {
      setStoredSession({ ...session, isSaving: false });
      toast({
        title: "Rename failed",
        description: requestError.message || "Could not rename the comics.",
        variant: "destructive",
      });
    }
  };

  const undo = async () => {
    if (!session || session.isSaving) return;
    if (session.phase === "preview") {
      setStoredSession(null);
      return;
    }

    setStoredSession({ ...session, isSaving: true });
    try {
      await api.patch("/api/comics/titles", renamePayload(session.renames, true));
      await refreshCurrent();
      setStoredSession(null);
      toast({ title: "Rename undone", description: "The original comic titles were restored." });
    } catch (requestError) {
      setStoredSession({ ...session, isSaving: false });
      toast({
        title: "Undo failed",
        description: requestError.message || "Could not restore the original titles.",
        variant: "destructive",
      });
    }
  };

  const previewComics = useMemo(
    () => session
      ? applyComicTitleRenamePreview(comics, session.renames)
      : comics,
    [comics, session],
  );

  return { session, previewComics, startPreview, accept, undo };
}
