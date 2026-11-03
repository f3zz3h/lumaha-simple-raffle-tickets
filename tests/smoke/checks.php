<?php
/**
 * Smoke test checks, run with `wp eval-file checks.php <name>` by run.sh.
 *
 * @package Lumaha_Simple_Raffle_Tickets
 */

$lsrt_check = isset( $args[0] ) ? $args[0] : '';
$lsrt_fails = 0;

/**
 * Report one check.
 *
 * @param bool   $ok    Passed.
 * @param string $label What was checked.
 */
function lsrt_check( $ok, $label ) {
	global $lsrt_fails;
	echo ( $ok ? 'ok   ' : 'FAIL ' ) . $label . "\n";
	if ( ! $ok ) {
		$lsrt_fails++;
	}
}

$lsrt_file = 'lumaha-simple-raffle-tickets/lumaha-simple-raffle-tickets.php';

switch ( $lsrt_check ) {
	case 'github':
		lsrt_check( is_plugin_active( $lsrt_file ), 'plugin is active' );
		lsrt_check( class_exists( 'Lumaha_Simple_Raffle_Tickets' ), 'plugin class loaded' );
		lsrt_check( class_exists( 'LSRT_GitHub_Updater' ), 'GitHub updater loaded' );
		lsrt_check( (bool) has_filter( 'update_plugins_github.com' ), 'updater answers update checks for github.com' );

		// A fake newer release, so the check doesn't depend on GitHub.
		add_filter(
			'pre_http_request',
			static function ( $pre, $parsed_args, $url ) {
				if ( false === strpos( $url, 'api.github.com/repos/f3zz3h/lumaha-simple-raffle-tickets/releases/latest' ) ) {
					return $pre;
				}
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'tag_name'     => 'v99.0.0',
							'html_url'     => 'https://github.com/f3zz3h/lumaha-simple-raffle-tickets/releases/tag/v99.0.0',
							'body'         => 'Test release',
							'published_at' => '2026-01-01T00:00:00Z',
							'draft'        => false,
							'prerelease'   => false,
							'assets'       => array(
								array(
									'name'                 => 'lumaha-simple-raffle-tickets-99.0.0.zip',
									'browser_download_url' => 'https://example.com/lumaha-simple-raffle-tickets-99.0.0.zip',
								),
							),
						)
					),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
				);
			},
			10,
			3
		);
		LSRT_GitHub_Updater::forget();
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		$lsrt_updates = get_site_transient( 'update_plugins' );
		$lsrt_offer   = isset( $lsrt_updates->response[ $lsrt_file ] ) ? $lsrt_updates->response[ $lsrt_file ] : null;
		lsrt_check( $lsrt_offer && '99.0.0' === $lsrt_offer->new_version, 'a newer GitHub release is offered as an update' );
		lsrt_check( $lsrt_offer && 'https://example.com/lumaha-simple-raffle-tickets-99.0.0.zip' === $lsrt_offer->package, 'the update installs the release zip' );

		$lsrt_info = apply_filters( 'plugins_api', false, 'plugin_information', (object) array( 'slug' => 'lumaha-simple-raffle-tickets' ) );
		lsrt_check( is_object( $lsrt_info ) && '99.0.0' === $lsrt_info->version, '"View details" shows the release' );
		LSRT_GitHub_Updater::forget();
		break;

	case 'wporg':
		lsrt_check( is_plugin_active( $lsrt_file ), 'plugin is active' );
		lsrt_check( class_exists( 'Lumaha_Simple_Raffle_Tickets' ), 'plugin class loaded' );
		lsrt_check( ! class_exists( 'LSRT_GitHub_Updater' ), 'no GitHub updater in the WordPress.org build' );
		$lsrt_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $lsrt_file, false, false );
		lsrt_check( empty( $lsrt_data['UpdateURI'] ), 'no Update URI in the WordPress.org build' );
		break;

	default:
		lsrt_check( false, "unknown check '$lsrt_check'" );
}

if ( $lsrt_fails ) {
	WP_CLI::error( "$lsrt_fails check(s) failed" );
}
