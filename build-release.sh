#!/bin/bash
# Build a release tarball: releases/php-budget-<version>.tar.gz
# Run from the project root on a machine with composer + tar.
set -euo pipefail

VERSION="0.2-beta"
STAGE="php-budget-${VERSION}"
ROOT="$(cd "$(dirname "$0")" && pwd)"
OUT="${ROOT}/releases"

cd "$ROOT"

echo ">> Installing production dependencies..."
composer install --no-dev --optimize-autoloader --no-interaction

echo ">> Staging ${STAGE}/ ..."
TMP="$(mktemp -d)"
DEST="${TMP}/${STAGE}"
mkdir -p "$DEST"

# What ships in a release.
cp -r public src templates migrations bin vendor "$DEST/"
cp composer.json composer.lock config.sample.php .htaccess README.md CHANGELOG.md LICENSE "$DEST/"

# Scrub anything that shouldn't ship.
rm -f "$DEST"/public/*.log "$DEST"/mail*.log 2>/dev/null || true
find "$DEST" -name '.DS_Store' -delete 2>/dev/null || true
find "$DEST" -type d -name '.git' -prune -exec rm -rf {} + 2>/dev/null || true

mkdir -p "$OUT"
echo ">> Creating ${OUT}/${STAGE}.tar.gz ..."
tar -czf "${OUT}/${STAGE}.tar.gz" -C "$TMP" "$STAGE"

rm -rf "$TMP"
echo ">> Done: releases/${STAGE}.tar.gz"
ls -lh "${OUT}/${STAGE}.tar.gz"
