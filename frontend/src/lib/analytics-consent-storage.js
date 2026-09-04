import { logger } from "@/lib/logger";

/**
 * The one place an Analytics-only installation's consent decision is kept.
 *
 * Only for the local consent provider. Where Google's certified CMP owns
 * consent, it owns the whole answer and nothing is stored here — a second copy
 * could disagree with the real one, and the disagreement would be invisible.
 *
 * Stored with a schema version so that changing what the dialogue says can
 * invalidate the answers people gave to the old wording. Consent is to a
 * specific description of a specific purpose; if the description changes
 * materially, the old answer is not consent to the new one.
 */
export const ANALYTICS_CONSENT_STORAGE_KEY = "panel-page-flip:analytics-consent";

/** Raise this when the wording changes in a way that makes old answers stale. */
export const ANALYTICS_CONSENT_VERSION = 1;
export const ANALYTICS_CONSENT_MAX_AGE_MS = 180 * 24 * 60 * 60 * 1000;

export const ANALYTICS_CONSENT_UNDECIDED = "undecided";
export const ANALYTICS_CONSENT_GRANTED = "granted";
export const ANALYTICS_CONSENT_DENIED = "denied";

const DECISIONS = [ANALYTICS_CONSENT_GRANTED, ANALYTICS_CONSENT_DENIED];

function store(storage) {
  return storage ?? globalThis.localStorage;
}

/**
 * The stored decision, or `undecided`.
 *
 * Anything unreadable, unparseable, unrecognised or written by an older schema
 * reads as undecided rather than as a grant. Failing closed here is the whole
 * point: the tag must not load because a corrupted value happened to be truthy.
 */
export function readAnalyticsConsent(storage, now = Date.now()) {
  try {
    const raw = store(storage).getItem(ANALYTICS_CONSENT_STORAGE_KEY);
    if (!raw) return ANALYTICS_CONSENT_UNDECIDED;

    const parsed = JSON.parse(raw);
    if (parsed?.version !== ANALYTICS_CONSENT_VERSION) return ANALYTICS_CONSENT_UNDECIDED;
    if (typeof parsed.decidedAt !== "string") return ANALYTICS_CONSENT_UNDECIDED;
    const decidedAt = Date.parse(parsed.decidedAt);
    const age = now - decidedAt;
    if (
      !Number.isFinite(decidedAt)
      || !Number.isFinite(age)
      || age < 0
      || age > ANALYTICS_CONSENT_MAX_AGE_MS
    ) {
      return ANALYTICS_CONSENT_UNDECIDED;
    }

    return DECISIONS.includes(parsed.decision) ? parsed.decision : ANALYTICS_CONSENT_UNDECIDED;
  } catch {
    // Blocked storage, or somebody else's value under our key. Either way there
    // is no decision to act on.
    return ANALYTICS_CONSENT_UNDECIDED;
  }
}

export function persistAnalyticsConsent(decision, storage, now = Date.now()) {
  if (!DECISIONS.includes(decision)) return false;
  const decidedAt = new Date(now);
  if (Number.isNaN(decidedAt.getTime())) return false;

  try {
    store(storage).setItem(ANALYTICS_CONSENT_STORAGE_KEY, JSON.stringify({
      version: ANALYTICS_CONSENT_VERSION,
      decision,
      decidedAt: decidedAt.toISOString(),
    }));

    return true;
  } catch (error) {
    // Private browsing and blocked storage both land here. The choice still
    // applies for this page load; it simply will not be remembered.
    logger.log("The analytics choice could not be stored:", error.message);

    return false;
  }
}
