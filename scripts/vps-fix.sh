#!/bin/bash
# Run on VPS after git pull: bash scripts/vps-fix.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

echo "==> Pro-Enroll API VPS fix"

if [ ! -f .env ]; then
  cp .env.remote .env
  echo "Created .env from .env.remote"
fi

# APP_URL must not end with /public
if grep -q 'APP_URL=.*/public' .env 2>/dev/null; then
  sed -i 's|APP_URL=\(.*\)/public|APP_URL=\1|' .env
  echo "Fixed APP_URL (removed /public)"
fi

if command -v composer >/dev/null 2>&1; then
  composer install --no-dev --optimize-autoloader
  composer dump-autoload -o
  echo "Composer dependencies OK"
else
  echo "WARN: composer not found — install it, then run: composer install --no-dev"
fi

echo ""
echo "Next: import database/setup_mysql_user.sql in phpMyAdmin if db FAIL"
echo "Test: curl -s http://98.93.105.128/pro_enroll_api/public/ping.php"
