ARG NGINX_VERSION=1.25

FROM nginx:${NGINX_VERSION}

WORKDIR /var/www/html

# Create directory for Symfony logs
RUN mkdir -p /var/log/nginx

CMD ["nginx", "-g", "daemon off;"]
