#!/bin/bash
# Deprecated — use deploy/ubuntu-setup.sh
# Kept for backward compatibility:
#   sudo bash deploy/ubuntu-fix-404.sh
exec "$(dirname "$0")/ubuntu-setup.sh"
