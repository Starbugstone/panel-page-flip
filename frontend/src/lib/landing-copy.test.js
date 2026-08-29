import { readFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";

import { landingCopy, landingPhrases } from "./landing-copy.js";

const frontendDir = resolve(dirname(fileURLToPath(import.meta.url)), "..", "..");
const indexHtml = readFileSync(resolve(frontendDir, "index.html"), "utf8");

describe("crawlable landing copy", () => {
  it("puts every public phrase in the non-JS first render", () => {
    const missing = landingPhrases(landingCopy).filter((phrase) => !indexHtml.includes(phrase));

    expect(missing).toEqual([]);
  });

  it("keeps the signup and login targets that the React page advertises", () => {
    expect(indexHtml).toContain('href="/login?signup=true"');
    expect(indexHtml).toContain('href="/login"');
  });
});
