#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

BUMP="${1:-minor}"
case "$BUMP" in
  major|minor|patch) ;;
  *)
    echo "Usage: $0 [major|minor|patch]  (default: minor)" >&2
    exit 1
    ;;
esac

CURRENT="$(tr -d '[:space:]' < VERSION)"
if [[ ! "$CURRENT" =~ ^([0-9]+)\.([0-9]+)\.([0-9]+)$ ]]; then
  echo "VERSION must be X.Y.Z, got: ${CURRENT}" >&2
  exit 1
fi

MAJOR="${BASH_REMATCH[1]}"
MINOR="${BASH_REMATCH[2]}"
PATCH="${BASH_REMATCH[3]}"

case "$BUMP" in
  major) MAJOR=$((MAJOR + 1)); MINOR=0; PATCH=0 ;;
  minor) MINOR=$((MINOR + 1)); PATCH=0 ;;
  patch) PATCH=$((PATCH + 1)) ;;
esac

NEXT="${MAJOR}.${MINOR}.${PATCH}"
printf '%s\n' "$NEXT" > VERSION

echo "VERSION: ${CURRENT} → ${NEXT} (${BUMP})"
echo "Next: edit changelog.json, then npm run release"
