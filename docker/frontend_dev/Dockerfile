ARG NODE_VERSION=22
FROM node:${NODE_VERSION}-alpine

# Vite runs against a bind mount of ./frontend, so it writes package-lock.json
# updates and any generated file straight into the host checkout. Running as
# root leaves those root-owned and unfixable from the host.
ARG HOST_UID=1000
ARG HOST_GID=1000

# node:alpine already ships a `node` user at 1000:1000, so the usual case is a
# rename rather than a create. Both branches end at a user literally named dev.
RUN if getent group ${HOST_GID} >/dev/null; then \
      apk add --no-cache shadow >/dev/null && \
      groupmod -n dev "$(getent group ${HOST_GID} | cut -d: -f1)"; \
    else \
      addgroup -g ${HOST_GID} dev; \
    fi \
 && if getent passwd ${HOST_UID} >/dev/null; then \
      apk add --no-cache shadow >/dev/null && \
      usermod -l dev -d /home/dev -m -g dev "$(getent passwd ${HOST_UID} | cut -d: -f1)"; \
    else \
      adduser -u ${HOST_UID} -G dev -h /home/dev -s /bin/sh -D dev; \
    fi

# node_modules is masked by an anonymous volume in docker-compose.yml, and a
# volume takes its ownership from the image path it shadows. Creating the
# directory here owned by dev is what stops that volume being created root-owned
# and `npm install` failing on the first start.
RUN mkdir -p /app/node_modules /home/dev/.npm \
 && chown -R dev:dev /app /home/dev

ENV npm_config_cache=/home/dev/.npm
ENV HOME=/home/dev
WORKDIR /app
USER dev
