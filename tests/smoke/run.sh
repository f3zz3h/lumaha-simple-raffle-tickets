#!/usr/bin/env bash
# Install-from-zip smoke test.
#
# Needs a WordPress install at $WP_PATH (database configured, WP_DEBUG_LOG on) with
# WooCommerce installed, and the two built zips in $DIST. Used by .github/workflows/smoke_test.yml.
set -euo pipefail

WP_PATH="${WP_PATH:?Set WP_PATH to the WordPress install}"
DIST="${DIST:?Set DIST to the folder with the built zips}"
WP_CLI="${WP_CLI:-wp}"
PLUGIN="lumaha-simple-raffle-tickets"
CHECKS="$(cd "$(dirname "$0")" && pwd)/checks.php"
LOG="$WP_PATH/wp-content/debug.log"

wp() { command "$WP_CLI" --path="$WP_PATH" "$@"; }

install_zip() {
	rm -rf "$WP_PATH/wp-content/plugins/$PLUGIN"
	unzip -q "$1" -d "$WP_PATH/wp-content/plugins/"
	wp plugin activate "$PLUGIN"
}

stage() { echo; echo "== $1"; }

wp plugin activate woocommerce

stage "GitHub zip"
install_zip "$(ls "$DIST"/$PLUGIN-*.zip | grep -v -- '-wporg\.zip$')"
wp eval-file "$CHECKS" github
wp plugin deactivate "$PLUGIN"

stage "WordPress.org zip"
install_zip "$(ls "$DIST"/$PLUGIN-*-wporg.zip)"
wp eval-file "$CHECKS" wporg

# Only errors raised in the plugin's own files count (WooCommerce logs notices of its own).
stage "PHP errors from the plugin"
if [ -f "$LOG" ] && grep -E "PHP (Fatal error|Parse error|Warning|Notice|Deprecated).*plugins/$PLUGIN/" "$LOG"; then
	echo "FAIL the plugin logged PHP errors (above)" >&2
	exit 1
fi
echo "ok   none logged"
