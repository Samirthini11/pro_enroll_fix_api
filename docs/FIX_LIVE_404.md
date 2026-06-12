# Fix live 404 — `http://98.93.105.128/pro_enroll_api/v1/...`

## Problem

Postman returns:

```text
404 Not Found
Apache/2.4.66 (Ubuntu) Server at 98.93.105.128 Port 80
```

This means **Apache is not routing** `/v1/auth/otp/send` to PHP `index.php`.  
The Flutter app and Postman URLs are correct — the **server** must be configured.

---

## Fix (SSH into VPS)

### Step 1 — Connect

```bash
ssh your_user@98.93.105.128
```

### Step 2 — Upload latest code

Upload the full `pro_enroll_api` folder to:

```text
/var/www/html/pro_enroll_api
```

Must include: `public/`, `src/`, `vendor/` (or run composer), `.htaccess`, `deploy/`

### Step 3 — Run fix script

```bash
cd /var/www/html/pro_enroll_api
sudo bash deploy/ubuntu-fix-404.sh
```

### Step 4 — Manual fix (if no script)

```bash
sudo a2enmod rewrite headers
sudo cp /var/www/html/pro_enroll_api/deploy/apache-pro-enroll.conf /etc/apache2/conf-available/pro-enroll.conf
sudo a2enconf pro-enroll
cd /var/www/html/pro_enroll_api
cp .env.remote .env
composer install --no-dev
sudo systemctl reload apache2
```

### Step 5 — Verify on server

```bash
curl -s http://127.0.0.1/pro_enroll_api/v1/screens/splash
```

Expected: JSON with `"success": true`

```bash
curl -s -X POST http://127.0.0.1/pro_enroll_api/v1/auth/otp/send \
  -H "Content-Type: application/json" \
  -d '{"phone_e164":"+919688720474","mode":"sign_up"}'
```

Expected: JSON with `"success": true` and `request_id`

---

## Postman (after fix)

| Setting | Value |
|---------|-------|
| Environment | **Pro-Enroll Live** |
| `base_url` | `http://98.93.105.128/pro_enroll_api` |
| URL | `POST {{base_url}}/v1/auth/otp/send` |

Body:

```json
{
  "phone_e164": "+919688720474",
  "mode": "sign_up"
}
```

---

## Why 404 happens

| URL | What Apache does now | What it should do |
|-----|----------------------|-------------------|
| `/pro_enroll_api/` | Shows directory listing | Route to PHP or hide listing |
| `/pro_enroll_api/v1/...` | **404** — no file exists | Rewrite to `public/index.php` |

The fix maps `/pro_enroll_api` → `public/` and enables `.htaccess` rewrite rules.

---

## Still failing?

| Check | Command |
|-------|---------|
| Apache config | `sudo apache2ctl -S` |
| Rewrite enabled | `apache2ctl -M \| grep rewrite` |
| .htaccess exists | `ls -la /var/www/html/pro_enroll_api/public/.htaccess` |
| PHP errors | `tail -f /var/log/apache2/error.log` |
| DB | Import `database/schema.sql` in phpMyAdmin |

See also: [`DEPLOY_VPS.md`](../DEPLOY_VPS.md)
