import { useCallback, useEffect, useRef, useState } from "react";

// Long enough to read a page number and reach for a control, short enough that
// the artwork gets the screen back without being asked.
const IDLE_MS = 3000;

/**
 * When the reader's controls are on screen.
 *
 * Artwork should have nearly the whole display, which means the controls have
 * to leave on their own — but never while somebody is using them, and never in
 * a way that strands keyboard focus on something invisible.
 */
export function useReaderChrome({ enabled = true, pinned = false, idleMs = IDLE_MS } = {}) {
  const [visible, setVisible] = useState(true);
  const timerRef = useRef(null);

  const reveal = useCallback(() => setVisible(true), []);
  const toggle = useCallback(() => setVisible((shown) => !shown), []);

  useEffect(() => {
    clearTimeout(timerRef.current);
    // Pinned covers both the preference to keep controls up and the moment a
    // control has focus or a popover open. Hiding then would take the thing
    // under the user's finger away mid-use.
    if (!visible || !enabled || pinned) return undefined;

    timerRef.current = setTimeout(() => setVisible(false), idleMs);
    return () => clearTimeout(timerRef.current);
  }, [visible, enabled, pinned, idleMs]);

  // Focus arriving from the keyboard has to bring the controls back with it,
  // or Tab walks into a control nobody can see.
  useEffect(() => {
    if (!enabled) return undefined;

    const wake = () => setVisible(true);
    window.addEventListener("keydown", wake);
    window.addEventListener("focusin", wake);

    return () => {
      window.removeEventListener("keydown", wake);
      window.removeEventListener("focusin", wake);
    };
  }, [enabled]);

  // Turning auto-hide off is an instruction, not just a future policy: the
  // controls that are currently faded out have to come back.
  useEffect(() => {
    if (!enabled) setVisible(true);
  }, [enabled]);

  return { chromeVisible: visible || !enabled, revealChrome: reveal, toggleChrome: toggle };
}
