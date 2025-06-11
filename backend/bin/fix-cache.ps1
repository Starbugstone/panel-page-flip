# Fix Symfony cache issues on Windows
# This script resolves the common "Failed to remove directory" cache errors

Write-Host "🔧 Fixing Symfony cache issues..." -ForegroundColor Green

# Stop any running PHP processes that might be using cache files
Write-Host "📋 Checking for running PHP processes..." -ForegroundColor Yellow
try {
    Get-Process -Name "php" -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue
} catch {
    # Ignore errors if no PHP processes found
}

# Force remove cache directories
Write-Host "🗑️  Removing cache directories..." -ForegroundColor Yellow
if (Test-Path "var/cache") {
    try {
        # Try normal removal first
        Remove-Item -Recurse -Force "var/cache/*" -ErrorAction Stop
        Write-Host "✅ Cache removed successfully" -ForegroundColor Green
    } catch {
        Write-Host "⚠️  Normal removal failed, trying alternative methods..." -ForegroundColor Red
        
        # Try removing files first, then directories
        try {
            Get-ChildItem -Path "var/cache" -Recurse -File | Remove-Item -Force -ErrorAction SilentlyContinue
            Get-ChildItem -Path "var/cache" -Recurse -Directory | Remove-Item -Recurse -Force -ErrorAction SilentlyContinue
        } catch {
            Write-Host "⚠️  Some files may still be locked" -ForegroundColor Red
        }
        
        # Last resort: try with robocopy to empty directories
        try {
            $tempDir = New-TemporaryFile | ForEach-Object { Remove-Item $_; New-Item -ItemType Directory -Path $_ }
            robocopy $tempDir.FullName "var/cache" /MIR /NFL /NDL /NJH /NJS /NC /NS /NP
            Remove-Item $tempDir -Recurse -Force
        } catch {
            Write-Host "⚠️  Robocopy method failed" -ForegroundColor Red
        }
    }
}

# Recreate cache directory structure
Write-Host "📁 Recreating cache directory structure..." -ForegroundColor Yellow
New-Item -ItemType Directory -Path "var/cache/dev" -Force | Out-Null
New-Item -ItemType Directory -Path "var/cache/prod" -Force | Out-Null
New-Item -ItemType Directory -Path "var/log" -Force | Out-Null

# Clear cache using Symfony
Write-Host "🧹 Clearing Symfony cache..." -ForegroundColor Yellow
try {
    & php bin/console cache:clear --no-warmup
    Write-Host "✅ Symfony cache cleared successfully" -ForegroundColor Green
} catch {
    Write-Host "⚠️  Cache clear failed, trying environment-specific clear..." -ForegroundColor Red
    try {
        & php bin/console cache:clear --env=dev --no-warmup
    } catch {
        Write-Host "⚠️  Dev cache clear failed" -ForegroundColor Red
    }
    try {
        & php bin/console cache:clear --env=prod --no-warmup
    } catch {
        Write-Host "⚠️  Prod cache clear failed" -ForegroundColor Red
    }
}

# Warm up cache
Write-Host "🔥 Warming up cache..." -ForegroundColor Yellow
try {
    & php bin/console cache:warmup
    Write-Host "✅ Cache warmed up successfully" -ForegroundColor Green
} catch {
    Write-Host "⚠️  Cache warmup failed, but cache has been cleared" -ForegroundColor Yellow
}

Write-Host ""
Write-Host "✅ Cache fix completed!" -ForegroundColor Green
Write-Host ""
Write-Host "💡 If this issue keeps happening, consider:" -ForegroundColor Cyan
Write-Host "   - Checking disk space" -ForegroundColor White
Write-Host "   - Restarting your development server" -ForegroundColor White
Write-Host "   - Running this script regularly: .\bin\fix-cache.ps1" -ForegroundColor White
Write-Host "   - Checking for antivirus interference" -ForegroundColor White 