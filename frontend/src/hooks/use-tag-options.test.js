import { describe, expect, it } from "vitest";

import { isRetryableTagError } from "./use-tag-options";

describe("isRetryableTagError", () => {
  it.each([
    [{ status: 0, message: "Unable to reach the server" }, true],
    [{ status: 503, message: "Temporarily unavailable" }, true],
    [{ status: 400, message: "network connection failed" }, true],
    [{ status: 404, message: "Tag endpoint not found" }, false],
  ])("classifies %o as retryable=%s", (error, expected) => {
    expect(isRetryableTagError(error)).toBe(expected);
  });
});
