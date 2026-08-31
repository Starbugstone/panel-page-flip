import { useCallback, useEffect, useRef, useState } from "react";

import {
  COVER_MAX_ATTEMPTS,
  coverRetryDelay,
  coverSlots,
  coverUrlForAttempt,
} from "@/lib/cover-loading";

/**
 * Far enough ahead of the scroll that a cover is usually there by the time it
 * is looked at, close enough that scrolling past a long library does not
 * request the whole thing.
 */
const VISIBILITY_MARGIN = "400px";

const UNREQUESTED = { attempt: 0, granted: -1, loaded: false, failed: false };

/**
 * One comic cover: when to ask the server for it, and what to do when the
 * answer is broken.
 *
 * `loading="lazy"` is not enough on its own. It defers an off-screen image but
 * says nothing about how many may start at once, so a fast scroll through a
 * large library makes every cover eligible within a frame or two and the
 * browser opens all of them. The server refuses what it cannot serve, the
 * browser renders that as a broken image, and — this is the part that matters —
 * nothing ever asks again. A permanent hole in the grid caused by a moment of
 * load.
 *
 * So the caller, not the browser, decides when the `src` is set: a card asks
 * for a slot when it comes on screen, and gets one when the library has fewer
 * than {@link COVER_REQUEST_LIMIT} requests outstanding. A failure is retried
 * on a jittered backoff, and only while the card is still on screen — a retry
 * for something nobody is looking at is the original problem wearing a hat.
 */
export function useCoverImage(url, { eager = false, slots = coverSlots, maxAttempts = COVER_MAX_ATTEMPTS } = {}) {
  // An eager cover is one of the few above the fold; it has no scrolling to
  // wait for. Where there is no observer at all, everything counts as on screen
  // rather than nothing — a library that shows no covers is the worse failure.
  const [isVisible, setVisible] = useState(() => eager || typeof IntersectionObserver !== "function");
  const [tracked, setTracked] = useState(() => ({ url, ...UNREQUESTED }));

  const ticketRef = useRef(null);
  const retryTimerRef = useRef(null);

  // A new cover URL for the same card — a regenerated cover, a recycled row —
  // starts over. Derived rather than reset from an effect, so the first render
  // after the change already describes the new cover instead of briefly
  // claiming the old one is loaded.
  const state = tracked.url === url ? tracked : { url, ...UNREQUESTED };

  const update = useCallback((changes) => {
    setTracked((previous) => {
      const base = previous.url === url ? previous : { url, ...UNREQUESTED };

      return { ...base, ...(typeof changes === "function" ? changes(base) : changes), url };
    });
  }, [url]);

  const releaseTicket = useCallback(() => {
    ticketRef.current?.release();
    ticketRef.current = null;
  }, []);

  // A callback ref rather than a ref object: React 19 runs the returned
  // cleanup when the node goes away, and a hook that handed its ref object back
  // to the caller would be handing out something only it should be reading.
  const watchVisibility = useCallback((node) => {
    if (!node || typeof IntersectionObserver !== "function") return undefined;

    const observer = new IntersectionObserver(
      ([entry]) => setVisible(entry.isIntersecting),
      { rootMargin: VISIBILITY_MARGIN }
    );
    observer.observe(node);

    return () => observer.disconnect();
  }, []);

  const { attempt, granted, loaded, failed } = state;

  useEffect(() => {
    if (!url || loaded || !isVisible || granted === attempt) return undefined;

    // Anything still held here belongs to a request this one supersedes — a new
    // URL for the same card, or a retry started before the last attempt
    // settled. Nothing is waiting on it, and a slot nobody is using is a slot
    // the rest of the grid has lost.
    releaseTicket();

    const ticket = slots.acquire();
    let abandoned = false;
    let held = false;

    ticket.granted.then((isGranted) => {
      if (!isGranted) return;
      // Released between asking and being let in: hand the slot straight back,
      // or it is held for a card that has stopped waiting for it.
      if (abandoned) {
        ticket.release();
        return;
      }
      held = true;
      ticketRef.current = ticket;
      update({ granted: attempt });
    });

    return () => {
      abandoned = true;
      if (!held) ticket.release();
    };
  }, [attempt, granted, isVisible, loaded, releaseTicket, slots, update, url]);

  // Unmount only. The slot is held until the image settles, which is normally
  // after the effect above has already been cleaned up and re-run.
  useEffect(() => () => {
    clearTimeout(retryTimerRef.current);
    ticketRef.current?.release();
  }, []);

  const onLoad = useCallback(() => {
    releaseTicket();
    update({ loaded: true, failed: false });
  }, [releaseTicket, update]);

  const onError = useCallback(() => {
    releaseTicket();
    update({ failed: true });
    if (attempt + 1 >= maxAttempts) return;

    clearTimeout(retryTimerRef.current);
    retryTimerRef.current = setTimeout(
      // Only bumps the attempt. Whether that turns into a request is the
      // effect's decision, so a cover that failed off screen waits there.
      () => update((base) => ({ attempt: base.attempt + 1, failed: false })),
      coverRetryDelay(attempt)
    );
  }, [attempt, maxAttempts, releaseTicket, update]);

  const retry = useCallback(() => {
    clearTimeout(retryTimerRef.current);
    update((base) => ({ attempt: base.attempt + 1, failed: false, loaded: false }));
  }, [update]);

  const isExhausted = failed && attempt + 1 >= maxAttempts;
  const isRequested = granted === attempt;

  // "idle" rather than "loading" for a card nobody has scrolled to yet. A large
  // library is hundreds of these, and a placeholder that animates in every one
  // of them spends the frame budget on cells nobody is looking at — the same
  // mistake as the requests, in the compositor instead of the network.
  const status = !url ? "absent"
    : loaded ? "loaded"
      : isExhausted ? "failed"
        : failed ? "retrying"
          : isVisible || isRequested ? "loading" : "idle";

  return {
    observe: watchVisibility,
    src: isRequested ? coverUrlForAttempt(url, attempt) : null,
    status,
    attempt,
    onLoad,
    onError,
    retry,
  };
}
