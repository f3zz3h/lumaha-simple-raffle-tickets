#!/usr/bin/env bash
# Build the installable plugin zip: dist/lumaha-simple-raffle-tickets-<version>.zip containing a
# lumaha-simple-raffle-tickets/ folder, without the development files listed in .distignore.
#
# Usage: build-zip.sh [--wporg] [expected-version]
# With an expected version (e.g. from a v0.3.0 tag), fail unless the plugin header and
# Lumaha_Simple_Raffle_Tickets::VERSION both say that version.
#
# --wporg builds the WordPress.org version instead: dist/lumaha-simple-raffle-tickets-<version>-wporg.zip,
# without the GitHub update checker and the header's Update URI (WordPress.org plugins may only
# update from WordPress.org). Its folder is kept in dist/wporg/lumaha-simple-raffle-tickets for the SVN deploy.
set -euo pipefail

WPORG=""
if [ "${1:-}" = "--wporg" ]; then
	WPORG=1
	shift
fi

SLUG="lumaha-simple-raffle-tickets"
DOMAIN="lumaha-simple-raffle-tickets"
MAIN_FILE="lumaha-simple-raffle-tickets.php"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
MAIN="$ROOT/$MAIN_FILE"

header_version="$(sed -n 's/^ \* Version:[[:space:]]*\([^[:space:]]*\).*/\1/p' "$MAIN" | head -1)"
constant_version="$(sed -n "s/^[[:space:]]*const VERSION[[:space:]]*= '\([^']*\)';.*/\1/p" "$MAIN" | head -1)"

if [ -z "$header_version" ] || [ "$header_version" != "$constant_version" ]; then
	echo "Version mismatch: plugin header says '$header_version', Lumaha_Simple_Raffle_Tickets::VERSION says '$constant_version'." >&2
	exit 1
fi

if [ -n "${1:-}" ] && [ "$1" != "$header_version" ]; then
	echo "The tag is for version $1, but the plugin says $header_version. Bump the version (header and VERSION constant) first." >&2
	exit 1
fi

if [ -f "$ROOT/readme.txt" ]; then
	stable_tag="$(sed -n 's/^Stable tag:[[:space:]]*\([^[:space:]]*\).*/\1/p' "$ROOT/readme.txt" | head -1)"
	if [ -n "$stable_tag" ] && [ "$stable_tag" != "$header_version" ]; then
		echo "readme.txt says Stable tag: $stable_tag, but the plugin says $header_version. Update the Stable tag too." >&2
		exit 1
	fi
fi

DIST="$ROOT/dist"
STAGE="$DIST/stage"
ZIP="$DIST/$SLUG-$header_version.zip"
if [ -n "$WPORG" ]; then
	STAGE="$DIST/wporg"
	ZIP="$DIST/$SLUG-$header_version-wporg.zip"
fi

rm -rf "$STAGE" "$ZIP"
mkdir -p "$STAGE/$SLUG"
rsync -a --exclude-from="$ROOT/.distignore" --exclude dist "$ROOT/" "$STAGE/$SLUG/"

if [ -n "$WPORG" ]; then
	rm "$STAGE/$SLUG/includes/class-lsrt-github-updater.php"
	rmdir "$STAGE/$SLUG/includes" 2> /dev/null || true
	sed -i '/^ \* Update URI:/d' "$STAGE/$SLUG/$MAIN_FILE"
	sed -i '/lsrt-github-updater:start/,/lsrt-github-updater:end/d' "$STAGE/$SLUG/$MAIN_FILE"
	# README.md is the GitHub page (it documents the GitHub updater); WordPress.org uses readme.txt.
	rm -f "$STAGE/$SLUG/README.md"
	if [ -f "$STAGE/$SLUG/readme.txt" ]; then
		# Changelog lines about the GitHub updater don't apply to the WordPress.org copy.
		sed -i '/installed from GitHub\|installed from the GitHub release zip/d' "$STAGE/$SLUG/readme.txt"
	else
		echo "Warning: no readme.txt, which WordPress.org needs." >&2
	fi
	if grep -q '^ \* Update URI:' "$STAGE/$SLUG/$MAIN_FILE" || grep -rqi 'github-updater\|GitHub_Updater\|api\.github\.com\|installed from GitHub' "$STAGE/$SLUG"; then
		echo "The WordPress.org build still refers to the GitHub updater." >&2
		exit 1
	fi
fi

# Refresh the translation template from the code being shipped (needs WP-CLI; skipped otherwise).
if command -v wp > /dev/null; then
	mkdir -p "$STAGE/$SLUG/languages"
	wp i18n make-pot "$STAGE/$SLUG" "$STAGE/$SLUG/languages/$DOMAIN.pot" --slug="$DOMAIN" --domain="$DOMAIN" \
		--headers='{"Report-Msgid-Bugs-To":"https://github.com/f3zz3h/lumaha-simple-raffle-tickets/issues"}' --quiet
	echo "Translation template refreshed ($(grep -c '^msgid "' "$STAGE/$SLUG/languages/$DOMAIN.pot") strings)"
fi

if command -v zip > /dev/null; then
	(cd "$STAGE" && zip -qr -X "$ZIP" "$SLUG")
else
	# No zip program (e.g. a minimal WSL): Python's zipfile does the same job.
	python3 -c 'import shutil, sys; shutil.make_archive(sys.argv[1][:-4], "zip", sys.argv[2], sys.argv[3])' "$ZIP" "$STAGE" "$SLUG"
fi
[ -n "$WPORG" ] || rm -rf "$STAGE"

echo "Built $ZIP ($(du -h "$ZIP" | cut -f1)), version $header_version"
if [ -n "${GITHUB_OUTPUT:-}" ]; then
	{ echo "zip=$ZIP"; echo "version=$header_version"; echo "name=$(basename "$ZIP")"; } >> "$GITHUB_OUTPUT"
fi
