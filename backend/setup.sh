#!/bin/bash

# Setup script for the CBZ Reader backend
echo "Setting up CBZ Reader backend..."

# Create database if it doesn't exist
php bin/console doctrine:database:create --if-not-exists

# Run migrations
php bin/console doctrine:migrations:migrate --no-interaction

# Set up upload directories
php bin/console app:setup-upload-directories

# Create admin user if no users exist
USER_COUNT=$(php bin/console doctrine:query:sql "SELECT COUNT(*) FROM user" --no-ansi | grep -oP '\d+')
if [ "$USER_COUNT" -eq "0" ]; then
    echo "Creating default admin user..."
    php bin/console app:create-admin-user admin@example.com password123
    echo "Default admin user created with email: admin@example.com and password: password123"
    echo "IMPORTANT: Change this password immediately after first login!"
fi

echo "Setup complete!"
