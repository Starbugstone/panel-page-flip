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
}));
