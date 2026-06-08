# Pro-Enroll API — Full Postman Reference (Live)

Complete list of **all API endpoints** for Postman testing on the live VPS.

| Setting | Value |
|---------|-------|
| **Live base URL** | `http://98.93.105.128/pro_enroll_api` |
| **Postman collection** | `Pro-Enroll-API.postman_collection.json` |
| **Live environment** | `Pro-Enroll-API-Live.postman_environment.json` |
| **Server deploy guide** | `DEPLOY_VPS.md` |

> **Apps using this API:** Pro-Enroll (professional) · Pro-Fix Customer (customer app)  
> Same server, same `base_url`. Customer auth adds `"app": "pro_fix_customer"` in OTP bodies.

---

## 1. Postman setup (live)

### Import

1. Postman → **Import**
2. Select:
   - `Pro-Enroll-API.postman_collection.json`
   - `Pro-Enroll-API-Live.postman_environment.json`
3. Top-right dropdown → **Pro-Enroll Live**

### Environment variables

| Variable | Live value | Notes |
|----------|------------|-------|
| `base_url` | `http://98.93.105.128/pro_enroll_api` | Required |
| `access_token` | *(empty)* | Auto-set after Verify OTP |
| `refresh_token` | *(empty)* | Auto-set after Verify OTP |
| `request_id` | *(empty)* | Auto-set after Send OTP |
| `offer_id` | `offer_001` | From home-jobs response |
| `pro_id` | `1` | For customer pro detail |
| `booking_id` | `1` | After create booking |

### Headers (all JSON requests)

```text
Content-Type: application/json
Accept: application/json
```

Protected routes add:

```text
Authorization: Bearer {{access_token}}
```

---

## 2. Live test order (recommended)

Run in this order. Check **Status = 200** and `"success": true` in JSON.

| # | Postman folder | Request | Auth | Expected |
|---|----------------|---------|------|----------|
| 1 | Health | Ping | — | 200, `"vendor": true` |
| 2 | Health | API Root `/v1` | — | 200, route list |
| 3 | Health | Test Connection | — | 200, `"db": "OK"` |
| 4 | Auth | Send OTP (Pro-Enroll) | — | 200, `request_id` |
| 5 | Auth | Verify OTP | — | 200, tokens saved |
| 6 | Auth | Validate | 🔒 | 200 |
| 7 | Auth | Me | 🔒 | 200 |
| 8 | Screens | Splash | — | 200 |
| 9 | Screens | Onboard Category GET | — | 200 |
| 10 | Screens | Onboard Category PUT | 🔒 | 200 |
| 11 | Screens | … onboarding / KYC … | 🔒 | 200 |
| 12 | Screens | Home Jobs | 🔒 | 200 |
| 13 | Customer | Categories | — | 200 |
| 14 | Customer | Pros Search | — | 200 |
| 15 | Auth | Send OTP (Pro-Fix Customer) | — | 200 |
| 16 | Auth | Verify OTP (Pro-Fix) | — | 200 |
| 17 | Customer | Bookings GET | 🔒 | 200 |
| 18 | Auth | Logout | 🔒 | 200 |

---

## 3. Health & diagnostics (3 endpoints)

| # | Method | Path | Live URL | Auth | Purpose | Postman |
|---|--------|------|----------|------|---------|---------|
| H1 | GET | `/v1` | `http://98.93.105.128/pro_enroll_api/v1` | — | API name, version, route list | Health → API Root |
| H2 | GET | `/public/ping.php` | `http://98.93.105.128/pro_enroll_api/public/ping.php` | — | PHP, vendor, .env, DB health | Health → Ping |
| H3 | GET | `/test_connection.php` | `http://98.93.105.128/pro_enroll_api/test_connection.php` | — | DB connectivity (legacy) | Health → Test Connection |

---

## 4. Auth endpoints (8 + Pro-Fix variants)

