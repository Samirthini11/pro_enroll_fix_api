# Pro-Enroll API — Documentation

REST API for the **Pro-Enroll** professional onboarding Flutter app.

| Item | Value |
|------|-------|
| **Live base URL** | `http://98.93.105.128/pro_enroll_api` |
| **Local base URL** | `http://localhost:8080` |
| **Full Postman live reference (55 endpoints)** | [`POSTMAN_LIVE_FULL_REFERENCE.md`](./POSTMAN_LIVE_FULL_REFERENCE.md) |
| **Postman collection** | [`../Pro-Enroll-API.postman_collection.json`](../Pro-Enroll-API.postman_collection.json) |
| **Live environment** | [`../Pro-Enroll-API-Live.postman_environment.json`](../Pro-Enroll-API-Live.postman_environment.json) |
| **Local environment** | [`../Pro-Enroll-API-Local.postman_environment.json`](../Pro-Enroll-API-Local.postman_environment.json) |

---

## 1. Base URL

All endpoints are relative to the base URL:

```text
{base_url}/v1/...
```

Examples:

| Environment | Full splash URL |
|-------------|-----------------|
| Live | `http://98.93.105.128/pro_enroll_api/v1/screens/splash` |
| Local | `http://localhost:8080/v1/screens/splash` |

**Do not** add `/public` to the live URL when Apache is configured correctly (see [`DEPLOY_VPS.md`](../DEPLOY_VPS.md)).

---

## 2. Request / response format

### Headers

| Header | When |
|--------|------|
| `Content-Type: application/json` | POST / PUT with JSON body |
| `Authorization: Bearer <access_token>` | Protected routes (🔒) |

### Success response

```json
{
  "success": true,
  "data": { },
  "error": null
}
```

### Error response

```json
{
  "success": false,
  "data": null,
  "error": {
    "code": "not_found",
    "message": "Route not found"
  }
}
```

### Auth legend

| Symbol | Meaning |
|--------|---------|
| — | Public (no token) |
| 🔒 | Requires `Authorization: Bearer <access_token>` |

---

## 3. Authentication flow (Postman)

Use this order when testing the live server:

```text
1. Health → Ping
2. Health → API Root (/v1)
3. Auth → Send OTP
4. Auth → Verify OTP          (saves access_token + refresh_token)
5. Auth → Me (Bearer)
6. Screens / Customer calls   (Bearer auto-filled from collection variables)
```

### Step 1 — Send OTP

**POST** `{base_url}/v1/auth/otp/send`

```json
{
  "phone_e164": "+919876543210",
  "mode": "sign_up"
}
```

**Response (data):** copy `request_id` into Postman variable `request_id`.  
When `APP_DEBUG=true`, OTP may appear in `debug_otp`.

### Step 2 — Verify OTP

**POST** `{base_url}/v1/auth/otp/verify`

```json
{
  "request_id": "{{request_id}}",
  "otp": "123456",
  "mode": "sign_up"
}
```

**Response (data):** `access_token`, `refresh_token`, `next_route`.  
The Postman collection auto-saves tokens from this response.

### Step 3 — Use Bearer token

Protected requests use:

```text
Authorization: Bearer {{access_token}}
```

### Firebase alternative

**POST** `{base_url}/v1/auth/firebase/session`

```json
{
  "id_token": "<firebase_id_token_from_app>",
  "mode": "sign_up"
}
```

---

## 4. Health & diagnostics

| Method | Path | Auth | Purpose |
|--------|------|------|---------|
| GET | `/v1` | — | API info, route list |
| GET | `/public/ping.php` | — | Server health: PHP, vendor, .env, DB |
| GET | `/test_connection.php` | — | Legacy DB connectivity check (project root) |

**Live ping:** `http://98.93.105.128/pro_enroll_api/public/ping.php`

Expected when server is ready:

```json
{
  "ok": true,
  "vendor": true,
  "env": true,
  "db": "OK"
}
```

---

## 5. Auth endpoints

| Method | Path | Auth | Purpose |
|--------|------|------|---------|
| POST | `/v1/auth/otp/send` | — | Send OTP to phone (mail/SMS per server config) |
| POST | `/v1/auth/otp/verify` | — | Verify OTP → JWT session |
| POST | `/v1/auth/firebase/session` | — | Exchange Firebase ID token → JWT |
| POST | `/v1/auth/refresh` | — | Refresh access token |
| GET | `/v1/auth/me` | 🔒 | Current user / session info |
| GET | `/v1/auth/validate` | 🔒 | Validate JWT still active |
| POST | `/v1/auth/logout` | 🔒 | Revoke session |
| POST | `/v1/auth/switch-role` | 🔒 | Switch role (e.g. pro / customer) |

