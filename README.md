# Pro-Enroll API (PHP)

REST API for the **Pro-Enroll** Flutter app (`pro_enroll_v1`). Each Flutter screen has a matching PHP file under `api/screens/`. Protected routes require a **Firebase ID token** in the `Authorization` header.

## Requirements

- PHP 8.2+
- Composer
- MySQL 8+
- Firebase project `proenroll-4ff13` + **service account JSON** (Admin SDK)

## Setup

```powershell
cd D:\krishna\pro_enroll_api
copy .env.example .env
# Edit .env (DB_*, FIREBASE_CREDENTIALS)

composer install

# Firebase: Console → Project settings → Service accounts → Generate new private key
copy config\firebase-service-account.json.example config\firebase-service-account.json
# Paste real JSON from Firebase

mysql -u root -p < database\schema.sql
```

## Run locally

```powershell
cd D:\krishna\pro_enroll_api
php -S localhost:8080 -t public
```

Open http://localhost:8080/v1 for route list.

## Authentication (Firebase Phone Auth → JWT)

1. User signs in with **Firebase Phone Auth** in the Flutter app (`USE_FIREBASE_SMS_OTP=true`). Firebase sends the SMS OTP.
2. App calls `currentUser.getIdToken()` and posts to `POST /v1/auth/firebase/session`.
3. API verifies the token with `kreait/firebase-php` (service account JSON) and returns a JWT session (same shape as `/v1/auth/otp/verify`).
4. Protected routes use `Authorization: Bearer <jwt>`.

**Legacy mail OTP:** `POST /v1/auth/otp/send` + `/verify` when the app runs with `USE_FIREBASE_SMS_OTP=false`.

**Local dev without Firebase:** set in `.env`:

```env
FIREBASE_AUTH_DISABLED=true
```

## Screen → endpoint map

| Flutter screen | PHP file | Endpoint |
| -------------- | -------- | -------- |
| Splash | `splash.php` | `GET /v1/screens/splash` |
| Auth landing | `auth_landing.php` | `GET /v1/screens/auth-landing` |
| Phone input | `auth_phone.php` | `GET/POST /v1/screens/auth-phone` 🔒 |
| OTP verify | `auth_otp.php` | `GET/POST /v1/screens/auth-otp` 🔒 |
| Category select | `onboard_category.php` | `GET/PUT /v1/screens/onboard-category` |
| Experience | `onboard_experience.php` | `GET/PUT /v1/screens/onboard-experience` 🔒 |
| Home location | `onboard_location.php` | `GET/PUT /v1/screens/onboard-location` |
| Visit fee | `onboard_fee.php` | `GET/PUT /v1/screens/onboard-fee` |
| KYC intro | `kyc_intro.php` | `GET /v1/screens/kyc-intro` 🔒 |
| Aadhaar | `kyc_aadhaar.php` | `POST /v1/screens/kyc-aadhaar` 🔒 |
| Selfie | `kyc_selfie.php` | `POST /v1/screens/kyc-selfie` 🔒 |
| Documents | `kyc_docs.php` | `POST /v1/screens/kyc-docs` 🔒 |
| Pending review | `kyc_pending.php` | `GET /v1/screens/kyc-pending` 🔒 |
| Jobs tab | `home_jobs.php` | `GET /v1/screens/home-jobs` 🔒 |
| Earnings tab | `home_earnings.php` | `GET /v1/screens/home-earnings` 🔒 |
| Profile tab | `home_profile.php` | `GET/PUT /v1/screens/home-profile` 🔒 |
| Help tab | `home_help.php` | `GET /v1/screens/home-help` |
| Offer detail | `job_offer.php` | `GET /v1/screens/job-offer/{id}` 🔒 |
| Active job | `job_active.php` | `GET/PUT/POST /v1/screens/job-active` 🔒 |

🔒 = requires `Authorization: Bearer <firebase_id_token>`

## Response format

```json
{
  "success": true,
  "data": { },
  "error": null
}
```

## Example (after Firebase sign-in)

```bash
curl -H "Authorization: Bearer YOUR_ID_TOKEN" ^
  http://localhost:8080/v1/screens/home-profile
```

```bash
curl -X PUT -H "Authorization: Bearer YOUR_ID_TOKEN" ^
  -H "Content-Type: application/json" ^
  -d "{\"full_name\":\"Ravi Kumar\",\"city_id\":1,\"work_radius_km\":8}" ^
  http://localhost:8080/v1/screens/onboard-location
```

## Project layout

```
pro_enroll_api/
├── api/screens/          # one PHP class per Flutter screen
├── public/index.php      # front controller
├── src/
│   ├── Auth/FirebaseAuth.php
│   ├── Middleware/FirebaseTokenMiddleware.php
│   ├── Services/ProRepository.php
│   └── Router.php
├── database/schema.sql
└── config/               # firebase service account (gitignored)
```
