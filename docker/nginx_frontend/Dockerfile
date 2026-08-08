ARG NODE_VERSION=22
ARG NGINX_VERSION=1.25 # Declare NGINX_VERSION globally

# Stage 1: Build the React application
FROM node:${NODE_VERSION}-alpine AS builder

WORKDIR /app

ARG APP_URL=http://localhost:8080
ENV APP_URL=${APP_URL}
ENV FRONTEND_ROUTES_FILE=/backend/config/frontend-routes.json

COPY scripts/generate-nginx-routes.mjs /scripts/generate-nginx-routes.mjs
COPY backend/config/frontend-routes.json /backend/config/frontend-routes.json

# Copy frontend's package.json and lock file to /app/
COPY frontend/package.json frontend/package-lock.json* ./

# Reproduce the exact dependency graph from the lock file.
RUN npm ci --ignore-scripts --no-audit --no-fund

# Copy the entire frontend directory contents to /app/
# This will place src, public, vite.config.js, etc., directly into /app/
COPY frontend/. .

# Build the application
# This will create the 'dist' folder (default for Vite)
RUN npm run build

# Generate route handling only after Vite has produced the exact HTML Nginx
# will serve. This makes the sub_filter source validation meaningful even if a
# future Vite version rewrites or minifies the metadata tags.
RUN node /scripts/generate-nginx-routes.mjs \
      --manifest "$FRONTEND_ROUTES_FILE" \
      --index-html /app/dist/index.html \
      --output /tmp/spa-routes.conf

# Stage 2: Serve the application with Nginx
# ARG NGINX_VERSION is now declared globally
FROM nginx:${NGINX_VERSION}-alpine

# Copy the built static files from the builder stage to Nginx's web root
COPY --from=builder /app/dist /usr/share/nginx/html

# Copy the Nginx configuration file
# This file (nginx.conf) should be in the same directory as this Dockerfile
COPY ./docker/nginx_frontend/nginx.conf /etc/nginx/conf.d/default.conf
# Included by default.conf from the server block and from each location that
# adds a header of its own; nginx would fail to start without it. Kept out of
# conf.d, which the base image globs into the http block — landing there would
# apply these a second time at http level.
COPY ./docker/nginx_frontend/security-headers.conf /etc/nginx/snippets/security-headers.conf
COPY --from=builder /tmp/spa-routes.conf /etc/nginx/snippets/spa-routes.conf

# Expose port 80 for Nginx
EXPOSE 80

# Start Nginx
CMD ["nginx", "-g", "daemon off;"]
