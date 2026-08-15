import { describe, expect, it } from "vitest";
import { getCsrfHeaders, getCsrfToken } from "./csrf";

describe("csrf", () => {
  it("reads the XSRF-TOKEN cookie", () => {
    globalThis.document = { cookie: "other=1; XSRF-TOKEN=abc123; keep=1" };

    expect(getCsrfToken()).toBe("abc123");
    expect(getCsrfHeaders()).toEqual({ "X-XSRF-TOKEN": "abc123" });
  });

  it("returns nothing when the cookie is missing", () => {
    globalThis.document = { cookie: "other=1" };

    expect(getCsrfToken()).toBe("");
    expect(getCsrfHeaders()).toEqual({});
  });
});