| # | Method | Path | Auth | App | Purpose | Postman |
|---|--------|------|------|-----|---------|---------|
| A1 | POST | `/v1/auth/otp/send` | — | Pro-Enroll | Send OTP to phone | Auth → Send OTP |
| A2 | POST | `/v1/auth/otp/send` | — | Pro-Fix | Same path, add `"app":"pro_fix_customer"` | Auth → Send OTP (Pro-Fix) |
| A3 | POST | `/v1/auth/otp/verify` | — | Both | Verify OTP → JWT | Auth → Verify OTP |
| A4 | POST | `/v1/auth/firebase/session` | — | Both | Firebase ID token → JWT | Auth → Firebase Session |
| A5 | POST | `/v1/auth/refresh` | — | Both | New access token | Auth → Refresh Token |
| A6 | GET | `/v1/auth/me` | 🔒 | Both | Current session / profile | Auth → Me |
| A7 | GET | `/v1/auth/validate` | 🔒 | Both | Check JWT valid | Auth → Validate |
| A8 | POST | `/v1/auth/logout` | 🔒 | Both | End session | Auth → Logout |
| A9 | POST | `/v1/auth/switch-role` | 🔒 | Both | Switch pro / customer role | Auth → Switch Role |

### A1 — Send OTP (Pro-Enroll)

```http
POST {{base_url}}/v1/auth/otp/send
Content-Type: application/json

{
  "phone_e164": "+919876543210",
  "mode": "sign_up"
}
```

### A2 — Send OTP (Pro-Fix Customer)

```json
{
  "phone_e164": "+919876543210",
  "mode": "sign_up",
  "app": "pro_fix_customer"
}
```

### A3 — Verify OTP

```json
{
  "request_id": "{{request_id}}",
  "otp": "123456",
  "mode": "sign_up"
}
```

Pro-Fix verify also add: `"app": "pro_fix_customer"`, optional `"full_name": "Customer Name"`.

Response `data`: `access_token`, `refresh_token`, `next_route`, `profile`.

---

## 5. Pro-Enroll screen endpoints (32 endpoints)

Used by **Pro-Enroll** Flutter app (`pro_enroll_app`).

### Splash & auth screens

| # | Method | Path | Auth | Purpose | Postman |
|---|--------|------|------|---------|---------|
| S1 | GET | `/v1/screens/splash` | — | App config, languages | Screens → Splash |
| S2 | GET | `/v1/screens/auth-landing` | — | Welcome screen data | Auth Landing |
| S3 | GET | `/v1/screens/auth-phone` | — | Phone input screen | Auth Phone (GET) |
| S4 | POST | `/v1/screens/auth-phone` | — | Submit phone, start OTP | Auth Phone (POST) |
| S5 | GET | `/v1/screens/auth-otp` | — | OTP screen data | Auth OTP (GET) |
| S6 | POST | `/v1/screens/auth-otp` | 🔒 | Verify OTP in screen flow | Auth OTP (POST) |

### Onboarding

| # | Method | Path | Auth | Purpose | Postman |
|---|--------|------|------|---------|---------|
| S7 | GET | `/v1/screens/onboard-category` | — | List categories | Onboard Category (GET) |
| S8 | PUT | `/v1/screens/onboard-category` | 🔒 | Save categories + experience | Onboard Category (PUT) |
| S9 | GET | `/v1/screens/onboard-experience` | 🔒 | Experience form | Onboard Experience (GET) |
| S10 | PUT | `/v1/screens/onboard-experience` | 🔒 | Save name + experience | Onboard Experience (PUT) |
| S11 | GET | `/v1/screens/onboard-location` | — | City / radius form | Onboard Location (GET) |
| S12 | PUT | `/v1/screens/onboard-location` | 🔒 | Save work area | Onboard Location (PUT) |
| S13 | GET | `/v1/screens/onboard-fee` | — | Visit fee form | Onboard Fee (GET) |
| S14 | PUT | `/v1/screens/onboard-fee` | 🔒 | Save visit fee (paise) | Onboard Fee (PUT) |

