import { logger } from "@/lib/logger";

/**
 * Asking Google for a rewarded advertisement, and reporting honestly whether
 * one was actually watched.
 *
 * Google implements the rewarded format itself — this application plays no
 * video, counts no seconds and draws no skip button, which is both the policy
 * requirement and the only sane way to stay on the right side of it. The Ad
 * Placement API is the surface that reports completion: `adViewed` fires when
 * Google considers the reward earned, and it is the *only* thing here that
 * produces a `"viewed"`.
 *
 * The important negative: every other outcome — the API absent because an ad
 * blocker ate the site code, no rewarded inventory, a frequency cap, the user
 * closing the ad early, an exception out of Google's own code — resolves to
 * something that is not `"viewed"`, and the caller opens bulk upload anyway.
 * Issue #73 is explicit that bulk upload must never depend on an advertisement
 * being available, so a missing ad is a missing enhancement, not a locked door.
 */

/**
 * How long to wait for Google to say whether it has an advertisement.
 *
 * This bounds the *availability* question only. Once Google calls back to say
 * an ad is ready, the timer is cleared and the wait becomes as long as the
 * advertisement lasts — cutting that short would abandon a user midway through
 * something they agreed to watch and then deny them the reward for it.
 */
export const REWARDED_AVAILABILITY_TIMEOUT_MS = 8000;

/**
 * @returns {Promise<"viewed" | "dismissed" | "unavailable">}
 */
export function requestRewardedAd({
  win = typeof window === "undefined" ? null : window,
  timeoutMs = REWARDED_AVAILABILITY_TIMEOUT_MS,
} = {}) {
  const adBreak = win?.adBreak;
  if (typeof adBreak !== "function") {
    // The ordinary case on most installations: the site code never loaded, or
    // this account has no rewarded inventory configured.
    return Promise.resolve("unavailable");
  }

  return new Promise((resolve) => {
    let settled = false;

    const finish = (outcome) => {
      if (settled) return;
      settled = true;
      clearTimeout(availabilityTimer);
      resolve(outcome);
    };

    const availabilityTimer = setTimeout(() => finish("unavailable"), timeoutMs);

    try {
      adBreak({
        type: "reward",
        name: "bulk-upload",
        beforeReward: (showAdFn) => {
          // Google has an advertisement. The user has already asked for it by
          // pressing the button, so it is shown straight away rather than
          // behind a second confirmation they did not ask for.
          clearTimeout(availabilityTimer);
          showAdFn();
        },
        adViewed: () => finish("viewed"),
        adDismissed: () => finish("dismissed"),
        // Always called, including when no advertisement was ever shown. It runs
        // after the outcome callbacks, so by this point a watched or dismissed
        // ad has already settled and this only catches "there was nothing".
        adBreakDone: () => finish("unavailable"),
      });
    } catch (error) {
      logger.warn("The rewarded advertisement could not be requested:", error.message);
      finish("unavailable");
    }
  });
}
