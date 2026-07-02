#!/bin/bash
# Upload admin API endpoints to live VPS (PSR-4 under src/).
# Usage (from pro_enroll_api root):
#   VPS_USER=ubuntu VPS_HOST=98.93.105.128 bash scripts/deploy-admin-live.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VPS_USER="${VPS_USER:-ubuntu}"
VPS_HOST="${VPS_HOST:-98.93.105.128}"
REMOTE="${VPS_USER}@${VPS_HOST}"
API_ROOT="/var/www/html/pro_enroll_api"

echo "Deploying admin API to ${REMOTE}:${API_ROOT}"

scp "${ROOT}/src/Endpoints/Auth/AdminLoginEndpoint.php" \
  "${REMOTE}:${API_ROOT}/src/Endpoints/Auth/"

scp "${ROOT}/src/Endpoints/Admin/"*.php \
  "${REMOTE}:${API_ROOT}/src/Endpoints/Admin/"

scp "${ROOT}/src/Services/AdminAuthService.php" \
  "${ROOT}/src/Services/AdminRepository.php" \
  "${REMOTE}:${API_ROOT}/src/Services/"

scp "${ROOT}/src/Middleware/AdminMiddleware.php" \
  "${REMOTE}:${API_ROOT}/src/Middleware/"

scp "${ROOT}/.env.remote" "${REMOTE}:${API_ROOT}/.env.remote"

ssh "${REMOTE}" "cd ${API_ROOT} && \
  grep -q '^ADMIN_EMAIL=' .env 2>/dev/null || cat .env.remote >> .env && \
  composer dump-autoload -o --no-dev 2>/dev/null || composer dump-autoload -o"

echo "Verifying admin login..."
curl -sf -X POST "http://${VPS_HOST}/pro_enroll_api/v1/auth/admin/login" \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@proenroll.in","password":"admin123"}' | head -c 200
echo ""
