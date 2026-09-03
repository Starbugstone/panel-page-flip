import { beforeEach, describe, expect, it, vi } from "vitest";

import {
  ANALYTICS_CONSENT_VERSION,
  clearAnalyticsConsent,
  persistAnalyticsConsent,
  readAnalyticsConsent,
} from "@/lib/analytics-consent-storage";

const KEY = "panel-page-flip:analytics-consent";

/**
 * A stand-in for `localStorage`, injected rather than mocked globally: this is
 * a logic test, and standing a DOM up for one string would put it in the slow
 * suite for nothing. The default argument is exercised through the provider
 * tests, which have a real one.
 */
function fakeStorage(initial = {}) {
  const values = { ...initial };

  return {
    getItem: (key) => (key in values ? values[key] : null),
    setItem: (key, value) => { values[key] = String(value); },
    removeItem: (key) => { delete values[key]; },
  };
}

let storage;

beforeEach(() => {
  storage = fakeStorage();
});

/**
 * One stored answer, and everything ambiguous reads as "not yet asked". A
 * corrupted value that happened to be truthy would be a grant nobody gave.
 */
describe("the stored analytics decision", () => {
  it("starts undecided and round-trips each answer", () => {
    expect(readAnalyticsConsent(storage)).toBe("undecided");

    expect(persistAnalyticsConsent("granted", storage)).toBe(true);
    expect(readAnalyticsConsent(storage)).toBe("granted");

    expect(persistAnalyticsConsent("denied", storage)).toBe(true);
    expect(readAnalyticsConsent(storage)).toBe("denied");
  });

  it("records the schema version alongside the answer", () => {
    persistAnalyticsConsent("granted", storage);

    expect(JSON.parse(storage.getItem(KEY))).toMatchObject({
      version: ANALYTICS_CONSENT_VERSION,
      decision: "granted",
    });
  });

  /**
   * Consent is to a particular description of a particular purpose. Changing
   * what the dialogue says has to be able to invalidate answers given to the
   * old wording, and the only safe reading of a stale answer is none.
   */
  it("treats an answer from an older schema as undecided", () => {
    storage.setItem(KEY, JSON.stringify({
      version: ANALYTICS_CONSENT_VERSION - 1,
      decision: "granted",
    }));

    expect(readAnalyticsConsent(storage)).toBe("undecided");
  });

  it.each([
    ["unparseable JSON", "not json"],
    ["an unrecognised decision", JSON.stringify({ version: ANALYTICS_CONSENT_VERSION, decision: "maybe" })],
    ["a bare truthy value", "true"],
    ["an empty string", ""],
  ])("reads %s as undecided rather than as a grant", (_label, stored) => {
    storage.setItem(KEY, stored);

    expect(readAnalyticsConsent(storage)).toBe("undecided");
  });

  it("refuses to store anything that is not one of the two answers", () => {
    expect(persistAnalyticsConsent("maybe", storage)).toBe(false);
    expect(storage.getItem(KEY)).toBeNull();
  });

  it("forgets the answer when asked to", () => {
    persistAnalyticsConsent("granted", storage);

    clearAnalyticsConsent(storage);

    expect(readAnalyticsConsent(storage)).toBe("undecided");
  });

  /**
   * Private browsing and blocked storage both throw. The choice still applies
   * for this page load; it simply is not remembered, and nothing crashes.
   */
  it("survives storage being unavailable", () => {
    const blocked = {
      getItem: vi.fn(() => { throw new Error("blocked"); }),
      setItem: vi.fn(() => { throw new Error("blocked"); }),
      removeItem: vi.fn(() => { throw new Error("blocked"); }),
    };

    expect(readAnalyticsConsent(blocked)).toBe("undecided");
    expect(persistAnalyticsConsent("granted", blocked)).toBe(false);
    expect(() => clearAnalyticsConsent(blocked)).not.toThrow();
  });
});