**S8 body:** `{ "category_codes": ["ac","plumber"], "experience_by_category": { "ac": 3 } }`  
**S12 body:** `{ "city_id": 1, "work_radius_km": 8 }`  
**S14 body:** `{ "visit_fee_paise": 19900 }`

### KYC

| # | Method | Path | Auth | Purpose | Postman |
|---|--------|------|------|---------|---------|
| S15 | GET | `/v1/screens/kyc-intro` | 🔒 | KYC intro | KYC Intro |
| S16 | POST | `/v1/screens/kyc-aadhaar` | 🔒 | Aadhaar initiate / verify | KYC Aadhaar |
| S17 | POST | `/v1/screens/kyc-selfie` | 🔒 | Selfie / liveness | KYC Selfie |
| S18 | POST | `/v1/screens/kyc-docs` | 🔒 | Supporting documents | KYC Docs |
| S19 | GET | `/v1/screens/kyc-pending` | 🔒 | Pending review status | KYC Pending |
| S20 | POST | `/v1/screens/kyc-pending/simulate-approval` | 🔒 | Demo: approve profile | Simulate Approval |

**S16 initiate:** `{ "action": "initiate", "aadhaar_last4": "1234" }`  
**S16 verify:** `{ "action": "verify", "kyc_ref_id": "...", "otp": "123456" }`

### Home & jobs

| # | Method | Path | Auth | Purpose | Postman |
|---|--------|------|------|---------|---------|
| S21 | GET | `/v1/screens/home-jobs` | 🔒 | Jobs tab, offers | Home Jobs |
| S22 | GET | `/v1/screens/home-earnings` | 🔒 | Earnings tab | Home Earnings |
| S23 | GET | `/v1/screens/home-profile` | 🔒 | Profile read | Home Profile (GET) |
| S24 | PUT | `/v1/screens/home-profile` | 🔒 | Profile update | Home Profile (PUT) |
| S25 | GET | `/v1/screens/home-help` | — | Help / FAQ | Home Help |
| S26 | GET | `/v1/screens/job-offer/{offer_id}` | 🔒 | Offer detail + timer | Job Offer |
| S27 | POST | `/v1/screens/job-offer/{offer_id}/accept` | 🔒 | Accept job | Job Offer Accept |
| S28 | POST | `/v1/screens/job-offer/{offer_id}/reject` | 🔒 | Reject job | Job Offer Reject |
| S29 | GET | `/v1/screens/job-active` | 🔒 | Active job state | Job Active (GET) |
| S30 | PUT | `/v1/screens/job-active` | 🔒 | Update job status | Job Active (PUT) |
| S31 | POST | `/v1/screens/job-active` | 🔒 | Job action (complete, etc.) | Job Active (POST) |

---

## 6. Pro-Fix customer endpoints (11 endpoints)

Used by **Pro-Fix Customer** app (`pro_fix_v1`). Auth with `"app": "pro_fix_customer"`.

| # | Method | Path | Auth | Purpose | Postman |
|---|--------|------|------|---------|---------|
| C1 | GET | `/v1/customer/categories` | — | Service categories | Categories |
| C2 | GET | `/v1/customer/cities` | — | Cities list | Cities |
| C3 | GET | `/v1/customer/pros/search?city=&category=` | — | Search professionals | Pros Search |
| C4 | GET | `/v1/customer/pros/{pro_id}` | — | Pro public profile | Pro Detail |
| C5 | GET | `/v1/customer/bookings` | 🔒 | My bookings list | Bookings (GET) |
| C6 | POST | `/v1/customer/bookings` | 🔒 | Create booking | Bookings (POST) |
| C7 | GET | `/v1/customer/bookings/{booking_id}` | 🔒 | Booking detail | Booking Detail |
| C8 | POST | `/v1/customer/bookings/{booking_id}/complete` | 🔒 | Mark complete | Booking Complete |
| C9 | POST | `/v1/customer/bookings/{booking_id}/rating` | 🔒 | Rate pro | Booking Rating |
| C10 | GET | `/v1/customer/profile` | 🔒 | Customer profile | Profile (GET) |
| C11 | PUT | `/v1/customer/profile` | 🔒 | Update profile | Profile (PUT) |
| C12 | POST | `/v1/customer/profile/photo` | 🔒 | Upload photo | Profile Photo |

