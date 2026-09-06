#!/usr/bin/env bash
# Builds an installable plugin zip: build/woo-wholesale.zip
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BUILD="$ROOT/build"
NAME="woo-wholesale"

rm -rf "$BUILD"
mkdir -p "$BUILD/$NAME"

# Copy everything except the entries listed in .distignore.
EXCLUDES=()
while IFS= read -r line; do
	[[ -z "$line" || "$line" == \#* ]] && continue
	EXCLUDES+=( "--exclude=./$line" )
done < "$ROOT/.distignore"

( cd "$ROOT" && tar -cf - "${EXCLUDES[@]}" --exclude='./build' . ) | ( cd "$BUILD/$NAME" && tar -xf - )

( cd "$BUILD" && zip -rq "$NAME.zip" "$NAME" )

echo "Created $BUILD/$NAME.zip"
