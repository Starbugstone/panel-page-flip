import { describe, expect, it } from "vitest";

import { uncoveredFiles } from "../../scripts/check-coverage.mjs";

describe("frontend coverage policy", () => {
  it("finds executable production files that no test reached", () => {
    expect(uncoveredFiles({
      total: { lines: { total: 10, covered: 8 } },
      "/app/src/covered.js": { lines: { total: 4, covered: 1 } },
      "/app/src/untested.jsx": { lines: { total: 3, covered: 0 } },
      "/app/src/constants.js": { lines: { total: 0, covered: 0 } },
    })).toEqual(["/app/src/untested.jsx"]);
  });
});
