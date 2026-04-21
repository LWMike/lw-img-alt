#!/usr/bin/env bash
# Build a distributable zip of lw-img-alt containing only installable files.
set -euo pipefail

PLUGIN_SLUG="lw-img-alt"
REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VERSION=$(grep -m1 "Version:" "$REPO_ROOT/lw-img-alt.php" | sed 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')
DIST_DIR="$REPO_ROOT/dist"
BUILD_DIR="$DIST_DIR/$PLUGIN_SLUG"
ZIP_NAME="${PLUGIN_SLUG}-${VERSION}.zip"

echo "Building $ZIP_NAME..."

rm -rf "$BUILD_DIR"
rm -f  "$DIST_DIR/$ZIP_NAME"
mkdir -p "$BUILD_DIR"

# Copy only the files that belong in a WordPress plugin install.
cp "$REPO_ROOT/lw-img-alt.php" "$BUILD_DIR/"
cp "$REPO_ROOT/uninstall.php"  "$BUILD_DIR/"

for dir in admin includes languages; do
    [ -d "$REPO_ROOT/$dir" ] && cp -r "$REPO_ROOT/$dir" "$BUILD_DIR/$dir"
done

[ -f "$REPO_ROOT/readme.txt" ] && cp "$REPO_ROOT/readme.txt" "$BUILD_DIR/"

cd "$DIST_DIR"
zip -rq "$ZIP_NAME" "$PLUGIN_SLUG"
rm -rf "$BUILD_DIR"

echo "Done: dist/$ZIP_NAME"
