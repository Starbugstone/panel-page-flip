ARG NODE_VERSION=22
ARG NGINX_VERSION=1.25 # Declare NGINX_VERSION globally

# Stage 1: Build the React application
FROM node:${NODE_VERSION}-alpine AS builder

WORKDIR /app

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

# Stage 2: Serve the application with Nginx
# ARG NGINX_VERSION is now declared globally
FROM nginx:${NGINX_VERSION}-alpine

# Copy the built static files from the builder stage to Nginx's web root
COPY --from=builder /app/dist /usr/share/nginx/html

# Copy the Nginx configuration file
# This file (nginx.conf) should be in the same directory as this Dockerfile
COPY ./docker/nginx_frontend/nginx.conf /etc/nginx/conf.d/default.conf

# Expose port 80 for Nginx
EXPOSE 80

# Start Nginx
CMD ["nginx", "-g", "daemon off;"]
