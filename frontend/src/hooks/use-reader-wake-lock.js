import { useEffect } from "react";

/** Keep long reading sessions awake when the browser supports the Screen Wake Lock API. */
export function useReaderWakeLock(enabled) {
  useEffect(() => {
    if (!enabled || typeof navigator === "undefined" || !("wakeLock" in navigator)) return undefined;

    let sentinel = null;
    let cancelled = false;

    const acquire = async () => {
      if (cancelled || document.visibilityState !== "visible" || sentinel) return;
      try {
        const acquired = await navigator.wakeLock.request("screen");
        if (cancelled) {
          await acquired.release();
          return;
        }
        sentinel = acquired;
        acquired.addEventListener?.("release", () => {
          if (sentinel === acquired) sentinel = null;
        });
      } catch {
        // Support and permission vary by device. Reading remains fully usable.
      }
    };

    const handleVisibilityChange = () => {
      if (document.visibilityState === "visible") void acquire();
    };

    void acquire();
    document.addEventListener("visibilitychange", handleVisibilityChange);

    return () => {
      cancelled = true;
      document.removeEventListener("visibilitychange", handleVisibilityChange);
      void sentinel?.release();
      sentinel = null;
    };
  }, [enabled]);
}
