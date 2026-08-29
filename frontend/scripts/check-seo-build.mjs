import { readFileSync } from "node:fs";
import { resolve } from "node:path";

import { landingCopy, landingPhrases } from "../src/lib/landing-copy.js";

const frontendDir = resolve(import.meta.dirname, "..");
// Same resolution order as vite.config.js and scripts/generate-nginx-routes.mjs,
// so a container that relocates the manifest only has to set it in one place.
const routesFile = process.env.FRONTEND_ROUTES_FILE
  || resolve(frontendDir, "../backend/config/frontend-routes.json");
const routes = JSON.parse(readFileSync(routesFile, "utf8"));
const index = readFileSync(resolve(frontendDir, "dist/index.html"), "utf8");
const sitemap = readFileSync(resolve(frontendDir, "dist/sitemap.xml"), "utf8");
const robots = readFileSync(resolve(frontendDir, "dist/robots.txt"), "utf8");
const appUrl = (process.env.APP_URL || "http://localhost:8080").replace(/\/$/, "");

if (index.includes("__APP_URL__")) throw new Error("The production index still contains the APP_URL placeholder.");
if (!index.includes(`<link rel="canonical" href="${appUrl}/" />`)) throw new Error("The landing canonical does not use APP_URL.");
if (!index.includes(`<meta property="og:url" content="${appUrl}/" />`)) throw new Error("The landing Open Graph URL does not use APP_URL.");
if (!robots.includes(`Sitemap: ${appUrl}/sitemap.xml`)) throw new Error("robots.txt does not advertise the generated sitemap.");

const locations = [...sitemap.matchAll(/<loc>([^<]+)<\/loc>/g)].map((match) => match[1]);
const expected = routes.indexable.map(({ path }) => `${appUrl}${path === "/" ? "/" : path}`);
if (JSON.stringify(locations) !== JSON.stringify(expected)) {
  throw new Error(`Sitemap locations differ from the route manifest: ${JSON.stringify(locations)}`);
}

const missingLandingCopy = landingPhrases(landingCopy).filter((phrase) => !index.includes(phrase));
if (missingLandingCopy.length > 0) {
  throw new Error(
    `The built index is missing crawlable landing copy: ${JSON.stringify(missingLandingCopy)}`,
  );
}

console.log(`SEO build metadata is complete for ${locations.length} indexable routes.`);
