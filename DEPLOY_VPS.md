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

`.env` on server:

```env
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=pro_enroll
DB_USER=root
DB_PASS=
APP_URL=http://98.93.105.128/pro_enroll_api
```

## 5. Apache (required)

```bash
sudo a2enmod rewrite headers
sudo cp deploy/apache-pro-enroll.conf /etc/apache2/conf-available/pro-enroll.conf
sudo a2enconf pro-enroll
sudo systemctl reload apache2
```

If `AllowOverride` is still off, the **Alias** in `deploy/apache-pro-enroll.conf` maps
`/pro_enroll_api` → `public/` so `/v1/*` routes work.

## 6. Verify

| URL | Expected |
|-----|----------|
| http://98.93.105.128/pro_enroll_api/public/ping.php | JSON with `"ok": true` |
| http://98.93.105.128/pro_enroll_api/v1/screens/splash | JSON `"success": true` |
| http://98.93.105.128/pro_enroll_api/test_connection.php | `"db": "OK"` |

Quick test:

```bash
curl -s http://98.93.105.128/pro_enroll_api/v1/screens/splash
curl -s http://98.93.105.128/pro_enroll_api/public/ping.php
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
| db FAIL / infinityfree host | Replace server `.env` with `.env.remote` (local MySQL 127.0.0.1) |
| CORS error in Chrome | Headers in `public/.htaccess` + `public/index.php`; reload Apache |
