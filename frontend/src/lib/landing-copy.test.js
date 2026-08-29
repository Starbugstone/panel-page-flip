import { readFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";

import { landingCopy, landingPhrases } from "./landing-copy.js";

const frontendDir = resolve(dirname(fileURLToPath(import.meta.url)), "..", "..");
const indexHtml = readFileSync(resolve(frontendDir, "index.html"), "utf8");
const pageTitle = "Panel Page Flip – Personal Comic Library";

describe("crawlable landing copy", () => {
  it("uses the product name in the static footer", () => {
    expect(indexHtml).toMatch(/<footer>[\s\S]*© 2026 Panel Page Flip\. All rights reserved\.[\s\S]*<\/footer>/);
  });

  it("uses the accurate library title in every metadata title", () => {
    expect(indexHtml).toContain(`<title>${pageTitle}</title>`);
    expect(indexHtml).toContain(`<meta property="og:title" content="${pageTitle}" />`);
    expect(indexHtml).toContain(`<meta name="twitter:title" content="${pageTitle}" />`);
  });

  it("names the direct and code-based sharing workflows", () => {
    expect(landingCopy.sharing.body).toMatch(/username, U- code, or email/);
    expect(landingCopy.sharing.body).toMatch(/C- or G- code/);
  });

  it("puts every public phrase in the non-JS first render", () => {
    const missing = landingPhrases(landingCopy).filter((phrase) => !indexHtml.includes(phrase));

    expect(missing).toEqual([]);
  });

  it("keeps the signup and login targets that the React page advertises", () => {
    expect(indexHtml).toContain('href="/login?signup=true"');
    expect(indexHtml).toContain('href="/login"');
  });
});
