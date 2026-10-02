#!/usr/bin/env bash
# Builds the installable packages of both plugins.
#
#   build/master/woo-wholesale/              plain plugin folder (CI artifact)
#   build/partner/woo-wholesale-partner/     plain plugin folder (CI artifact)
#   dist/woo-wholesale.zip                   installable zip for Plugins > Upload
#   dist/woo-wholesale-partner.zip           installable zip for Plugins > Upload
#
# The two plugins belong to the same release: the supplier shop runs
# woo-wholesale, every partner shop runs woo-wholesale-partner, and both carry
# the same version number.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BUILD="$ROOT/build"
DIST="$ROOT/dist"

rm -rf "$BUILD" "$DIST"
mkdir -p "$BUILD/master/woo-wholesale" "$BUILD/partner/woo-wholesale-partner" "$DIST"

# Shared excludes from .distignore.
EXCLUDES=()
while IFS= read -r line; do
	[[ -z "$line" || "$line" == \#* ]] && continue
	EXCLUDES+=( "--exclude=./$line" )
done < "$ROOT/.distignore"

# --- Supplier plugin -------------------------------------------------------
( cd "$ROOT" && tar -cf - "${EXCLUDES[@]}" --exclude='./build' --exclude='./dist' --exclude='./partner-plugin' . ) \
	| ( cd "$BUILD/master/woo-wholesale" && tar -xf - )

( cd "$BUILD/master" && zip -rq "$DIST/woo-wholesale.zip" "woo-wholesale" )

# --- Partner plugin --------------------------------------------------------
( cd "$ROOT/partner-plugin" && tar -cf - --exclude='*.zip' --exclude='./node_modules' . ) \
	| ( cd "$BUILD/partner/woo-wholesale-partner" && tar -xf - )

( cd "$BUILD/partner" && zip -rq "$DIST/woo-wholesale-partner.zip" "woo-wholesale-partner" )

echo "Created $DIST/woo-wholesale.zip"
echo "Created $DIST/woo-wholesale-partner.zip"
