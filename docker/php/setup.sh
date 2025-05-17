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

# Set proper permissions
chown -R www:www /var/www/html

echo "Setup completed!"
