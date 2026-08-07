import { defineConfig } from "vite";
import react from "@vitejs/plugin-react-swc";
import path from "path";
import { componentTagger } from "lovable-tagger";

// https://vitejs.dev/config/
export default defineConfig(({ mode }) => ({
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
    // Only use lovable-tagger in development mode
    ...(mode === 'development' ? [componentTagger()] : [])
  ],
  resolve: {
    alias: {
      "@": path.resolve(__dirname, "./src"),
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
}));
