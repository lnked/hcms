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
# Relative so Composer resolves it against composer.json, not the caller's cwd.
NODEV_VENDOR_REL="dist/vendor-nodev"
NODEV_VENDOR="$ROOT/$NODEV_VENDOR_REL"
ZIP="$DIST/cms-${VERSION}.zip"
SHA_FILE="$DIST/cms-${VERSION}.zip.sha256"
LATEST="$DIST/latest.json"

echo "==> Building admin UI"
npm run build --prefix frontend

echo "==> Staging release tree"
rm -rf "$DIST"
mkdir -p "$STAGE"

# Build the no-dev tree in its own vendor dir: the working vendor/ keeps its
# dev tools, so running tests right after a release needs no reinstall.
echo "==> Composer autoload (no-dev, isolated vendor)"
COMPOSER_VENDOR_DIR="$NODEV_VENDOR_REL" \
  composer install --no-dev --optimize-autoloader --no-interaction

# Paths that belong in the installable zip (no .git / node_modules / .env)
rsync -a \
  --exclude '.git' \
  --exclude 'vendor' \
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
  ./ "$STAGE/"

rsync -a "$NODEV_VENDOR/" "$STAGE/vendor/"
rm -rf "$NODEV_VENDOR"

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

if [[ ! -f "$STAGE/vendor/autoload.php" || -d "$STAGE/vendor/phpunit" ]]; then
  echo "Staged vendor is not a no-dev autoload tree" >&2
  exit 1
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
