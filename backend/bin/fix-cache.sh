#!/bin/bash

# Fix Symfony cache issues
# This script resolves the common "Failed to remove directory" cache errors

echo "🔧 Fixing Symfony cache issues..."

# Stop any running processes that might be using cache files
echo "📋 Checking for running PHP processes..."
if command -v pkill &> /dev/null; then
    pkill -f "php bin/console" 2>/dev/null || true
fi

# Force remove cache directories
echo "🗑️  Removing cache directories..."
if [ -d "var/cache" ]; then
    # Try normal removal first
    rm -rf var/cache/* 2>/dev/null || {
        echo "⚠️  Normal removal failed, trying force removal..."
        
        # Force removal with different approaches
        find var/cache -type f -delete 2>/dev/null || true
        find var/cache -type d -empty -delete 2>/dev/null || true
        
        # If still failing, try with sudo (for production)
        if [ -d "var/cache" ] && [ "$(ls -A var/cache 2>/dev/null)" ]; then
            echo "🔐 Trying with elevated permissions..."
            sudo rm -rf var/cache/* 2>/dev/null || true
        fi
    }
fi

# Recreate cache directory structure
echo "📁 Recreating cache directory structure..."
mkdir -p var/cache/dev
mkdir -p var/cache/prod
mkdir -p var/log

# Set proper permissions
echo "🔒 Setting proper permissions..."
if command -v chown &> /dev/null; then
    # For production servers
    chown -R www-data:www-data var/ 2>/dev/null || true
fi

chmod -R 755 var/ 2>/dev/null || true
chmod -R 777 var/cache/ 2>/dev/null || true
chmod -R 777 var/log/ 2>/dev/null || true

# Clear cache using Symfony
echo "🧹 Clearing Symfony cache..."
php bin/console cache:clear --no-warmup 2>/dev/null || {
    echo "⚠️  Cache clear failed, trying environment-specific clear..."
    php bin/console cache:clear --env=dev --no-warmup 2>/dev/null || true
    php bin/console cache:clear --env=prod --no-warmup 2>/dev/null || true
}

# Warm up cache
echo "🔥 Warming up cache..."
php bin/console cache:warmup 2>/dev/null || {
    echo "⚠️  Cache warmup failed, but cache has been cleared"
}

echo "✅ Cache fix completed!"
echo ""
echo "💡 If this issue keeps happening, consider:"
echo "   - Checking disk space: df -h"
echo "   - Checking file permissions: ls -la var/"
echo "   - Running this script regularly: ./bin/fix-cache.sh"
echo "   - Adding to crontab for automatic cleanup" 