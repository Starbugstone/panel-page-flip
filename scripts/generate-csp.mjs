#!/usr/bin/env node

/**
 * Keep nginx's policy aligned with the CSP manifest.
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
  || !manifest.scriptSrcWithoutGoogle.includes(manifest.turnstileScriptOrigin)
  || !manifest.scriptSrcWithGoogle.includes(manifest.turnstileScriptOrigin)) {
  throw new Error("The Turnstile origin must be present in every applicable script-src and frame-src policy.");
}

// Everything Google-shaped the manifest names anywhere has to be in
// googleOrigins, or the legal-route policy below would keep whichever origin
// was added to a directive and forgotten here.
const googleShaped = /google|doubleclick|adtrafficquality/i;
const undeclared = Object.values(manifest.directives)
  .flat()
  .filter((source) => googleShaped.test(source) && !manifest.googleOrigins.includes(source));
if (undeclared.length > 0) {
  throw new Error(`Google origins missing from googleOrigins in backend/config/csp.json: ${[...new Set(undeclared)].join(", ")}`);
}

const policy = (directives) => Object.entries(directives)
  .map(([directive, values]) => `${directive} ${values.join(" ")}`)
  .join("; ");

const googlePolicy = (nonce) => policy({
  ...manifest.directives,
  "script-src": manifest.scriptSrcWithGoogle.map((value) => value.replace("{nonce}", nonce)),
});

/**
 * The policy for the legal-policy routes, which must name no Google origin in
 * any directive. Derived from the same manifest rather than written out again,
 * so an origin added above cannot survive here by being forgotten. A directive
 * left with nothing is dropped: an empty source list is a parse error, and
 * `default-src 'self'` already covers what would have remained.
 */
const googleFreePolicy = () => {
  const directives = Object.fromEntries(
    Object.entries(manifest.directives)
      .map(([directive, values]) => [directive, values.filter((value) => !manifest.googleOrigins.includes(value))])
      .filter(([, values]) => values.length > 0),
  );

  return policy({ ...directives, "script-src": manifest.scriptSrcWithoutGoogle });
};

const rewrite = (relativePath, expectedPolicy) => {
  const path = resolve(repoRoot, relativePath);
  const before = readFileSync(path, "utf8");
  const replacement = `add_header Content-Security-Policy "${expectedPolicy}" always;`;
  const after = before.replace(/add_header Content-Security-Policy "[^"]*" always;/, replacement);
  if (after === before && !before.includes(replacement)) {
    throw new Error(`No Content-Security-Policy line found in ${relativePath}.`);
  }
  if (after === before) return null;
  if (!checkOnly) writeFileSync(path, after);
  return relativePath;
};

const stale = [
  rewrite("docker/nginx_frontend/security-headers.conf", googlePolicy("$request_id")),
  rewrite("docker/nginx_frontend/security-headers-google-free.conf", googleFreePolicy()),
].filter(Boolean);

if (checkOnly && stale.length > 0) {
  console.error(`Content-Security-Policy is out of date in:\n  ${stale.join("\n  ")}\nRun: node scripts/generate-csp.mjs`);
  process.exit(1);
}

console.log(checkOnly
  ? "Strict Content-Security-Policy is up to date in nginx."
  : stale.length === 0
    ? "Strict Content-Security-Policy already matched nginx."
    : `Strict Content-Security-Policy written to:\n  ${stale.join("\n  ")}`);
