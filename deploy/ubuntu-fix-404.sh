#!/bin/bash
# Fix 404 on http://98.93.105.128/pro_enroll_api/v1/...
# Run on Ubuntu VPS as root or with sudo:
#   cd /var/www/html/pro_enroll_api && sudo bash deploy/ubuntu-fix-404.sh

set -e

API_ROOT="/var/www/html/pro_enroll_api"
APACHE_CONF="/etc/apache2/conf-available/pro-enroll.conf"

echo "=== Pro-Enroll API — fix Apache 404 ==="
echo "API root: $API_ROOT"

if [ ! -d "$API_ROOT/public" ]; then
  echo "ERROR: $API_ROOT/public not found. Upload pro_enroll_api first."
  exit 1
fi

# 1. Apache modules
echo "[1/6] Enable rewrite + headers..."
a2enmod rewrite headers 2>/dev/null || true

# 2. Apache config — map /pro_enroll_api → public/
echo "[2/6] Install Apache config..."
cat > "$APACHE_CONF" << 'EOF'
# Pro-Enroll API — /pro_enroll_api → public/
Alias /pro_enroll_api /var/www/html/pro_enroll_api/public

<Directory /var/www/html/pro_enroll_api/public>
    Options -Indexes +FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>

<Directory /var/www/html/pro_enroll_api>
    Options -Indexes +FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
EOF
a2enconf pro-enroll 2>/dev/null || true

# 3. .env
echo "[3/6] Setup .env..."
if [ -f "$API_ROOT/.env.remote" ] && [ ! -f "$API_ROOT/.env" ]; then
  cp "$API_ROOT/.env.remote" "$API_ROOT/.env"
fi

# 4. Composer
echo "[4/6] composer install..."
cd "$API_ROOT"
if command -v composer >/dev/null 2>&1; then
  composer install --no-dev --optimize-autoloader
else
  echo "WARN: composer not found. Install: apt install composer"
fi

# 5. Permissions
echo "[5/6] Permissions..."
chown -R www-data:www-data "$API_ROOT/storage" 2>/dev/null || true
chmod -R 755 "$API_ROOT/public"

# 6. Reload Apache
echo "[6/6] Reload Apache..."
apache2ctl configtest
systemctl reload apache2

echo ""
echo "=== Test these URLs ==="
echo "curl -s http://127.0.0.1/pro_enroll_api/public/ping.php"
echo "curl -s http://127.0.0.1/pro_enroll_api/v1/screens/splash"
echo "curl -s -X POST http://127.0.0.1/pro_enroll_api/v1/auth/otp/send \\"
echo "  -H 'Content-Type: application/json' \\"
echo "  -d '{\"phone_e164\":\"+919688720474\",\"mode\":\"sign_up\"}'"
echo ""
echo "Postman base_url: http://98.93.105.128/pro_enroll_api"
