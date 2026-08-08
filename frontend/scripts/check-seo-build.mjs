import { readFileSync } from "node:fs";
import { resolve } from "node:path";

const frontendDir = resolve(import.meta.dirname, "..");
const routes = JSON.parse(readFileSync(resolve(frontendDir, "../backend/config/frontend-routes.json"), "utf8"));
const index = readFileSync(resolve(frontendDir, "dist/index.html"), "utf8");
const sitemap = readFileSync(resolve(frontendDir, "dist/sitemap.xml"), "utf8");
const robots = readFileSync(resolve(frontendDir, "dist/robots.txt"), "utf8");
const appUrl = (process.env.APP_URL || "http://localhost:8080").replace(/\/$/, "");

if (index.includes("__APP_URL__")) throw new Error("The production index still contains the APP_URL placeholder.");
if (!index.includes(`<link rel="canonical" href="${appUrl}/" />`)) throw new Error("The landing canonical does not use APP_URL.");
if (!robots.includes(`Sitemap: ${appUrl}/sitemap.xml`)) throw new Error("robots.txt does not advertise the generated sitemap.");

const locations = [...sitemap.matchAll(/<loc>([^<]+)<\/loc>/g)].map((match) => match[1]);
const expected = routes.indexable.map(({ path }) => `${appUrl}${path === "/" ? "/" : path}`);
if (JSON.stringify(locations) !== JSON.stringify(expected)) {
  throw new Error(`Sitemap locations differ from the route manifest: ${JSON.stringify(locations)}`);
}

console.log(`SEO build metadata is complete for ${locations.length} indexable routes.`);
