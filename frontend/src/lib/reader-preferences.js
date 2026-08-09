export const READER_FITS = Object.freeze([
  { value: "contain", label: "Best fit" },
  { value: "width", label: "Fit width" },
  { value: "height", label: "Fit height" },
  { value: "original", label: "Original size" },
]);

const FIT_VALUES = new Set(READER_FITS.map(({ value }) => value));

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

/**
 * Treat server data as untrusted. This mirrors the backend's read-time
 * normalization so a stale response can never select a broken renderer or
 * distort a page.
 */
export function normalizeReaderPreferences(candidate) {
  const settings = candidate?.schemaVersion === 1 && candidate?.settings && typeof candidate.settings === "object"
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
    // Reserved for validated device/orientation contexts in later reader work.
    overrides: [],
  };
}

export function updateReaderSettings(preferences, patch) {
  return normalizeReaderPreferences({
    ...preferences,
    settings: { ...preferences.settings, ...patch },
  });
}
