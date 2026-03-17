#!/bin/bash
# Build a clean WordPress-ready plugin zip.
# Usage: ./build-zip.sh
#
# The zip is created one level up: ../wc-0xprocessing.zip
# It excludes dev files, macOS junk, and version control.

set -e

PLUGIN_SLUG="wc-0xprocessing"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PARENT_DIR="$(dirname "$SCRIPT_DIR")"
OUTPUT="$PARENT_DIR/$PLUGIN_SLUG.zip"

# Remove previous build
rm -f "$OUTPUT"

cd "$PARENT_DIR"

zip -r "$OUTPUT" "$PLUGIN_SLUG/" \
    -x "$PLUGIN_SLUG/.git/*"       \
       "$PLUGIN_SLUG/.github/*"    \
       "$PLUGIN_SLUG/.gitignore"   \
       "$PLUGIN_SLUG/.distignore"  \
       "$PLUGIN_SLUG/docs/*"       \
       "$PLUGIN_SLUG/build-zip.sh" \
       "$PLUGIN_SLUG/CONTRIBUTING.md" \
       "$PLUGIN_SLUG/CHANGELOG.md" \
       "$PLUGIN_SLUG/.DS_Store"    \
       "*/.DS_Store"               \
       "*__MACOSX*"                \
       "*.git*"

echo ""
echo "✅ Built: $OUTPUT"
echo "   $(du -h "$OUTPUT" | cut -f1) — ready to upload to WordPress"
