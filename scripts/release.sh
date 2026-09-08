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

echo "==> Staging release tree"
rm -rf "$DIST"
mkdir -p "$STAGE"

DIRTY="$(git -C "$ROOT" status --porcelain)"
if [[ -n "$DIRTY" ]]; then
  echo "!! Working tree is dirty — the zip is built from HEAD, these stay out:" >&2
  echo "$DIRTY" >&2
fi

# Everything ships from a pristine HEAD checkout: release artifacts must come
# from committed code, never from whatever happens to be in the working tree.
echo "==> Checking out HEAD and building admin UI"
SRC="$DIST/head"
git -C "$ROOT" worktree prune
git -C "$ROOT" worktree add --detach --quiet "$SRC" HEAD
trap 'git -C "$ROOT" worktree remove --force "$SRC" >/dev/null 2>&1 || true' EXIT
npm ci --prefix "$SRC/frontend"
npm run build --prefix "$SRC/frontend"

# Paths that belong in the installable zip (no .git / node_modules / .env).
# Dev-only trees are dropped so an update touches as few files as possible.
rsync -a \
  --exclude '.git' \
  --exclude '.github' \
  --exclude 'vendor' \
  --exclude '.env' \
  --exclude '.env.*' \
  --exclude 'frontend' \
  --exclude 'landing' \
  --exclude 'node_modules' \
  --exclude 'dist' \
  --exclude 'storage/*' \
  --exclude '.php-cs-fixer.php' \
  --exclude '.php-cs-fixer.cache' \
  --exclude '.phpunit.cache' \
  --exclude 'phpstan.neon' \
  --exclude 'phpunit.xml' \
  --exclude 'tests' \
  --exclude '.cursor' \
  "$SRC/" "$STAGE/"

# Install into the stage itself: Composer bakes the vendor→root depth into the
# generated autoload files, so vendor must be built where it will ship. The
# working vendor/ keeps its dev tools and needs no reinstall after a release.
echo "==> Composer autoload (no-dev, staged tree)"
composer install --no-dev --optimize-autoloader --no-interaction --working-dir="$STAGE"

mkdir -p "$STAGE/storage/cache" "$STAGE/storage/logs" "$STAGE/storage/uploads"
touch "$STAGE/storage/.gitkeep" \
  "$STAGE/storage/cache/.gitkeep" \
  "$STAGE/storage/logs/.gitkeep" \
  "$STAGE/storage/uploads/.gitkeep"

# Harden upload dirs even when storage/* is excluded from rsync
cp -f "$SRC/storage/.htaccess" "$STAGE/storage/.htaccess"
cp -f "$SRC/storage/uploads/.htaccess" "$STAGE/storage/uploads/.htaccess"

if [[ ! -f "$STAGE/public/admin/index.html" ]]; then
  echo "Admin UI build produced no index.html" >&2
  exit 1
fi

if [[ ! -f "$STAGE/vendor/autoload.php" || -d "$STAGE/vendor/phpunit" ]]; then
  echo "Staged vendor is not a no-dev autoload tree" >&2
  exit 1
fi

# Never ship a tree that cannot boot: this is the check that a mis-generated
# autoloader (0.45.3) slipped past.
echo "==> Verifying staged tree boots"
php "$STAGE/scripts/verify-tree.php" "$STAGE"

echo "==> Zipping"
(
  cd "$STAGE"
  zip -qr "$ZIP" .
)

SHA="$(shasum -a 256 "$ZIP" | awk '{print $1}')"
echo "$SHA  cms-${VERSION}.zip" > "$SHA_FILE"

REPO="${CMS_GITHUB_REPO:-lnked/hcms}"
php "$SRC/scripts/latest-json.php" \
  --version="$VERSION" \
  --zip="https://github.com/${REPO}/releases/download/${TAG}/cms-${VERSION}.zip" \
  --sha256="$SHA" \
  --out="$LATEST"

INSTALL_PHP="$DIST/install.php"
cp -f "$SRC/install.php" "$INSTALL_PHP"

echo "==> Artifacts"
ls -lh "$ZIP" "$SHA_FILE" "$LATEST" "$INSTALL_PHP"
echo "sha256: $SHA"

if [[ "${PUBLISH:-0}" == "1" ]]; then
  echo "==> Publishing GitHub release ${TAG}"
  gh release create "$TAG" \
    --repo "$REPO" \
    --title "HCMS ${VERSION}" \
    --notes "Release ${VERSION}. Install via install.php → download latest." \
    "$ZIP" "$SHA_FILE" "$LATEST" "$INSTALL_PHP"
  # latest.json / install.php must be reachable as .../latest/download/<name>
  # GitHub maps /releases/latest/download/X to the newest release asset named X.
  echo "Published. Verify: https://github.com/${REPO}/releases/latest/download/install.php"
else
  echo "Dry-run only. Re-run with PUBLISH=1 to create GitHub release."
fi
