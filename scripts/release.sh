#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

VERSION="$(tr -d '[:space:]' < VERSION)"
if [[ -z "$VERSION" ]]; then
  echo "VERSION file is empty" >&2
  exit 1
fi

TAG="v${VERSION}"
DIST="$ROOT/dist"
STAGE="$DIST/stage"
ZIP="$DIST/cms-${VERSION}.zip"
SHA_FILE="$DIST/cms-${VERSION}.zip.sha256"
LATEST="$DIST/latest.json"

echo "==> Building admin UI"
npm run build --prefix frontend

echo "==> Composer autoload (no-dev)"
composer install --no-dev --optimize-autoloader --no-interaction

echo "==> Staging release tree"
rm -rf "$DIST"
mkdir -p "$STAGE"

# Paths that belong in the installable zip (no .git / node_modules / .env)
rsync -a \
  --exclude '.git' \
  --exclude '.env' \
  --exclude '.env.*' \
  --exclude 'frontend/node_modules' \
  --exclude 'frontend/dist' \
  --exclude 'node_modules' \
  --exclude 'dist' \
  --exclude 'storage/*' \
  --exclude '.php-cs-fixer.cache' \
  --exclude '.phpunit.cache' \
  --exclude 'tests' \
  --exclude '.cursor' \
  --exclude 'scripts/clean.php' \
  ./ "$STAGE/"

mkdir -p "$STAGE/storage/cache" "$STAGE/storage/logs" "$STAGE/storage/uploads"
touch "$STAGE/storage/.gitkeep" \
  "$STAGE/storage/cache/.gitkeep" \
  "$STAGE/storage/logs/.gitkeep" \
  "$STAGE/storage/uploads/.gitkeep"

# Harden upload dirs even when storage/* is excluded from rsync
cp -f "$ROOT/storage/.htaccess" "$STAGE/storage/.htaccess"
cp -f "$ROOT/storage/uploads/.htaccess" "$STAGE/storage/uploads/.htaccess"

# Keep built admin assets in the zip even if gitignored locally
if [[ -d public/admin ]]; then
  rsync -a public/admin/ "$STAGE/public/admin/"
fi

echo "==> Zipping"
(
  cd "$STAGE"
  zip -qr "$ZIP" .
)

SHA="$(shasum -a 256 "$ZIP" | awk '{print $1}')"
echo "$SHA  cms-${VERSION}.zip" > "$SHA_FILE"

REPO="${CMS_GITHUB_REPO:-lnked/hcms}"
cat > "$LATEST" <<EOF
{
  "version": "${VERSION}",
  "channel": "stable",
  "releasedAt": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
  "zip": "https://github.com/${REPO}/releases/download/${TAG}/cms-${VERSION}.zip",
  "sha256": "${SHA}",
  "changelog": []
}
EOF

echo "==> Artifacts"
ls -lh "$ZIP" "$SHA_FILE" "$LATEST"
echo "sha256: $SHA"

if [[ "${PUBLISH:-0}" == "1" ]]; then
  echo "==> Publishing GitHub release ${TAG}"
  gh release create "$TAG" \
    --repo "$REPO" \
    --title "HCMS ${VERSION}" \
    --notes "Release ${VERSION}. Install via install.php → download latest." \
    "$ZIP" "$SHA_FILE" "$LATEST"
  # latest.json must also be reachable as .../latest/download/latest.json
  # GitHub maps /releases/latest/download/X to the newest release asset named X.
  echo "Published. Verify: https://github.com/${REPO}/releases/latest/download/latest.json"
else
  echo "Dry-run only. Re-run with PUBLISH=1 to create GitHub release."
fi
