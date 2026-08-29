import { useEffect } from "react";

import { isTypingTarget } from "@/lib/keyboard";

/**
 * The keys the reader claims, and the ones it deliberately leaves alone.
 *
 * Escape only while there is a zoom to leave and nothing else is claiming it:
 * it closes the settings sheet first, because taking the zoom off at the same
 * time would be two things happening for one press. Leaving fullscreen resets
 * the transform anyway, so those two cannot disagree.
 */
export function useReaderKeyboard({ isZoomed, isSettingsOpen, pageCount, zoomToFit, onPrevious, onNext, goToPage }) {
  useEffect(() => {
    const handleKeyPress = (event) => {
      if (isTypingTarget(event.target)) return;

      if (event.key === "Escape" && isZoomed && !isSettingsOpen) {
        event.preventDefault();
        zoomToFit();
        return;
      }

      if (["ArrowLeft", "ArrowRight", "Home", "End"].includes(event.key)) event.preventDefault();
      if (event.key === "ArrowLeft") onPrevious();
      if (event.key === "ArrowRight") onNext();
      if (event.key === "Home") goToPage(0);
      if (event.key === "End") goToPage(pageCount - 1);
    };

    window.addEventListener("keydown", handleKeyPress);
    return () => window.removeEventListener("keydown", handleKeyPress);
  }, [goToPage, isSettingsOpen, isZoomed, onNext, onPrevious, pageCount, zoomToFit]);
}
