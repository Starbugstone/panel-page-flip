import { describe, expect, it } from "vitest";
import { getCookie, setCookie } from "./cookies";

describe("cookies", () => {
  it("round-trips a value through document.cookie", () => {
    const jar = [];
    globalThis.document = {
      get cookie() {
        return jar.join("; ");
      },
      set cookie(value) {
        jar.push(value.split(";")[0]);
      },
    };
    globalThis.window = { location: { protocol: "http:" } };

    setCookie("notice", "dismissed", 1);

    expect(getCookie("notice")).toBe("dismissed");
    expect(getCookie("missing")).toBeNull();
  });

  it("decodes a stored value", () => {
    globalThis.document = { cookie: "title=Hello%20World" };

    expect(getCookie("title")).toBe("Hello World");
  });
});
