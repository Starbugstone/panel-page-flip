import { useEffect, useState } from "react";

/**
 * Whether the reader is filling the screen, and the transforms that must not
 * outlive the layout they were set up against.
 *
 * A zoom is a position measured in the old geometry, so leaving fullscreen,
 * turning the device and changing reading mode each drop it rather than leaving
 * the page parked somewhere it no longer makes sense.
 */
export function useReaderFullscreen({ resetTransform, orientation, effectiveMode }) {
  const [isFullscreen, setIsFullscreen] = useState(false);

  useEffect(() => {
    const handleFullscreenChange = () => {
      const next = Boolean(document.fullscreenElement);
      setIsFullscreen(next);
      if (!next) resetTransform();
    };
    document.addEventListener("fullscreenchange", handleFullscreenChange);
    return () => document.removeEventListener("fullscreenchange", handleFullscreenChange);
  }, [resetTransform]);

  useEffect(() => { resetTransform(); }, [orientation, resetTransform]);
  useEffect(() => resetTransform(), [effectiveMode, resetTransform]);

  return isFullscreen;
}
