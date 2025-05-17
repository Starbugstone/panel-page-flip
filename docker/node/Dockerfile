ARG NODE_VERSION=18

FROM node:${NODE_VERSION}-alpine

WORKDIR /app

# Install dependencies for node-gyp
RUN apk add --no-cache python3 make g++

# Set environment variables
ENV NODE_ENV=development
ENV PATH /app/node_modules/.bin:$PATH

# Expose port for development server
EXPOSE 3000

# Start development server
CMD ["sh", "-c", "npm install && npm run dev -- --host 0.0.0.0"]
