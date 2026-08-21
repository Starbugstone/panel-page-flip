import { READER_DEVICES, READER_ORIENTATIONS, viewportContextKey } from "@/lib/reader-viewport";

export const READER_FITS = Object.freeze([
  { value: "contain", label: "Best fit" },
  { value: "width", label: "Fit width" },
  { value: "height", label: "Fit height" },
  { value: "original", label: "Original size" },
]);

const FIT_VALUES = new Set(READER_FITS.map(({ value }) => value));

// What a device/orientation context may say for itself. A context chooses how a
// page is sized on this shape of screen; it does not get to select a renderer,
// so mode and direction stay global until their own work lands.
export const OVERRIDABLE_SETTINGS = Object.freeze(["fit"]);

// One per context and no more: the set of contexts is closed, so a longer list
// is duplicates or junk.
const MAX_OVERRIDES = READER_DEVICES.length * READER_ORIENTATIONS.length;

export const DEFAULT_READER_PREFERENCES = Object.freeze({
  schemaVersion: 1,
  settings: Object.freeze({
    mode: "single",
    direction: "ltr",
    fit: "contain",
    autoHideControls: true,
    showProgress: true,
    wakeLock: true,
  }),
  overrides: Object.freeze([]),
});

function normalizeOverride(candidate) {
  const device = candidate?.context?.device;
  const orientation = candidate?.context?.orientation;
  if (!READER_DEVICES.includes(device) || !READER_ORIENTATIONS.includes(orientation)) return null;
  if (!FIT_VALUES.has(candidate?.settings?.fit)) return null;

  return { context: { device, orientation }, settings: { fit: candidate.settings.fit } };
}

function normalizeOverrides(candidate) {
  if (!Array.isArray(candidate)) return [];

  const byContext = new Map();
  for (const override of candidate) {
    const valid = normalizeOverride(override);
    // Last wins, so a re-saved context replaces its predecessor rather than
    // leaving two entries that disagree.
    if (valid) byContext.set(viewportContextKey(valid.context), valid);
  }

  return [...byContext.values()].slice(0, MAX_OVERRIDES);
}

/**
 * Treat server data as untrusted. This mirrors the backend's read-time
 * normalization so a stale response can never select a broken renderer or
 * distort a page.
 */
export function normalizeReaderPreferences(candidate) {
  const isCurrentSchema = candidate?.schemaVersion === 1;
  const settings = isCurrentSchema && candidate?.settings && typeof candidate.settings === "object"
    ? candidate.settings
    : {};

  return {
    schemaVersion: 1,
    settings: {
      mode: settings.mode === "single" ? settings.mode : "single",
      direction: settings.direction === "ltr" ? settings.direction : "ltr",
      fit: FIT_VALUES.has(settings.fit) ? settings.fit : "contain",
      autoHideControls: typeof settings.autoHideControls === "boolean" ? settings.autoHideControls : true,
      showProgress: typeof settings.showProgress === "boolean" ? settings.showProgress : true,
      wakeLock: typeof settings.wakeLock === "boolean" ? settings.wakeLock : true,
    },
    overrides: isCurrentSchema ? normalizeOverrides(candidate.overrides) : [],
  };
}

export function updateReaderSettings(preferences, patch) {
  return normalizeReaderPreferences({
    ...preferences,
    settings: { ...preferences.settings, ...patch },
  });
}

function contextsMatch(a, b) {
  return a?.device === b?.device && a?.orientation === b?.orientation;
}

/**
 * What this screen should actually read with: the account's settings, with any
 * choice made for this device and orientation laid over them.
 */
export function effectiveReaderSettings(preferences, context) {
  const override = preferences?.overrides?.find((entry) => contextsMatch(entry.context, context));
  return override ? { ...preferences.settings, ...override.settings } : { ...preferences.settings };
}

/**
 * Record a choice against one device and orientation. A phone kept upright and
 * a tablet turned sideways want different page sizes, and neither should be
 * able to quietly rewrite the other — or the account default.
 */
export function setReaderOverride(preferences, context, patch) {
  const settings = Object.fromEntries(
    Object.entries(patch).filter(([key]) => OVERRIDABLE_SETTINGS.includes(key))
  );
  const others = (preferences.overrides ?? []).filter((entry) => !contextsMatch(entry.context, context));
  const existing = (preferences.overrides ?? []).find((entry) => contextsMatch(entry.context, context));

  return normalizeReaderPreferences({
    ...preferences,
    overrides: [...others, { context, settings: { ...existing?.settings, ...settings } }],
  });
}

/** Hand a context back to the account default. */
export function clearReaderOverride(preferences, context) {
  return normalizeReaderPreferences({
    ...preferences,
    overrides: (preferences.overrides ?? []).filter((entry) => !contextsMatch(entry.context, context)),
  });
}

export function hasReaderOverride(preferences, context) {
  return Boolean(preferences?.overrides?.some((entry) => contextsMatch(entry.context, context)));
}
