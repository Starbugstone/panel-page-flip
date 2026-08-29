import { useState } from "react";
import { useToast } from "@/hooks/use-toast.js";

/**
 * The three things a card can be asked to do, and the confirmation each needs.
 *
 * Deleting can come back asking about an orphan — a library record whose file
 * has gone. That is a question rather than a failure, so the dialog stays open
 * and changes what it is asking.
 */
export function useComicCardActions({ comic, onResetProgress, onDeleteComic, onRemoveSharedComic }) {
  const { toast } = useToast();
  const [openDialog, setOpenDialog] = useState(null);
  const [isOrphaned, setIsOrphaned] = useState(false);

  const failed = (fallback) => (error) => {
    toast({ title: "Error", description: error.message || fallback, variant: "destructive" });
  };

  const close = () => {
    setOpenDialog(null);
    setIsOrphaned(false);
  };

  const confirmReset = async () => {
    try {
      await onResetProgress(comic.id);
      close();
      toast({
        title: "Reading progress reset",
        description: "Your reading progress has been reset successfully.",
      });
    } catch (error) {
      failed("Failed to reset reading progress")(error);
    }
  };

  const confirmDelete = async () => {
    try {
      await onDeleteComic(comic.id, { confirmOrphaned: isOrphaned });
      close();
      toast({
        title: "Comic deleted",
        description: isOrphaned
          ? "The orphaned comic record has been removed from your library."
          : "The comic has been removed from your library.",
      });
    } catch (error) {
      if (error.data?.code === "orphaned_comics_confirmation_required") {
        setIsOrphaned(true);
        return;
      }
      failed("Failed to delete comic")(error);
    }
  };

  const confirmRemoveShared = async () => {
    try {
      await onRemoveSharedComic(comic);
      close();
      toast({
        title: "Removed from your collection",
        description: "You can restore it from the Sharing page while it is still shared with you.",
      });
    } catch (error) {
      failed("Could not remove the comic from your collection")(error);
    }
  };

  return {
    openDialog,
    open: setOpenDialog,
    close,
    isOrphaned,
    confirmReset,
    confirmDelete,
    confirmRemoveShared,
  };
}
