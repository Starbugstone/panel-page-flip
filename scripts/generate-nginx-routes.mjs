#!/usr/bin/env node

import { readFileSync, writeFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const scriptDir = dirname(fileURLToPath(import.meta.url));
const repoRoot = resolve(scriptDir, "..");
const argument = (name) => {
  const index = process.argv.indexOf(name);
  return index === -1 ? null : process.argv[index + 1];
};
const manifestPath = resolve(
  argument("--manifest")
  || process.env.FRONTEND_ROUTES_FILE
  || `${repoRoot}/backend/config/frontend-routes.json`,
);
const indexHtmlPath = resolve(
  argument("--index-html")
  || `${repoRoot}/frontend/index.html`,
);
const outputPath = argument("--output") ? resolve(argument("--output")) : null;
const configuredAppUrl = new URL(process.env.APP_URL || "http://localhost:8080");
if (
  configuredAppUrl.username
  || configuredAppUrl.password
  || configuredAppUrl.search
  || configuredAppUrl.hash
  || (configuredAppUrl.pathname !== "/" && configuredAppUrl.pathname !== "")
) {
  throw new Error("APP_URL must be an origin without credentials, a path, query parameters, or a fragment.");
}
const appUrl = configuredAppUrl.origin;

const manifest = JSON.parse(readFileSync(manifestPath, "utf8"));
const indexable = manifest.indexable?.map((route) => route.path) ?? [];
const noindex = manifest.noindex ?? [];
const patterns = manifest.noindexPatterns ?? [];

const allExact = [...indexable, ...noindex];
if (!indexable.includes("/")) throw new Error("The frontend route manifest must include the landing page.");
if (new Set(allExact).size !== allExact.length) throw new Error("Frontend routes must be unique.");
for (const path of allExact) {
  if (typeof path !== "string" || !path.startsWith("/") || path.includes("?")) {
    throw new Error(`Invalid frontend route: ${JSON.stringify(path)}`);
  }
}
for (const pattern of patterns) {
  if (typeof pattern !== "string" || !pattern.startsWith("^/") || !pattern.endsWith("$")) {
    throw new Error(`Frontend route patterns must be anchored: ${JSON.stringify(pattern)}`);
  }
  new RegExp(pattern);
}

const escapeRegex = (value) => value.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
const exactAlternation = (routes) => routes
  .filter((path) => path !== "/")
  .map((path) => escapeRegex(path.slice(1)))
  .join("|");
const stripAnchors = (pattern) => pattern.replace(/^\^\//, "").replace(/\$$/, "");

const noindexAlternation = [exactAlternation(noindex), ...patterns.map(stripAnchors)]
  .filter(Boolean)
  .join("|");

const canonicalSource = `<link rel="canonical" href="${appUrl}/" />`;
const openGraphSource = `<meta property="og:url" content="${appUrl}/" />`;
const indexHtml = readFileSync(indexHtmlPath, "utf8").replaceAll("__APP_URL__", appUrl);
for (const source of [canonicalSource, openGraphSource]) {
  if (!indexHtml.includes(source)) {
    throw new Error(
      `sub_filter source not found in ${indexHtmlPath}: ${source}. `
      + "Update frontend/index.html or this generator so they stay in sync.",
    );
  }
}

const indexableLocations = indexable.filter((path) => path !== "/").map((path) => `location = ${path} {
    sub_filter_once off;
    sub_filter '${canonicalSource}' '<link rel="canonical" href="${appUrl}${path}" />';
    sub_filter '${openGraphSource}' '<meta property="og:url" content="${appUrl}${path}" />';
    try_files /index.html =404;
}`).join("\n\n");

const noindexBlock = noindexAlternation
  ? `location ~ "^/(?:${noindexAlternation})$" {
    include /etc/nginx/snippets/security-headers.conf;
    add_header X-Robots-Tag "noindex, follow" always;
    try_files /index.html =404;
}
`
  : "";

// Unknown URLs reach the SPA through `error_page 404 /index.html`, which is an
// internal redirect and so re-enters location matching here. Without this block
// they would inherit the server-level headers and come back indexable, while
// Symfony (the Apache deployment) marks the same 404s noindex. The blocks above
// serve /index.html as a file rather than a URI, so they are unaffected.
const notFoundBlock = `location = /index.html {
    include /etc/nginx/snippets/security-headers.conf;
    add_header X-Robots-Tag "noindex, follow" always;
    try_files /index.html =404;
}
`;

const output = `# Generated from backend/config/frontend-routes.json.
# Do not maintain a second route list here; rebuild the image after editing the manifest.
location = / {
    try_files /index.html =404;
}

${indexableLocations}
${indexableLocations ? "\n" : ""}${noindexBlock}${noindexBlock ? "\n" : ""}${notFoundBlock}`;

if (outputPath) {
  writeFileSync(outputPath, output);
  console.log(`Generated ${outputPath}`);
} else if (process.argv.includes("--check")) {
  // Validation only. Discarding the config here rather than through a shell
  // redirect keeps `npm run check:routes` working on Windows too.
  console.log(
    `Frontend route manifest is valid: ${indexable.length} indexable, `
    + `${noindex.length} noindex, ${patterns.length} noindex patterns.`,
  );
} else {
  process.stdout.write(output);
}
