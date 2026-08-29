import { useEffect } from "react";

import { useReaderChrome } from "@/hooks/use-reader-chrome";
import { useReaderControlsHeight } from "@/hooks/use-reader-controls-height";

/**
 * Whether the reader's controls are on screen, and how much room they take.
 *
 * Auto-hide only applies where there is no hover to bring them back — a
 * touchscreen, or fullscreen — because on a desktop the controls fading out
 * would be a disappearance with nothing to explain it. Turning a device is a
 * deliberate act, so it brings them back rather than leaving somebody to guess
 * where they went.
 */
export function useReaderChromeState({ settings, profile, isFullscreen, isSettingsOpen, isControlFocused }) {
  const { height: controlsHeight, controlsRef } = useReaderControlsHeight();
  const autoHide = settings.autoHideControls && (isFullscreen || profile.touchCapable);
  const { chromeVisible, revealChrome, toggleChrome } = useReaderChrome({
    enabled: autoHide,
    pinned: isSettingsOpen || isControlFocused,
  });

  useEffect(() => revealChrome(), [profile.orientation, revealChrome]);

  return { controlsHeight, controlsRef, revealChrome, toggleChrome, isChromeHidden: autoHide && !chromeVisible };
}
