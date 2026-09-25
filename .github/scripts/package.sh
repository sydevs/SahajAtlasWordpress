#!/usr/bin/env bash
# Checks the version declarations, then builds and inspects the plugin zip.
#
#   .github/scripts/package.sh <source-dir> <out-dir>
#
# Run it from a checkout with an `origin` remote: the version is compared against origin's tags.
# CI runs it on every change, so a release fails on the PR that caused it, not after the merge.
set -euo pipefail

SRC=${1:?usage: package.sh <source-dir> <out-dir>}
OUT=${2:?usage: package.sh <source-dir> <out-dir>}

fail() {
	echo "::error::$*"
	exit 1
}

VERSION=$(sed -n 's/^ \* Version: *//p' "$SRC/sahaj-atlas.php" | head -1 | tr -d '[:space:]')
[[ $VERSION =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] ||
	fail "The Version: header in sahaj-atlas.php reads '$VERSION', not MAJOR.MINOR.PATCH."

# ⚠ Nothing else catches a missed declaration. SAHAJ_ATLAS_VERSION is the cache-buster on every
# enqueued asset: left behind, an updated site runs new PHP against the old CSS and JS.
# (why: sydevs/claude-workflow docs/why.md#a-missed-version-declaration-passes-ci)
expect() {
	[ "$2" = "$VERSION" ] || fail "$1 reads '$2', but the plugin header reads $VERSION."
}
expect "SAHAJ_ATLAS_VERSION in sahaj-atlas.php" \
	"$(sed -n "s/^define( *'SAHAJ_ATLAS_VERSION', *'\([^']*\)'.*/\1/p" "$SRC/sahaj-atlas.php")"
expect '"version" in blocks/embed/block.json' \
	"$(sed -n 's/^ *"version": *"\([^"]*\)".*/\1/p' "$SRC/blocks/embed/block.json" | head -1)"
expect "Stable tag in readme.txt" \
	"$(sed -n 's/^Stable tag: *//p' "$SRC/readme.txt" | tr -d '[:space:]')"

# Plugin Update Checker shows this entry in the site's update modal.
grep -qxF "= $VERSION =" "$SRC/readme.txt" ||
	fail "readme.txt has no '= $VERSION =' changelog entry."

REMOTE_TAGS=$(git ls-remote --tags --refs origin 'v*')
TAGS=$(printf '%s\n' "$REMOTE_TAGS" | sed 's|.*refs/tags/v||' | grep -E '^[0-9]+\.[0-9]+\.[0-9]+$' || true)
NEWEST_OTHER=$(printf '%s\n' "$TAGS" | grep -vxF "$VERSION" | sort -V | tail -1 || true)
if [ -n "$NEWEST_OTHER" ] &&
	[ "$(printf '%s\n%s\n' "$NEWEST_OTHER" "$VERSION" | sort -V | tail -1)" != "$VERSION" ]; then
	fail "Version $VERSION is not newer than the released v$NEWEST_OTHER."
fi
if grep -qxF "$VERSION" <<<"$TAGS"; then
	PENDING=false
else
	PENDING=true
fi

# ⚠ The zip's top folder must be named `sahaj-atlas`. GitHub's own "Source code (zip)" names that
# folder `SahajAtlasWordpress-<tag>` instead. WordPress installs an update into the folder the zip
# names. A wrong folder name installs a second, separate plugin next to the first. Both copies then
# run. Each one refuses the other's `<sahaj-atlas>` element.
#
# The slug must match `SAHAJ_ATLAS_SLUG` in `includes/updates.php`. Never let the two values diverge.
ZIP="sahaj-atlas-$VERSION.zip"
rm -rf "$OUT/sahaj-atlas" "${OUT:?}/$ZIP"
mkdir -p "$OUT/sahaj-atlas"
rsync -a --exclude-from="$SRC/.distignore" "$SRC/" "$OUT/sahaj-atlas/"
(cd "$OUT" && zip -qr "$ZIP" sahaj-atlas)

LISTING=$(unzip -Z1 "$OUT/$ZIP")

[ "$(printf '%s\n' "$LISTING" | cut -d/ -f1 | sort -u)" = "sahaj-atlas" ] ||
	fail "The zip must hold one top-level folder, sahaj-atlas."

# Without the update checker, an installed copy never hears of the next release.
for required in sahaj-atlas/sahaj-atlas.php sahaj-atlas/vendor/plugin-update-checker/plugin-update-checker.php; do
	grep -qxF "$required" <<<"$LISTING" || fail "The zip is missing $required."
done

LEAKED=$(printf '%s\n' "$LISTING" |
	grep -E '^sahaj-atlas/(\.git|\.github|\.claude|tests|docs|node_modules|build)/|^sahaj-atlas/(package\.json|pnpm-lock\.yaml|\.mcp\.json|AGENTS\.md|CLAUDE\.md|README\.md)$' ||
	true)
[ -z "$LEAKED" ] ||
	fail "Dev-only files reached the zip, so .distignore has lost a line: $(printf '%s\n' "$LEAKED" | cut -d/ -f2 | sort -u | tr '\n' ' ')"

echo "Built $OUT/$ZIP: $(printf '%s\n' "$LISTING" | grep -vc '/$') files, v$VERSION, pending release: $PENDING."
if [ -n "${GITHUB_OUTPUT:-}" ]; then
	{
		echo "version=$VERSION"
		echo "zip=$OUT/$ZIP"
		echo "pending=$PENDING"
	} >>"$GITHUB_OUTPUT"
fi
