#!/usr/bin/env node

/**
 * Keep the two nginx policies aligned with the CSP manifest.
 *
 * Apache HTML is served by Symfony, which creates a cryptographic nonce and
 * the header together in FrontendController. nginx serves static HTML, so its
 * per-request `$request_id` is substituted into both the header and every
 * initial script tag. Scripts those trusted modules create are covered by
 * `strict-dynamic`.
 */

import { readFileSync, writeFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const scriptDir = dirname(fileURLToPath(import.meta.url));
const repoRoot = resolve(scriptDir, "..");
const checkOnly = process.argv.includes("--check");
const manifest = JSON.parse(readFileSync(resolve(repoRoot, "backend/config/csp.json"), "utf8"));
const turnstileLoader = readFileSync(resolve(repoRoot, "frontend/src/lib/turnstile-loader.js"), "utf8");

if (!turnstileLoader.includes(`TURNSTILE_SCRIPT_ORIGIN = "${manifest.turnstileScriptOrigin}"`)) {
  throw new Error("The Turnstile loader origin has drifted from backend/config/csp.json.");
}
if (!manifest.directives["frame-src"].includes(manifest.turnstileScriptOrigin)
  || !manifest.scriptSrcWithoutAdvertising.includes(manifest.turnstileScriptOrigin)
  || !manifest.scriptSrcWithAdvertising.includes(manifest.turnstileScriptOrigin)) {
  throw new Error("The Turnstile origin must be present in every applicable script-src and frame-src policy.");
}

const policy = (directives) => Object.entries(directives)
  .map(([directive, values]) => `${directive} ${values.join(" ")}`)
  .join("; ");

const withAdditions = (base, additions) => Object.fromEntries(
  Object.entries(base).map(([directive, values]) => [
    directive,
    additions[directive] ? [...values, ...additions[directive]] : values,
  ]),
);

const advertisingPolicy = (nonce, development = false) => {
  const base = {
    ...manifest.directives,
    "script-src": manifest.scriptSrcWithAdvertising.map((value) => value.replace("{nonce}", nonce)),
  };

  return policy(development ? withAdditions(base, manifest.developmentAdditions) : base);
};

const rewrite = (relativePath, development) => {
  const path = resolve(repoRoot, relativePath);
  const before = readFileSync(path, "utf8");
  const replacement = `add_header Content-Security-Policy "${advertisingPolicy("$request_id", development)}" always;`;
  const after = before.replace(/add_header Content-Security-Policy "[^"]*" always;/, replacement);
  if (after === before && !before.includes(replacement)) {
    throw new Error(`No Content-Security-Policy line found in ${relativePath}.`);
  }
  if (after === before) return null;
  if (!checkOnly) writeFileSync(path, after);
  return relativePath;
};

const stale = [
  rewrite("docker/nginx_frontend/security-headers.conf", false),
  rewrite("docker/nginx_frontend/nginx.dev.conf", true),
].filter(Boolean);

if (checkOnly && stale.length > 0) {
  console.error(`Content-Security-Policy is out of date in:\n  ${stale.join("\n  ")}\nRun: node scripts/generate-csp.mjs`);
  process.exit(1);
}

console.log(checkOnly
  ? "Strict Content-Security-Policy is up to date across both nginx targets."
  : stale.length === 0
    ? "Strict Content-Security-Policy already matched both nginx targets."
    : `Strict Content-Security-Policy written to:\n  ${stale.join("\n  ")}`);
