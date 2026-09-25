#!/usr/bin/env bash
# Update Composer + frontend deps (same major) and run QA.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

echo "==> Composer update"
composer update

echo "==> Frontend: bump package.json (same major) + install"
(
  cd frontend
  if command -v ncu >/dev/null 2>&1; then
    # Keep TS7 (@typescript/native) + TS6 API alias (typescript) intact — ncu would flatten them.
    ncu -u --target minor --reject '/^(typescript|@typescript\/native)$/'
  else
    npx --yes npm-check-updates -u --target minor --reject '/^(typescript|@typescript\/native)$/'
  fi
  npm install
)

echo "==> QA (PHP)"
composer qa

echo "==> QA (frontend)"
npm run qa

echo "==> Build frontend"
npm run build

echo "==> verify-tree"
php scripts/verify-tree.php

echo "OK — deps updated and checks passed."