### Refresh token

**POST** `{base_url}/v1/auth/refresh`

```json
{
  "refresh_token": "{{refresh_token}}"
}
```

### Switch role

**POST** `{base_url}/v1/auth/switch-role`

```json
{
  "role": "pro"
}
```

---

## 6. Screen endpoints (Pro-Enroll app flow)

Each endpoint returns screen payload for the matching Flutter screen.

### Splash & auth

| Method | Path | Auth | Purpose |
|--------|------|------|---------|
| GET | `/v1/screens/splash` | — | App config, languages, auth hints |
| GET | `/v1/screens/auth-landing` | — | Welcome / value props |
| GET | `/v1/screens/auth-phone` | — | Phone input screen data |
| POST | `/v1/screens/auth-phone` | — | Submit phone → start OTP |
| GET | `/v1/screens/auth-otp` | — | OTP screen data |
| POST | `/v1/screens/auth-otp` | — | Verify OTP on screen flow |

### Onboarding

| Method | Path | Auth | Purpose |
|--------|------|------|---------|
| GET | `/v1/screens/onboard-category` | — | List service categories |
| PUT | `/v1/screens/onboard-category` | 🔒 | Save selected categories |
| GET | `/v1/screens/onboard-experience` | 🔒 | Experience form data |
| PUT | `/v1/screens/onboard-experience` | 🔒 | Save name + experience |
| GET | `/v1/screens/onboard-location` | — | City / radius form |
| PUT | `/v1/screens/onboard-location` | 🔒 | Save work area |
| GET | `/v1/screens/onboard-fee` | — | Visit fee form |
| PUT | `/v1/screens/onboard-fee` | 🔒 | Save visit fee (paise) |

**PUT onboard-category body:**

```json
{
  "category_codes": ["ac", "plumbing"],
  "experience_by_category": { "ac": 3, "plumbing": 2 }
}
```

**PUT onboard-location body:**

```json
{
  "city_id": 1,
  "work_radius_km": 10
}
```

**PUT onboard-fee body:**

```json
{
  "visit_fee_paise": 29900
}
```

### KYC

| Method | Path | Auth | Purpose |
|--------|------|------|---------|
| GET | `/v1/screens/kyc-intro` | 🔒 | KYC intro screen |
| POST | `/v1/screens/kyc-aadhaar` | 🔒 | Submit Aadhaar OTP step |
| POST | `/v1/screens/kyc-selfie` | 🔒 | Submit selfie / liveness |
| POST | `/v1/screens/kyc-docs` | 🔒 | Upload supporting documents |
| GET | `/v1/screens/kyc-pending` | 🔒 | Pending review status |
| POST | `/v1/screens/kyc-pending/simulate-approval` | 🔒 | Demo: mark profile verified |

### Home & jobs

| Method | Path | Auth | Purpose |
|--------|------|------|---------|
| GET | `/v1/screens/home-jobs` | 🔒 | Jobs tab (offers, stats) |
| GET | `/v1/screens/home-earnings` | 🔒 | Earnings tab |
| GET | `/v1/screens/home-profile` | 🔒 | Profile tab (read) |
| PUT | `/v1/screens/home-profile` | 🔒 | Update profile |
| GET | `/v1/screens/home-help` | — | Help / support content |
| GET | `/v1/screens/job-offer/{offer_id}` | 🔒 | Job offer detail + timer |
| POST | `/v1/screens/job-offer/{offer_id}/accept` | 🔒 | Accept offer |
| POST | `/v1/screens/job-offer/{offer_id}/reject` | 🔒 | Reject offer |
| GET | `/v1/screens/job-active` | 🔒 | Active job state |
| PUT | `/v1/screens/job-active` | 🔒 | Update job status |
| POST | `/v1/screens/job-active` | 🔒 | Job action (e.g. complete) |

Set Postman variable `offer_id` (e.g. `offer_001`) for job-offer routes.

---

## 7. Customer endpoints (Pro-User app)

