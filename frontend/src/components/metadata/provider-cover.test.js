import { readFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";

import { displayableCoverUrl } from "./provider-cover";

const repositoryRoot = resolve(dirname(fileURLToPath(import.meta.url)), "..", "..", "..", "..");
const manifest = JSON.parse(readFileSync(resolve(repositoryRoot, "backend/config/csp.json"), "utf8"));

const METRON_COVER = "https://static.metron.cloud/media/issue/cover.jpg";

describe("which provider covers may be drawn", () => {
  it("accepts Metron's image host", () => {
    expect(displayableCoverUrl(METRON_COVER)).toBe(METRON_COVER);
  });

  /* The two lists have to agree: an address accepted here and refused by the
     policy would render as a broken image, on production only. */
  it("agrees with the Content-Security-Policy", () => {
    const providerHosts = manifest.directives["img-src"]
      .filter((source) => source.startsWith("https://") && !/google|doubleclick/i.test(source));

    expect(providerHosts).toEqual(["https://static.metron.cloud"]);
    expect(displayableCoverUrl(`${providerHosts[0]}/a.jpg`)).toBe(`${providerHosts[0]}/a.jpg`);
  });

  it.each([
    ["plain http", "http://static.metron.cloud/a.jpg"],
    ["a host that only begins the same way", "https://static.metron.cloud.example/a.jpg"],
    ["a sibling host", "https://metron.cloud/a.jpg"],
    ["the right host as a username", "https://static.metron.cloud@example.test/a.jpg"],
    ["credentials in front of the right host", "https://user:pass@static.metron.cloud/a.jpg"],
    ["another port", "https://static.metron.cloud:8443/a.jpg"],
    ["Comic Vine, whose host is not yet established", "https://comicvine.gamespot.com/a/uploads/original/1/1.jpg"],
    ["a script address", "javascript:alert(1)"],
    ["an inline image", "data:image/png;base64,AAAA"],
    ["a relative path", "/media/cover.jpg"],
    ["not an address at all", "cover"],
    ["nothing", null],
    ["something that is not text", { href: METRON_COVER }],
  ])("refuses %s", (_label, url) => {
    expect(displayableCoverUrl(url)).toBeNull();
  });
});
