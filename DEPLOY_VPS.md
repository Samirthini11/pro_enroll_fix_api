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
```

**Do not** set `APP_URL` to `.../pro_enroll_api/public` — use the base path only.

## 5. Apache (required)

```bash
sudo a2enmod rewrite headers
sudo cp deploy/apache-pro-enroll.conf /etc/apache2/conf-available/pro-enroll.conf
sudo a2enconf pro-enroll
sudo systemctl reload apache2
```

If `AllowOverride` is still off, the **Alias** in `deploy/apache-pro-enroll.conf` maps
`/pro_enroll_api` → `public/` so `/v1/*` routes work.

## 6. One-command fix (SSH)

After `git pull` on the server:

```bash
cd /var/www/html/pro_enroll_api
bash scripts/vps-fix.sh
```

Import `database/setup_mysql_user.sql` in phpMyAdmin if `db` is still `FAIL`.

## 7. Verify

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

## 8. Flutter app (live API)

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
| 500 HTML on `/v1/*` (index.php works) | Apache rewrite blocked — run `sudo bash deploy/ubuntu-fix-404.sh` (enables `FallbackResource` + `AllowOverride`) |
| 500 JSON `Class …Endpoint not found` | Run `composer dump-autoload -o` after upload (`api/*` uses classmap in `composer.json`) |
| 500 on `/v1/auth/otp/send` | Use **POST** + JSON body; deploy latest code; `composer install` on server |
