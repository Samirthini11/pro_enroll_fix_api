# Deploy Pro-Enroll API on Ubuntu VPS (98.93.105.128)

Fixes:
- Directory listing at `/pro_enroll_api/`
- 404 on `/pro_enroll_api/v1/...`
- CORS errors from Flutter web
- 500 from missing `vendor/` or `.env`

## 1. Upload latest code

Upload the full `pro_enroll_api` folder to:

```text
/var/www/html/pro_enroll_api
```

Include: `src/`, `public/`, `config/`, `database/`, `.htaccess`, `index.php`, `composer.json`

Do **not** upload `.env` from your PC if it has local passwords — create it on the server (step 3).

## 2. SSH into the server

```bash
ssh user@98.93.105.128
cd /var/www/html/pro_enroll_api
```

## 3. Environment + dependencies

```bash
cp .env.remote .env
# Edit DB_PASS if MySQL root has a password:
nano .env

composer install --no-dev --optimize-autoloader
```

Upload Firebase service account JSON to:

```text
config/firebase-service-account.json
```

## 4. MySQL (phpMyAdmin)

Open: http://98.93.105.128/phpmyadmin/

1. Create database `pro_enroll` (utf8mb4_unicode_ci)
2. Import `database/schema.sql`
3. **Create API user** (Ubuntu MySQL often blocks `root` from PHP — use `proadmin`):

```sql
CREATE USER IF NOT EXISTS 'proadmin'@'localhost' IDENTIFIED BY 'Krishna@123';
GRANT ALL PRIVILEGES ON pro_enroll.* TO 'proadmin'@'localhost';
FLUSH PRIVILEGES;
```

If the user already exists with a wrong password:

```sql
ALTER USER 'proadmin'@'localhost' IDENTIFIED BY 'Krishna@123';
FLUSH PRIVILEGES;
```

`.env` on server (must match the MySQL user above):

```env
APP_URL=http://98.93.105.128/pro_enroll_api
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=pro_enroll
DB_USER=proadmin
DB_PASS=Krishna@123
FIREBASE_CREDENTIALS=config/firebase-service-account.json
FIREBASE_PROJECT_ID=proenroll-4ff13
```

**Do not** set `APP_URL` to `.../pro_enroll_api/public` — use the base path only.

## 5. One-command server setup (required)

SSH into the VPS and run **once** after every code upload:

```bash
cd /var/www/html/pro_enroll_api
sudo bash deploy/ubuntu-setup.sh
```

This script:
- Installs PHP + Composer (if missing)
- Configures Apache (`Alias`, rewrite `/v1/*` → `index.php`, CORS)
- Creates `.env` from `.env.remote`
- Runs `composer install` + `composer dump-autoload -o` (endpoint classmap)
- Reloads Apache and runs smoke tests

Shortcut (same script):

```bash
sudo bash scripts/vps-fix.sh
```

Import `database/setup_mysql_user.sql` in phpMyAdmin if `db` is still `FAIL`.

### Manual Apache only (if needed)

```bash
sudo a2enmod rewrite headers
sudo cp deploy/apache-pro-enroll.conf /etc/apache2/conf-available/pro-enroll.conf
sudo a2enconf pro-enroll
sudo systemctl reload apache2
```

## 6. Verify

| URL | Expected |
|-----|----------|
| http://98.93.105.128/pro_enroll_api/ping.php | JSON with `"ok": true` |
| http://98.93.105.128/pro_enroll_api/v1/screens/splash | JSON `"success": true` |
| http://98.93.105.128/pro_enroll_api/v1/health/push | JSON `"fcm_http_v1_ready": true` |
| Push test (after login) | `POST /v1/device/push-test` with Bearer token |
| Android + iOS push | FCM sends both Android (`proconnect_alerts`) and APNs configs. iOS needs APNs Auth Key in Firebase Console |

Quick test:

```bash
curl -s http://98.93.105.128/pro_enroll_api/v1/screens/splash
curl -s http://98.93.105.128/pro_enroll_api/ping.php
curl -s http://98.93.105.128/pro_enroll_api/v1/health/push
curl -s -X POST http://98.93.105.128/pro_enroll_api/v1/device/push-test \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"title":"Test","body":"Hello"}'
```

## 7. Flutter app (live API)

After verify passes:

```bash
flutter run -d chrome --dart-define=USE_LIVE_API=true
flutter build apk --release
```

Live base URL: `http://98.93.105.128/pro_enroll_api`

## Troubleshooting

| Symptom | Fix |
|---------|-----|
| Index of /pro_enroll_api | Run step 5; ensure `.htaccess` and `index.php` are uploaded |
| 404 on /v1/... | Enable `mod_rewrite`; apply Apache conf; check `public/.htaccess` exists |
| 503 missing_vendor | `composer install` in project root |
| db FAIL Access denied proadmin | Import `database/setup_mysql_user.sql` in phpMyAdmin |
| app_url contains /public | `APP_URL=http://98.93.105.128/pro_enroll_api` (no `/public`) |
| SplashScreen not found | Upload `api/` folder; run `composer dump-autoload -o` |
| 404 on /v1/... | Upload root `.htaccess`; enable Apache rewrite (step 5) |
| CORS error in Chrome | Headers in `public/.htaccess` + `public/index.php`; reload Apache |
| 500 on all URLs (even README.md) | Broken `.htaccess` — remove `Options` from `.htaccess`; run `deploy/ubuntu-fix-404.sh` |
| 500 on `/pro_enroll_api/public/ping.php` | Wrong URL — use `/pro_enroll_api/ping.php` (no `/public/`; Apache Alias already maps to `public/`) |
| 500 HTML on `/v1/*` (index.php works) | Run `sudo bash deploy/ubuntu-fix-404.sh` (Apache rewrite + `AllowOverride`) |
| 500 JSON `Class …Endpoint not found` | Run `composer dump-autoload -o` after upload (`api/*` uses classmap in `composer.json`) |
| OTP verify: `JWT_SECRET is not configured` | Add `JWT_SECRET` to server `.env` — run `openssl rand -hex 32` or `sudo bash deploy/ubuntu-setup.sh` |
| 500 on `/v1/auth/otp/send` | Use **POST** + JSON body; deploy latest code; `composer install` on server |
| 500 on admin login (`server_error`) | Upload `src/Endpoints/Auth/AdminLoginEndpoint.php`, `src/Endpoints/Admin/*`, `src/Services/Admin*.php`, `src/Middleware/AdminMiddleware.php`; run `composer dump-autoload -o`; add `ADMIN_*` to server `.env` |