**C3 example:**  
`GET {{base_url}}/v1/customer/pros/search?city=Pondicherry&category=ac`

**C6 body:**

```json
{
  "pro_id": 1,
  "category": "ac",
  "scheduled_at": "2026-06-10T10:00:00+05:30",
  "address_line": "MG Road, Pondicherry"
}
```

**C9 body:** `{ "rating": 5, "comment": "Great service" }`

---

## 7. Full endpoint count

| Group | Endpoints |
|-------|-----------|
| Health | 3 |
| Auth | 9 |
| Pro-Enroll Screens | 31 |
| Pro-Fix Customer | 12 |
| **Total** | **55** |

---

## 8. Response format

**Success:**

```json
{
  "success": true,
  "data": { },
  "error": null
}
```

**Error:**

```json
{
  "success": false,
  "data": null,
  "error": { "code": "not_found", "message": "Route not found" }
}
```

---

## 9. Live server checklist (before Postman)

If endpoints return **404 HTML** or directory listing, fix the server first:

```bash
cd /var/www/html/pro_enroll_api
cp .env.remote .env
composer install --no-dev
sudo a2enmod rewrite headers
sudo cp deploy/apache-pro-enroll.conf /etc/apache2/conf-available/pro-enroll.conf
sudo a2enconf pro-enroll
sudo systemctl reload apache2
```

Import DB in phpMyAdmin: `database/schema.sql`

Verify:

```bash
curl http://98.93.105.128/pro_enroll_api/public/ping.php
curl http://98.93.105.128/pro_enroll_api/v1/screens/splash
```

Both must return **JSON**, not HTML.

---

## 10. Common Postman errors (live)

| Result | Cause | Fix |
|--------|-------|-----|
| 404 HTML | Apache rewrite off | `DEPLOY_VPS.md` |
| 503 `missing_vendor` | No composer on server | `composer install` |
| 401 | No / expired token | Re-run Send + Verify OTP |
| 422 | Missing body field | Check JSON in doc above |
| CORS (browser only) | Missing headers | Enable `mod_headers` |
| HTML "Index of" | Directory listing | Deploy `.htaccess` + `index.php` |

---

## 11. Files to import in Postman

```
pro_enroll_api/
├── Pro-Enroll-API.postman_collection.json      ← all 55 requests
├── Pro-Enroll-API-Live.postman_environment.json ← base_url live
├── Pro-Enroll-API-Local.postman_environment.json
└── docs/
    ├── POSTMAN_LIVE_FULL_REFERENCE.md          ← this file
    └── API_DOCUMENTATION.md
```

---

## 12. Quick copy — live URLs

```text
http://98.93.105.128/pro_enroll_api/v1
http://98.93.105.128/pro_enroll_api/public/ping.php
http://98.93.105.128/pro_enroll_api/v1/screens/splash
http://98.93.105.128/pro_enroll_api/v1/auth/otp/send
http://98.93.105.128/pro_enroll_api/v1/auth/otp/verify
http://98.93.105.128/pro_enroll_api/v1/auth/me
http://98.93.105.128/pro_enroll_api/v1/screens/home-jobs
http://98.93.105.128/pro_enroll_api/v1/customer/categories
http://98.93.105.128/pro_enroll_api/v1/customer/pros/search?city=Pondicherry&category=ac
```
