#!/usr/bin/env bash
# Thin wrapper — prefer deploy.sh (used by GitHub Actions SSH deploy).
set -euo pipefail
cd "$(dirname "$0")/.."
exec bash ./deploy.sh
