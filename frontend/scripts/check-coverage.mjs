import { readFile } from "node:fs/promises";
import path from "node:path";
import { pathToFileURL } from "node:url";

export function uncoveredFiles(summary) {
  return Object.entries(summary)
    .filter(([file, metrics]) => file !== "total" && metrics.lines.total > 0 && metrics.lines.covered === 0)
    .map(([file]) => file)
    .sort();
}

async function main() {
  const reportPath = process.argv[2];
  if (!reportPath) {
    throw new Error("Usage: check-coverage.mjs COVERAGE_SUMMARY.json");
  }

  const summary = JSON.parse(await readFile(reportPath, "utf8"));
  const uncovered = uncoveredFiles(summary);
  if (uncovered.length > 0) {
    const names = uncovered.map((file) => path.relative(process.cwd(), file));
    throw new Error(`Production files with no executed lines: ${names.join(", ")}`);
  }
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  main().catch((error) => {
    process.stderr.write(`${error.message}\n`);
    process.exitCode = 1;
  });
}
