import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import { readFileSync } from "node:fs";
import path from "path";
import { fileURLToPath } from "node:url";
import { componentTagger } from "lovable-tagger";

const configDir = path.dirname(fileURLToPath(import.meta.url));
const routesFile = process.env.FRONTEND_ROUTES_FILE
  || path.resolve(configDir, "../backend/config/frontend-routes.json");
const frontendRoutes = JSON.parse(readFileSync(routesFile, "utf8"));

function normaliseAppUrl(value) {
  const url = new URL(value || "http://localhost:8080");
  if (url.username || url.password || url.search || url.hash || (url.pathname !== "/" && url.pathname !== "")) {
    throw new Error("APP_URL must be an origin without credentials, a path, query parameters, or a fragment.");
  }
  return url.origin;
}

const escapeXml = (value) => value
  .replaceAll("&", "&amp;")
  .replaceAll("<", "&lt;")
  .replaceAll(">", "&gt;")
  .replaceAll('"', "&quot;")
  .replaceAll("'", "&apos;");

function seoAssets(appUrl) {
  const sitemapUrls = frontendRoutes.indexable.map(({ path: routePath, changefreq, priority }) => `  <url>
    <loc>${escapeXml(`${appUrl}${routePath === "/" ? "/" : routePath}`)}</loc>
    <changefreq>${changefreq}</changefreq>
    <priority>${priority}</priority>
  </url>`).join("\n");

  return {
    name: "panel-page-flip-seo-assets",
    transformIndexHtml(html) {
      return html.replaceAll("__APP_URL__", appUrl);
    },
    generateBundle() {
      this.emitFile({
        type: "asset",
        fileName: "sitemap.xml",
        source: `<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n${sitemapUrls}\n</urlset>\n`,
      });
      this.emitFile({
        type: "asset",
        fileName: "robots.txt",
        source: `User-agent: *\nAllow: /\n\nSitemap: ${appUrl}/sitemap.xml\n`,
      });
    },
  };
}

// https://vitejs.dev/config/
export default defineConfig(({ mode }) => {
  const appUrl = normaliseAppUrl(process.env.APP_URL);

  return {
  // Expose the same public origin the SEO build embeds in index.html / sitemap.
  define: {
    "import.meta.env.VITE_APP_URL": JSON.stringify(appUrl),
  },
  server: {
    host: "0.0.0.0", // Ensure accessible within Docker, aligns with docker-compose command arg
    port: 3000, // Align with docker-compose.yml port mapping and README
    proxy: {
      '/api': {
        target: 'http://nginx',
        changeOrigin: true,
        // secure: false, // Uncomment if Nginx SSL is self-signed in Docker
        // rewrite: (path) => path.replace(/^\/api/, '') // Uncomment if your backend API routes don't have /api prefix
      }
    }
  },
  plugins: [
    react(),
    seoAssets(appUrl),
    // Only use lovable-tagger in development mode
    ...(mode === 'development' ? [componentTagger()] : [])
  ],
  resolve: {
    alias: {
      "@": path.resolve(configDir, "./src"),
    },
  },
  build: {
    rolldownOptions: {
      output: {
        codeSplitting: {
          groups: [
            {
              name: "ui-vendor",
              test: /node_modules[\\/](@radix-ui|lucide-react)[\\/]/,
              priority: 3,
            },
            {
              name: "query-vendor",
              test: /node_modules[\\/]@tanstack[\\/]/,
              priority: 3,
            },
            {
              name: "react-vendor",
              test: /node_modules[\\/](react|react-dom|react-router|react-router-dom)[\\/]/,
              priority: 2,
            },
            {
              name: "vendor",
              test: /node_modules[\\/]/,
              priority: 1,
              maxSize: 450 * 1024,
            },
          ],
        },
      },
    },
  },
  test: {
    // No implicit globals. Every test imports describe/it/expect by name, and
    // adding component tests is not a reason for that to stop being true.
    globals: false,
    // Split by extension, because the two kinds of test genuinely differ and
    // the file name should say which one you are writing:
    //
    //   *.test.js   pure logic — no DOM, so no jsdom to stand up
    //   *.test.jsx  a rendered component — jsdom, plus the Radix stubs
    //
    // Standing a DOM up for the helper tests would cost seconds per file to
    // give them something none of them touch.
    projects: [
      {
        extends: true,
        test: {
          name: "unit",
          environment: "node",
          include: ["src/**/*.test.js"],
        },
      },
      {
        extends: true,
        test: {
          name: "dom",
          environment: "jsdom",
          include: ["src/**/*.test.jsx"],
          setupFiles: ["./src/test/setup.js"],
          css: false,
        },
      },
    ],
  },
  };
});
