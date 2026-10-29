#!/usr/bin/env bash
#
# Build the installable plugin zip: dist/wp-category-featured-image-rotator-<version>.zip
#
# Only runtime files go in (no tests, dev tools or config). The script stops
# if the version numbers in the plugin disagree, or if a version is given
# (e.g. from a release tag) and it doesn't match.
#
#   bin/build.sh          # build the current version
#   bin/build.sh 0.2.0    # build, and require the plugin to be at 0.2.0

set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$root"

slug="wp-category-featured-image-rotator"
main="$slug.php"

# Files and folders the plugin needs at runtime.
include=(
	"$main"
	uninstall.php
	LICENSE
	includes
	admin
)

fail() {
	echo "✖ $*" >&2
	exit 1
}

# --- Versions must agree --------------------------------------------------
header=$(sed -n 's/^ \* Version:[[:space:]]*\(.*\)$/\1/p' "$main" | head -1)
constant=$(sed -n "s/^define( 'CFIR_VERSION', '\(.*\)' );$/\1/p" "$main" | head -1)
package=$(sed -n 's/^  "version": "\(.*\)",$/\1/p' package.json | head -1)

[ -n "$header" ] || fail "No 'Version:' found in $main."
for pair in "CFIR_VERSION:$constant" "package.json version:$package"; do
	name="${pair%%:*}"
	value="${pair#*:}"
	[ "$value" = "$header" ] || fail "$name is '$value' but the plugin header says '$header'. Run: bin/set-version.sh $header"
done
if [ $# -gt 0 ] && [ "$1" != "$header" ]; then
	fail "Expected version $1 (from the release tag) but the plugin is at $header. Run: bin/set-version.sh $1"
fi
version="$header"

# --- Required files must exist ---------------------------------------------
for path in "${include[@]}"; do
	[ -e "$path" ] || fail "Missing $path."
done

# --- Build --------------------------------------------------------------------
rm -rf dist
mkdir -p "dist/$slug"
cp -R "${include[@]}" "dist/$slug/"
# No OS clutter in the package.
find "dist/$slug" -name '.DS_Store' -delete

zip_name="$slug-$version.zip"
(cd dist && zip -qr -X "$zip_name" "$slug")
(cd dist && { command -v sha256sum >/dev/null && sha256sum "$zip_name" || shasum -a 256 "$zip_name"; } > "$zip_name.sha256")

# Keep only the zip and its checksum: an unzipped copy would be picked up
# by the coding-standards and boundary checks as duplicate code.
rm -rf "dist/$slug"

echo "✔ Built dist/$zip_name ($(du -h "dist/$zip_name" | cut -f1 | tr -d ' ')) — version $version"
echo "  Contents:"
(cd dist && unzip -Z1 "$zip_name" | grep -v '/$' | sed 's/^/    /')
