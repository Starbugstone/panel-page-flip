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

# Configure non-root user
RUN groupadd -g 1000 www && \
    useradd -u 1000 -ms /bin/bash -g www www

# Create Symfony directory structure
RUN mkdir -p /var/www/html/var/cache /var/www/html/var/log \
    && chown -R www:www /var/www/html

# Expose port 9000
EXPOSE 9000

# Start PHP-FPM server
CMD ["php-fpm"]
