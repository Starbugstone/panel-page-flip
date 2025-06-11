# Symfony Cache Troubleshooting Guide

## The Problem

You're experiencing this error frequently:
```
Failed to remove directory "/var/www/html/var/cache/dev/ContainerVjT0hHd": rmdir(/var/www/html/var/cache/dev/.!!g3A): Directory not empty
```

This is a common Symfony issue that occurs when:
- Cache files are locked by running processes
- File permissions are incorrect
- Disk space is low
- Antivirus software interferes with file operations
- Multiple processes try to access cache simultaneously

## Quick Fixes

### For Development (Windows)
```powershell
# Navigate to backend directory
cd backend

# Run the PowerShell fix script
.\bin\fix-cache.ps1

# Or manual fix:
Remove-Item -Recurse -Force var/cache/* -ErrorAction SilentlyContinue
php bin/console cache:clear
```

### For Development (Linux/Mac)
```bash
# Navigate to backend directory
cd backend

# Run the bash fix script
./bin/fix-cache.sh

# Or manual fix:
rm -rf var/cache/*
php bin/console cache:clear
```

### For Production
```bash
# SSH into your server
ssh user@your-server.com
cd /path/to/your/project/backend

# Run the fix script
./bin/fix-cache.sh

# Or use the API endpoint (if implemented)
curl -X POST /api/deployment/cache-fix \
  -H "Authorization: Bearer your-admin-token"
```

## Prevention Strategies

### 1. Automated Cache Cleanup
Add to your deployment process:
```bash
# Before any Symfony commands
rm -rf var/cache/*
php bin/console cache:clear --no-warmup
php bin/console cache:warmup
```

### 2. Proper File Permissions
```bash
# Set correct permissions (production)
chown -R www-data:www-data var/
chmod -R 755 var/
chmod -R 777 var/cache/
chmod -R 777 var/log/
```

### 3. Development Environment
```bash
# Add to your .bashrc or .zshrc
alias sf-cache-fix="rm -rf var/cache/* && php bin/console cache:clear"

# Or create a composer script in composer.json:
{
    "scripts": {
        "cache-fix": [
            "rm -rf var/cache/*",
            "@php bin/console cache:clear"
        ]
    }
}
```

### 4. Docker Environment
If using Docker, ensure proper volume mounting:
```yaml
# docker-compose.yml
services:
  php:
    volumes:
      - ./backend:/var/www/html
      - cache_volume:/var/www/html/var/cache  # Separate volume for cache
volumes:
  cache_volume:
```

## Root Cause Analysis

### Check for Common Issues

1. **Disk Space**
   ```bash
   df -h  # Check available disk space
   ```

2. **File Permissions**
   ```bash
   ls -la var/cache/  # Check cache directory permissions
   ```

3. **Running Processes**
   ```bash
   ps aux | grep php  # Check for running PHP processes
   lsof +D var/cache/  # Check what's using cache files
   ```

4. **Antivirus Interference**
   - Exclude `var/cache/` from real-time scanning
   - Temporarily disable antivirus to test

## Integration with Rollback System

The rollback system now includes automatic cache cleanup:

1. **Before rollback**: Cache is cleared to prevent conflicts
2. **After rollback**: Cache is rebuilt with new code
3. **On failure**: Cache state is restored with backup

### Rollback with Cache Fix
```bash
# The rollback command now automatically handles cache issues
php bin/console app:rollback --reason="Cache corruption fix"
```

## Monitoring and Alerts

### Log Monitoring
Watch for cache-related errors:
```bash
# Monitor deployment logs
tail -f var/log/deployment/deployment-$(date +%Y-%m-%d).log | grep -i cache

# Monitor Symfony logs
tail -f var/log/prod.log | grep -i cache
```

### Automated Monitoring Script
```bash
#!/bin/bash
# Add to crontab: */5 * * * * /path/to/cache-monitor.sh

CACHE_SIZE=$(du -s var/cache/ | cut -f1)
MAX_SIZE=1000000  # 1GB in KB

if [ $CACHE_SIZE -gt $MAX_SIZE ]; then
    echo "Cache size exceeded limit, cleaning up..."
    ./bin/fix-cache.sh
    echo "Cache cleanup completed at $(date)" >> var/log/cache-cleanup.log
fi
```

## Emergency Procedures

### If Cache Issues Prevent Deployment
1. **SSH into production server**
2. **Run emergency cache fix**:
   ```bash
   cd /path/to/project/backend
   ./bin/fix-cache.sh
   ```
3. **Verify application is working**
4. **Check logs for any issues**

### If Rollback Fails Due to Cache
1. **Manual cache cleanup**:
   ```bash
   rm -rf var/cache/*
   mkdir -p var/cache/prod var/cache/dev
   chmod -R 777 var/cache/
   ```
2. **Retry rollback**:
   ```bash
   php bin/console app:rollback
   ```

## Best Practices

1. **Never commit cache files** - Ensure `var/cache/` is in `.gitignore`
2. **Regular cleanup** - Run cache fix scripts weekly in development
3. **Monitor disk space** - Set up alerts for low disk space
4. **Proper permissions** - Always set correct file permissions after deployment
5. **Separate cache volumes** - Use separate volumes/partitions for cache in production

## When to Seek Help

Contact the development team if:
- Cache issues persist after following this guide
- Disk space is adequate but issues continue
- File permissions are correct but problems remain
- Cache issues are causing frequent production outages

## Quick Reference Commands

```bash
# Development quick fix
rm -rf var/cache/* && php bin/console cache:clear

# Production quick fix
./bin/fix-cache.sh

# Check cache size
du -sh var/cache/

# Check permissions
ls -la var/cache/

# Emergency cache rebuild
rm -rf var/cache/* && php bin/console cache:clear --env=prod && php bin/console cache:warmup --env=prod
``` 