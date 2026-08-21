import { describe, expect, it } from "vitest";

import {
  DEFAULT_READER_PREFERENCES,
  clearReaderOverride,
  effectiveReaderSettings,
  hasReaderOverride,
  normalizeReaderPreferences,
  setReaderOverride,
  updateReaderSettings,
} from "./reader-preferences";

const phonePortrait = { device: "phone", orientation: "portrait" };
const phoneLandscape = { device: "phone", orientation: "landscape" };

describe("reader preferences", () => {
  it("falls back safely for a stale or malformed response", () => {
    expect(normalizeReaderPreferences({ schemaVersion: 99, settings: { fit: "width" } }))
      .toEqual(DEFAULT_READER_PREFERENCES);

    expect(normalizeReaderPreferences({
      schemaVersion: 1,
      settings: { fit: "stretch", showProgress: false, wakeLock: "yes" },
    }).settings).toMatchObject({ fit: "contain", showProgress: false, wakeLock: true });
  });

  it("updates only recognized settings and preserves a complete envelope", () => {
    const updated = updateReaderSettings(DEFAULT_READER_PREFERENCES, {
      fit: "width",
      showProgress: false,
      arbitrary: "discarded",
    });

    expect(updated.settings.fit).toBe("width");
    expect(updated.settings.showProgress).toBe(false);
    expect(updated.settings).not.toHaveProperty("arbitrary");
  });
});

describe("what one device and orientation may say for itself", () => {
  it("reads with the account settings where nothing has been said", () => {
    expect(effectiveReaderSettings(DEFAULT_READER_PREFERENCES, phonePortrait))
      .toEqual(DEFAULT_READER_PREFERENCES.settings);
  });

  it("applies a context's choice over the account default", () => {
    const preferences = setReaderOverride(DEFAULT_READER_PREFERENCES, phonePortrait, { fit: "width" });

    expect(effectiveReaderSettings(preferences, phonePortrait).fit).toBe("width");
    expect(effectiveReaderSettings(preferences, phoneLandscape).fit).toBe("contain");
    // The account default is what every other screen still reads with.
    expect(preferences.settings.fit).toBe("contain");
  });

  it("replaces a context's choice rather than stacking another one beside it", () => {
    const once = setReaderOverride(DEFAULT_READER_PREFERENCES, phonePortrait, { fit: "width" });
    const twice = setReaderOverride(once, phonePortrait, { fit: "height" });

    expect(twice.overrides).toHaveLength(1);
    expect(effectiveReaderSettings(twice, phonePortrait).fit).toBe("height");
  });

  it("refuses to let a context choose a renderer", () => {
    const preferences = setReaderOverride(DEFAULT_READER_PREFERENCES, phonePortrait, {
      fit: "width",
      mode: "double",
      direction: "rtl",
    });

    expect(preferences.overrides[0].settings).toEqual({ fit: "width" });
    expect(effectiveReaderSettings(preferences, phonePortrait).mode).toBe("single");
  });

  it("hands a context back to the account default", () => {
    const preferences = setReaderOverride(DEFAULT_READER_PREFERENCES, phonePortrait, { fit: "width" });
    const cleared = clearReaderOverride(preferences, phonePortrait);

    expect(hasReaderOverride(preferences, phonePortrait)).toBe(true);
    expect(hasReaderOverride(cleared, phonePortrait)).toBe(false);
    expect(effectiveReaderSettings(cleared, phonePortrait).fit).toBe("contain");
  });

  it("drops overrides a server should never have sent", () => {
    const { overrides } = normalizeReaderPreferences({
      ...DEFAULT_READER_PREFERENCES,
      overrides: [
        { context: { device: "watch", orientation: "portrait" }, settings: { fit: "width" } },
        { context: { device: "phone", orientation: "sideways" }, settings: { fit: "width" } },
        { context: phonePortrait, settings: { fit: "stretch" } },
        { context: phoneLandscape, settings: { fit: "height" } },
        "not an override",
      ],
    });

    expect(overrides).toEqual([{ context: phoneLandscape, settings: { fit: "height" } }]);
  });

  it("keeps the last word when a context arrives twice", () => {
    const { overrides } = normalizeReaderPreferences({
      ...DEFAULT_READER_PREFERENCES,
      overrides: [
        { context: phonePortrait, settings: { fit: "width" } },
        { context: phonePortrait, settings: { fit: "original" } },
      ],
    });

    expect(overrides).toEqual([{ context: phonePortrait, settings: { fit: "original" } }]);
  });

  it("discards overrides that came with a schema it does not understand", () => {
    const { overrides } = normalizeReaderPreferences({
      schemaVersion: 2,
      settings: DEFAULT_READER_PREFERENCES.settings,
      overrides: [{ context: phonePortrait, settings: { fit: "width" } }],
    });

    expect(overrides).toEqual([]);
  });
});
