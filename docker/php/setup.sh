#!/bin/bash
set -e

# Function to check if Symfony is already installed
is_symfony_installed() {
    if [ -f /var/www/html/composer.json ] && [ -d /var/www/html/src ]; then
        return 0
    else
        return 1
    fi
}

# Change to the working directory
cd /var/www/html

# Install Symfony if not already installed
if ! is_symfony_installed; then
    echo "Creating new Symfony project..."
    
    # Create new Symfony project with webapp skeleton
    symfony new . --webapp --no-git --version=6.4
    
    # Install additional bundles
    composer require symfony/orm-pack
    composer require --dev symfony/maker-bundle
    composer require symfony/security-bundle
    composer require symfony/serializer-pack
    composer require symfony/validator
    composer require symfony/form
    composer require symfony/asset
    composer require symfony/twig-bundle
    composer require symfony/debug-bundle --dev
    composer require symfony/web-profiler-bundle --dev
    
    # Create .env.local file
    echo "APP_ENV=dev" > .env.local
    echo "APP_SECRET=$(openssl rand -hex 16)" >> .env.local
    echo "DATABASE_URL=\"mysql://${MYSQL_USER:-cbz_user}:${MYSQL_PASSWORD:-cbz_password}@database:3306/${MYSQL_DATABASE:-cbz_reader}?serverVersion=8.0\"" >> .env.local
    
    echo "Symfony project created successfully!"
else
    echo "Symfony project already exists, skipping installation."
fi

# Runtime directories must exist before PHP-FPM or a console command touches
# them. Do not take ownership of the bind-mounted source checkout: doing so
# prevents the host user from editing their own files after Docker starts.
#
# The container normally runs as the host developer's UID (see HOST_UID in the
# Dockerfile and `user:` in docker-compose.yml), so these are created owned by
# them and no chown is needed or possible. The chown below is only for the case
# where the container was started as root anyway; as a non-root user it would
# fail and take the entrypoint down with it, so it is guarded rather than
# suffixed with `|| true`, which would hide a real failure when running as root.
RUNTIME_DIRS="
  /var/www/html/var/cache
  /var/www/html/var/log
  /var/www/html/var/page-cache
  /var/www/html/var/quarantine/comics
  /var/www/html/public/uploads
  /tmp/comic_uploads
"

mkdir -p $RUNTIME_DIRS
chmod -R u+rwX,g+rwX $RUNTIME_DIRS

if [ "$(id -u)" = "0" ]; then
  chown -R www:www $RUNTIME_DIRS
fi

echo "Setup completed as uid $(id -u):$(id -g)"
