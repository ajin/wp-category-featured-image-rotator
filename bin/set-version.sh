#!/usr/bin/env bash
#
# Set the plugin version everywhere it appears:
# the plugin header, CFIR_VERSION and package.json.
#
#   bin/set-version.sh 0.2.0

set -euo pipefail

version="${1:-}"
if ! [[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+([.-][0-9A-Za-z.-]+)?$ ]]; then
	echo "Usage: bin/set-version.sh <version>   (for example 0.2.0)" >&2
	exit 1
fi

root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$root"
main="wp-category-featured-image-rotator.php"

# Portable in-place edit (GNU and BSD sed differ).
edit() {
	local file="$1" expr="$2"
	sed -e "$expr" "$file" > "$file.tmp" && mv "$file.tmp" "$file"
}

edit "$main" "s/^\( \* Version:[[:space:]]*\).*$/\1$version/"
edit "$main" "s/^define( 'CFIR_VERSION', '.*' );$/define( 'CFIR_VERSION', '$version' );/"
edit package.json "s/^  \"version\": \".*\",$/  \"version\": \"$version\",/"

echo "✔ Version set to $version. Next: add a '## $version' section to CHANGELOG.md, commit, then:"
echo "    git tag v$version && git push origin v$version"
