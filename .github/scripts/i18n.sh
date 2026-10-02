#!/usr/bin/env bash
# Builds the translation files for a staged copy of the plugin.
#
#   .github/scripts/i18n.sh <plugin-dir>
#
# `.po` is the only translation file anyone commits. The `.pot` template, the `.mo` that PHP reads
# and the `.json` files that `wp.i18n` reads are all built here, from the tree that is about to be
# zipped. `.github/scripts/package.sh` calls this between staging the plugin and zipping it, so the
# zip CI inspects and the zip a release publishes can never disagree.
#
# ⚠ This is release tooling, not a plugin build step. AGENTS.md principle 2 forbids a build step
# *in the plugin*: the zip must install and run with no npm and no node_modules, and it still does.
set -euo pipefail

SRC=${1:?usage: i18n.sh <plugin-dir>}

# Pinned, and checked: this phar runs on the path that publishes an auto-update to every site
# running the plugin. The hash is the one wp-cli publishes beside the release asset.
WP_CLI_VERSION=2.12.0
WP_CLI_SHA256=ce34ddd838f7351d6759068d09793f26755463b4a4610a5a5c0a97b68220d85c

fail() {
	echo "::error::$*"
	exit 1
}

command -v php >/dev/null || fail 'i18n.sh needs PHP on PATH to run wp-cli.'

# ⚠ Fetched and hashed every run, with no reuse of an existing file. A cache would have to verify
# the file it kept, or the one line that makes the pin mean anything is the one that gets skipped —
# and `RUNNER_TEMP` is fresh per job, so a cache could never have hit in CI anyway.
PHAR=${RUNNER_TEMP:-${TMPDIR:-/tmp}}/wp-cli-$WP_CLI_VERSION.phar
curl -fsSL --retry 3 -o "$PHAR" \
	"https://github.com/wp-cli/wp-cli/releases/download/v$WP_CLI_VERSION/wp-cli-$WP_CLI_VERSION.phar"
echo "$WP_CLI_SHA256  $PHAR" | sha256sum -c --quiet ||
	fail "The wp-cli $WP_CLI_VERSION download does not match the pinned SHA-256."

# ⚠ `--allow-root` is not a shortcut here. wp-cli refuses to run as root to protect a WordPress
# install, and `i18n` touches none — it reads source files. Without the flag this script fails in
# any root container, which is most of them.
wp() { php "$PHAR" --allow-root "$@"; }

mkdir -p "$SRC/languages"

wp i18n make-pot "$SRC" "$SRC/languages/sahaj-atlas.pot" \
	--domain=sahaj-atlas \
	--exclude=vendor \
	--headers='{"Report-Msgid-Bugs-To":"https://github.com/sydevs/SahajAtlasWordpress/issues"}'

# ⚠ Two readers, and shipping one without the other is the defect #17 fixed. PHP reads the `.mo`;
# `wp.i18n` in `blocks/embed/editor.js` reads a `.json` whose name carries an md5 of the script's
# registered path, which only `make-json` can compute.
if compgen -G "$SRC/languages/*.po" >/dev/null; then
	wp i18n make-mo "$SRC/languages" "$SRC/languages"
	wp i18n make-json "$SRC/languages" --no-purge
fi
