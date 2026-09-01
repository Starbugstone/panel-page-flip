ARG PHP_VERSION=8.2

FROM php:${PHP_VERSION}-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libjpeg62-turbo-dev \
    libwebp-dev \
    libfreetype6-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    p7zip-full \
    poppler-utils \
    qpdf \
    libzip-dev \
    libicu-dev \
    libxslt-dev \
    acl

# Install PHP extensions
#
# GD is configured explicitly rather than taking the defaults: without
# --with-jpeg and --with-webp it builds without JPEG or WebP support, which
# would leave the page pipeline unable to read the format comics are actually
# stored in or write the one they are delivered as.
RUN docker-php-ext-configure intl \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        mysqli \
        intl \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        zip \
        opcache \
        xsl

# Coverage is disabled for normal requests and enabled explicitly by the test
# command. Keeping the driver in the image makes CI and local measurements use
# the same instrumentation instead of silently skipping the gate.
RUN pecl install pcov-1.0.12 \
    && docker-php-ext-enable pcov \
    && echo "pcov.enabled=0" > /usr/local/etc/php/conf.d/zz-pcov.ini

# Create log directory for PHP
RUN mkdir -p /var/log/php && chmod 777 /var/log/php

# Copy custom PHP configuration
COPY custom.ini /usr/local/etc/php/conf.d/

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

# Set working directory
WORKDIR /var/www/html

# Install Symfony CLI
RUN curl -1sLf 'https://dl.cloudsmith.io/public/symfony/stable/setup.deb.sh' | bash \
    && apt-get install -y symfony-cli

# The container runs as the host developer's UID/GID so that everything it
# writes into the bind-mounted source tree — vendor/, var/, public/uploads/,
# .phpunit.cache/ — is owned by them and not by root. Getting this wrong is not
# cosmetic: root-owned build output makes the next `composer install`, cache
# clear or `git clean` fail from the host with permission errors that read as
# application bugs.
ARG HOST_UID=1000
ARG HOST_GID=1000
RUN if getent group ${HOST_GID} >/dev/null; then \
      groupmod -n www "$(getent group ${HOST_GID} | cut -d: -f1)"; \
    else \
      groupadd -g ${HOST_GID} www; \
    fi \
 && if getent passwd ${HOST_UID} >/dev/null; then \
      usermod -l www -d /home/www -m -g www "$(getent passwd ${HOST_UID} | cut -d: -f1)"; \
    else \
      useradd -u ${HOST_UID} -ms /bin/bash -g www www; \
    fi

# php-fpm's pool user. Honoured when the master starts as root; ignored with a
# notice when the container already runs as www, by which point it is redundant.
COPY www-pool.conf /usr/local/etc/php-fpm.d/zz-www-pool.conf

# Composer and the Symfony CLI both need a writable HOME. Without it they fall
# back to / and every command opens with a cache warning.
ENV COMPOSER_HOME=/home/www/.composer
ENV HOME=/home/www

# Create Symfony directory structure. The ownership set here is what a fresh
# php_cache volume inherits, so it must match the user the container runs as.
RUN mkdir -p /var/www/html/var/cache /var/www/html/var/log /home/www/.composer \
    && chown -R www:www /var/www/html /home/www

# Expose port 9000
EXPOSE 9000

# Start PHP-FPM server
CMD ["php-fpm"]
