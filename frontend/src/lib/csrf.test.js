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

  it("keeps a token that contains '=' whole", () => {
    globalThis.document = { cookie: "other=1; XSRF-TOKEN=YWJjZGVmZw==; keep=1" };

    expect(getCsrfToken()).toBe("YWJjZGVmZw==");
  });

  it("decodes a percent-encoded token", () => {
    globalThis.document = { cookie: "XSRF-TOKEN=a%2Bb%2Fc%3D" };

    expect(getCsrfToken()).toBe("a+b/c=");
  });

  it("does not match a cookie whose name merely ends in XSRF-TOKEN", () => {
    globalThis.document = { cookie: "NOT-XSRF-TOKEN=wrong; XSRF-TOKEN=right" };

    expect(getCsrfToken()).toBe("right");
  });

  it("falls back to the raw value when the escape is malformed", () => {
    globalThis.document = { cookie: "XSRF-TOKEN=100%" };

    expect(getCsrfToken()).toBe("100%");
  });

  it("survives a document without cookies", () => {
    globalThis.document = { cookie: "" };

    expect(getCsrfToken()).toBe("");
  });
});
