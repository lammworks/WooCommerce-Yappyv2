#!/usr/bin/env bash
#
# Build the installable plugin ZIP.
#
# The archive is produced with `git archive`, so it contains exactly the tracked
# files minus everything marked `export-ignore` in .gitattributes. Untracked
# local files, the Composer dev dependencies and the test suite therefore cannot
# leak into a release.
#
# Usage:
#   bin/build-plugin-zip.sh [git-ref]      # defaults to HEAD
#
# Output:
#   build/woocommerce-yappy-<version>.zip
#
# The ZIP contains a single top-level woocommerce-yappy/ directory, which is what
# WordPress expects from Plugins -> Add New -> Upload Plugin.

set -euo pipefail

SLUG="woocommerce-yappy"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REF="${1:-HEAD}"

cd "$ROOT"

# The plugin header is the single source of truth for the version.
VERSION="$(sed -n 's/^ \* Version:[[:space:]]*\([0-9][^[:space:]]*\).*/\1/p' "$SLUG.php" | head -n1)"

if [ -z "$VERSION" ]; then
	echo "error: could not read the version from $SLUG.php" >&2
	exit 1
fi

# Guard against a header that disagrees with the readme.txt stable tag, which is
# the mistake that ships an update WordPress will not offer to anyone.
STABLE_TAG="$(sed -n 's/^Stable tag:[[:space:]]*\(.*\)$/\1/p' readme.txt | head -n1 | tr -d '\r')"

if [ -n "$STABLE_TAG" ] && [ "$STABLE_TAG" != "$VERSION" ]; then
	echo "error: plugin header version ($VERSION) does not match readme.txt stable tag ($STABLE_TAG)" >&2
	exit 1
fi

BUILD="$ROOT/build"
ZIP="$BUILD/$SLUG-$VERSION.zip"

rm -rf "$BUILD"
mkdir -p "$BUILD"

git archive --format=tar --prefix="$SLUG/" "$REF" | tar -x -C "$BUILD"

# Fail loudly rather than ship a bad archive. export-ignore only covers what it
# is told about, so anything that was committed by accident would otherwise ride
# along silently — a nested build/ directory in particular, which quietly
# triples the size of the ZIP with a copy of the plugin inside itself.
for forbidden in build tests bin .github vendor composer.json composer.lock phpunit.xml.dist; do
	if [ -e "$BUILD/$SLUG/$forbidden" ]; then
		echo "error: $forbidden must not be in the distributable — check .gitattributes and .gitignore" >&2
		exit 1
	fi
done

if [ ! -f "$BUILD/$SLUG/$SLUG.php" ] || [ ! -f "$BUILD/$SLUG/readme.txt" ]; then
	echo "error: the archive is missing the plugin bootstrap or readme.txt" >&2
	exit 1
fi

( cd "$BUILD" && zip -rq "$(basename "$ZIP")" "$SLUG" -x '*.DS_Store' )

echo "Built $ZIP"
echo
echo "Contents:"
( cd "$BUILD" && find "$SLUG" -type f | sort | sed 's/^/  /' )
