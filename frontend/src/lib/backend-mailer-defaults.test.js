import { readFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";

const frontendDir = resolve(dirname(fileURLToPath(import.meta.url)), "..", "..");
const repositoryRoot = resolve(frontendDir, "..");

describe("backend mailer from-name defaults", () => {
  it.each([
    ["backend/.env", 'MAILER_FROM_NAME="Panel Page Flip"'],
    ["backend/.env.dev", 'MAILER_FROM_NAME="Panel Page Flip"'],
    ["backend/.env.example", 'MAILER_FROM_NAME="Panel Page Flip"'],
    ["scripts/.env.deploy.example", 'PROD_MAILER_FROM_NAME="Panel Page Flip"'],
    ["scripts/build-release.sh", "PROD_MAILER_FROM_NAME:-Panel Page Flip"],
    ["scripts/server/server-install.sh", 'MAILER_FROM_NAME="Panel Page Flip"'],
    ["SSH-deploy.md", 'MAILER_FROM_NAME="Panel Page Flip"'],
  ])("%s uses the safe product default", (relativePath, expectedSetting) => {
    expect(readFileSync(resolve(repositoryRoot, relativePath), "utf8")).toContain(expectedSetting);
  });
});
