import { useEffect, useState } from "react";

import { classifyViewport } from "@/lib/reader-viewport";

const CLASSIFICATION_KEYS = ["device", "orientation", "coarsePointer", "touchCapable", "hasHover", "memory"];

const sameProfile = (a, b) => CLASSIFICATION_KEYS.every((key) => a[key] === b[key]);

function readProfile() {
  if (typeof window === "undefined") return classifyViewport();

  return classifyViewport({
    // The layout viewport, not window.visualViewport: the visual one shrinks
    // for the on-screen keyboard and for the browser's own pinch zoom, neither
    // of which turns a tablet into a phone.
    width: window.innerWidth,
    height: window.innerHeight,
    coarsePointer: window.matchMedia?.("(pointer: coarse)").matches ?? false,
    touchCapable: (window.matchMedia?.("(pointer: coarse)").matches ?? false)
      || (window.matchMedia?.("(any-pointer: coarse)").matches ?? false)
      || (typeof navigator !== "undefined" && navigator.maxTouchPoints > 0),
    hasHover: window.matchMedia?.("(hover: hover)").matches ?? true,
    deviceMemory: typeof navigator === "undefined" ? undefined : navigator.deviceMemory,
  });
}

/**
 * What kind of screen the reader is being read on, kept current through
 * rotation, window drags and a pointer being plugged in.
 *
 * The same object is returned until the classification itself changes, so a
 * resize that does not move the answer does not re-render anything.
 */
export function useViewportProfile() {
  const [profile, setProfile] = useState(readProfile);

  useEffect(() => {
    const update = () => setProfile((current) => {
      const next = readProfile();
      return sameProfile(current, next) ? current : next;
    });

    update();

    // orientationchange as well as resize: some mobile browsers report the new
    // orientation before the viewport has finished settling to match it.
    window.addEventListener("resize", update);
    window.addEventListener("orientationchange", update);

    const queries = ["(pointer: coarse)", "(any-pointer: coarse)", "(hover: hover)"]
      .map((query) => window.matchMedia?.(query))
      .filter(Boolean);
    queries.forEach((query) => query.addEventListener?.("change", update));

    return () => {
      window.removeEventListener("resize", update);
      window.removeEventListener("orientationchange", update);
      queries.forEach((query) => query.removeEventListener?.("change", update));
    };
  }, []);

  return profile;
}
