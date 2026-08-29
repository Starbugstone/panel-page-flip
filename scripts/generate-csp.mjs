#!/usr/bin/env node

/**
 * Emit the Content-Security-Policy into every file that serves it.
 *
 * The same policy has to appear as an nginx `add_header`, an Apache
 * `Header always set`, and a development variant that also permits Vite's
 * inline scripts and websocket. Hand-maintaining three copies in two syntaxes
 * fails invisibly in whichever place you are not testing: fix development only
 * and production silently blocks advertising; fix nginx only and the Apache
 * release — which is what build-release.sh actually ships — blocks it.
 *
 * `--check` verifies the committed files match, which is what CI runs.
 */

import { readFileSync, writeFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const scriptDir = dirname(fileURLToPath(import.meta.url));
const repoRoot = resolve(scriptDir, "..");
const checkOnly = process.argv.includes("--check");

const manifest = JSON.parse(readFileSync(resolve(repoRoot, "backend/config/csp.json"), "utf8"));

const policy = (directives) => Object.entries(directives)
  .map(([directive, values]) => `${directive} ${values.join(" ")}`)
  .join("; ");

const withAdditions = (base, additions) => Object.fromEntries(
  Object.entries(base).map(([directive, values]) => [
    directive,
    // Additions sit directly after 'self' so the policy reads the same way in
    // every file: own origin first, then what this environment also allows.
    additions[directive] ? [values[0], ...additions[directive], ...values.slice(1)] : values,
  ]),
);

const { directives, apacheOnly, developmentAdditions, requiredScriptHosts } = manifest;

// The policy must permit the hosts the application actually loads scripts from.
// Those live in one place in the frontend; a policy that forgot one would block
// advertising with no error anywhere.
const advertisingSource = readFileSync(resolve(repoRoot, requiredScriptHosts.source), "utf8");
for (const constant of requiredScriptHosts.constants) {
  const match = advertisingSource.match(new RegExp(`${constant}\\s*=\\s*"([^"]+)"`));
  if (!match) throw new Error(`${constant} was not found in ${requiredScriptHosts.source}.`);
  if (!directives["script-src"].includes(match[1])) {
    throw new Error(`script-src does not permit ${constant} (${match[1]}), which the application loads.`);
  }
}

const productionPolicy = policy(directives);
const apachePolicy = policy({ ...directives, ...apacheOnly });
const developmentPolicy = policy(withAdditions(directives, developmentAdditions));

/** Replace the value inside the one policy line in a file, leaving its comments alone. */
const rewrite = (relativePath, pattern, replacement) => {
  const path = resolve(repoRoot, relativePath);
  const before = readFileSync(path, "utf8");
  if (!pattern.test(before)) throw new Error(`No Content-Security-Policy line found in ${relativePath}.`);

  const after = before.replace(pattern, replacement);
  if (after === before) return null;
  if (!checkOnly) writeFileSync(path, after);

  return relativePath;
};

const stale = [
  rewrite(
    "docker/nginx_frontend/security-headers.conf",
    /add_header Content-Security-Policy "[^"]*" always;/,
    `add_header Content-Security-Policy "${productionPolicy}" always;`,
  ),
  rewrite(
    "scripts/deploy/htaccess.dist",
    /Header always set Content-Security-Policy "[^"]*"/,
    `Header always set Content-Security-Policy "${apachePolicy}"`,
  ),
  rewrite(
    "docker/nginx_frontend/nginx.dev.conf",
    /add_header Content-Security-Policy "[^"]*" always;/,
    `add_header Content-Security-Policy "${developmentPolicy}" always;`,
  ),
].filter(Boolean);

if (checkOnly && stale.length > 0) {
  console.error(`Content-Security-Policy is out of date in:\n  ${stale.join("\n  ")}\nRun: node scripts/generate-csp.mjs`);
  process.exit(1);
}

const directiveCount = Object.keys(directives).length;
console.log(
  checkOnly
    ? `Content-Security-Policy is up to date across 3 files (${directiveCount} directives).`
    : stale.length === 0
      ? `Content-Security-Policy already matched in all 3 files (${directiveCount} directives).`
      : `Content-Security-Policy written to:\n  ${stale.join("\n  ")}`,
);
