#!/bin/bash
# Quick fix after git pull on VPS (calls full setup)
#   cd /var/www/html/pro_enroll_api && sudo bash scripts/vps-fix.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
exec sudo bash "$ROOT/deploy/ubuntu-setup.sh"
