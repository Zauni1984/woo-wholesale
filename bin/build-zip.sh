#!/usr/bin/env bash
# Builds the installable plugin package.
#
#   build/woo-wholesale/     plain plugin folder (used as the CI artifact)
#   dist/woo-wholesale.zip   installable zip for Plugins > Upload
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BUILD="$ROOT/build"
DIST="$ROOT/dist"
NAME="woo-wholesale"

rm -rf "$BUILD" "$DIST"
mkdir -p "$BUILD/$NAME" "$DIST"

# Copy everything except the entries listed in .distignore.
EXCLUDES=()
while IFS= read -r line; do
	[[ -z "$line" || "$line" == \#* ]] && continue
	EXCLUDES+=( "--exclude=./$line" )
done < "$ROOT/.distignore"

( cd "$ROOT" && tar -cf - "${EXCLUDES[@]}" --exclude='./build' --exclude='./dist' . ) | ( cd "$BUILD/$NAME" && tar -xf - )

( cd "$BUILD" && zip -rq "$DIST/$NAME.zip" "$NAME" )

echo "Created $DIST/$NAME.zip"
