import { describe, expect, it } from "vitest";

import {
  DEFAULT_READER_PREFERENCES,
  normalizeReaderPreferences,
  updateReaderSettings,
} from "./reader-preferences";

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