| Method | Path | Auth | Purpose |
|--------|------|------|---------|
| GET | `/v1/customer/categories` | — | Service categories list |
| GET | `/v1/customer/cities` | — | Cities (Pondicherry, Karaikal, …) |
| GET | `/v1/customer/pros/search` | — | Search professionals (`?city=&category=`) |
| GET | `/v1/customer/pros/{id}` | — | Pro public profile |
| GET | `/v1/customer/bookings` | 🔒 | Customer booking list |
| POST | `/v1/customer/bookings` | 🔒 | Create booking |
| GET | `/v1/customer/bookings/{id}` | 🔒 | Booking detail |
| POST | `/v1/customer/bookings/{id}/complete` | 🔒 | Mark booking complete |
| POST | `/v1/customer/bookings/{id}/rating` | 🔒 | Submit rating |
| GET | `/v1/customer/profile` | 🔒 | Customer profile |
| PUT | `/v1/customer/profile` | 🔒 | Update customer profile |
| POST | `/v1/customer/profile/photo` | 🔒 | Upload profile photo |

**Search example:**

```text
GET {base_url}/v1/customer/pros/search?city=Pondicherry&category=ac
```

---

## 8. How to use Postman (live URL)

### Import files

1. Open Postman → **Import**
2. Import these three files from `pro_enroll_api/`:
   - `Pro-Enroll-API.postman_collection.json`
   - `Pro-Enroll-API-Live.postman_environment.json`
3. Top-right environment dropdown → select **Pro-Enroll Live**

### Set base URL (already in Live environment)

| Variable | Live value |
|----------|------------|
| `base_url` | `http://98.93.105.128/pro_enroll_api` |
| `access_token` | *(auto-filled after Verify OTP)* |
| `refresh_token` | *(auto-filled after Verify OTP)* |
| `request_id` | *(copy from Send OTP response)* |
| `offer_id` | `offer_001` |
| `pro_id` | `1` |
| `booking_id` | `1` |

### Live test checklist

| Step | Request | Expected |
|------|---------|----------|
| 1 | **Health → Ping** | HTTP 200, `"vendor": true`, `"db": "OK"` |
| 2 | **Health → API Root** | HTTP 200, `"name": "Pro-Enroll API"` |
| 3 | **Auth → Send OTP** | HTTP 200, `data.request_id` present |
| 4 | Set `request_id` variable | From step 3 response |
| 5 | **Auth → Verify OTP** | HTTP 200, tokens saved automatically |
| 6 | **Auth → Me (Bearer)** | HTTP 200, user data |
| 7 | **Screens → Splash** | HTTP 200, no auth required |
| 8 | **Screens → Home Jobs** | HTTP 200 with Bearer |

### Common live errors

| HTTP | Cause | Fix |
|------|-------|-----|
| 404 | Apache rewrite not configured | See [`DEPLOY_VPS.md`](../DEPLOY_VPS.md) |
| 503 `missing_vendor` | `composer install` not run on server | SSH + `composer install` |
| 401 | Missing / expired token | Re-run Verify OTP |
| HTML instead of JSON | Wrong URL (directory listing) | Use `base_url` + `/v1/...`, not bare `/` |
| CORS (browser only) | Headers missing on server | Enable `mod_headers`, reload Apache |

### Switch to local testing

Select environment **Pro-Enroll Local** (`base_url` = `http://localhost:8080`).

Start local API:

```powershell
cd D:\krishna\pro_enroll_api
php -S localhost:8080 -t public
```

---

## 9. Quick curl examples (live)

```bash
# Health
curl http://98.93.105.128/pro_enroll_api/public/ping.php
curl http://98.93.105.128/pro_enroll_api/v1

# Send OTP
curl -X POST http://98.93.105.128/pro_enroll_api/v1/auth/otp/send \
  -H "Content-Type: application/json" \
  -d "{\"phone_e164\":\"+919876543210\",\"mode\":\"sign_up\"}"

# Splash (public)
curl http://98.93.105.128/pro_enroll_api/v1/screens/splash

# Protected (replace TOKEN)
curl http://98.93.105.128/pro_enroll_api/v1/auth/me \
  -H "Authorization: Bearer TOKEN"
```

---

## 10. Related docs

| Document | Description |
|----------|-------------|
| [`DEPLOY_VPS.md`](../DEPLOY_VPS.md) | Fix live 404 / CORS on Ubuntu VPS |
| [`database/schema.sql`](../database/schema.sql) | MySQL DDL |
| [`README.md`](../README.md) | Project setup |
