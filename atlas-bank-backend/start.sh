#!/usr/bin/env bash
# Render Start Command for Atlas Bank PHP Backend
# This script starts PHP's built-in web server with router.php as the front controller
# This approach works regardless of whether Render uses Apache or nginx

echo "=== Atlas Bank Backend Starting ==="
echo "PHP version: $(php -v 2>/dev/null | head -1)"
echo "Checking required extensions..."

# Check for required PHP extensions
php -m 2>/dev/null | grep -q 'pdo_pgsql' && echo "✅ pdo_pgsql: installed" || echo "⚠️  pdo_pgsql: NOT FOUND"
php -m 2>/dev/null | grep -q 'pdo' && echo "✅ pdo: installed" || echo "⚠️  pdo: NOT FOUND"
php -m 2>/dev/null | grep -q 'json' && echo "✅ json: installed" || echo "⚠️  json: NOT FOUND"
php -m 2>/dev/null | grep -q 'mbstring' && echo "✅ mbstring: installed" || echo "⚠️  mbstring: NOT FOUND"
php -m 2>/dev/null | grep -q 'openssl' && echo "✅ openssl: installed" || echo "⚠️  openssl: NOT FOUND"
php -m 2>/dev/null | grep -q 'session' && echo "✅ session: installed" || echo "⚠️  session: NOT FOUND"

echo ""
echo "Starting PHP built-in server on port ${PORT:-10000}..."
echo "Document root: $(pwd)"
echo "Router: router.php"

# PHP's built-in server with router.php handles all /api/* routing
# The router.php file already handles URL parsing, CORS, security headers, etc.
exec php -S 0.0.0.0:${PORT:-10000} router.php
