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
  // A mouse moving across the page is a reader who is still there, and there
  // are hundreds of those events a second. Keeping the last of them in a ref
  // means staying is free; only appearing and disappearing costs a render.
  const lastActivityRef = useRef(0);

  const reveal = useCallback(() => {
    lastActivityRef.current = Date.now();
    setVisible(true);
  }, []);

  const toggle = useCallback(() => {
    lastActivityRef.current = Date.now();
    setVisible((shown) => !shown);
  }, []);

  useEffect(() => {
    // Pinned covers both the preference to keep controls up and the moment a
    // control has focus or a popover open. Hiding then would take the thing
    // under the user's finger away mid-use.
    if (!visible || !enabled || pinned) return undefined;

    let timer = null;
    const check = () => {
      const idleFor = Date.now() - lastActivityRef.current;
      if (idleFor >= idleMs) {
        setVisible(false);
        return;
      }
      // Something happened while this was waiting; give it the rest of its time
      // rather than hiding on the schedule the last render set.
      timer = setTimeout(check, idleMs - idleFor);
    };

    timer = setTimeout(check, idleMs);
    return () => clearTimeout(timer);
  }, [visible, enabled, pinned, idleMs]);

  // Focus arriving from the keyboard has to bring the controls back with it,
  // or Tab walks into a control nobody can see.
  useEffect(() => {
    if (!enabled) return undefined;

    // reveal(), not a bare setVisible: somebody paging through a comic on the
    // keyboard is using the reader, and controls that hid under them on the
    // schedule set before the first keypress would be worse than never showing.
    window.addEventListener("keydown", reveal);
    window.addEventListener("focusin", reveal);

    return () => {
      window.removeEventListener("keydown", reveal);
      window.removeEventListener("focusin", reveal);
    };
  }, [enabled, reveal]);

  // Turning auto-hide off is an instruction, not just a future policy, and
  // turning it back on should not start from controls that are already gone.
  const [wasEnabled, setWasEnabled] = useState(enabled);
  if (wasEnabled !== enabled) {
    setWasEnabled(enabled);
    setVisible(true);
  }

  return { chromeVisible: visible || !enabled, revealChrome: reveal, toggleChrome: toggle };
}
