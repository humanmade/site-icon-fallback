<?php
/**
 * Reports whether root icon requests actually reach WordPress.
 *
 * @package SiteIconFallback
 */

declare( strict_types=1 );

namespace SiteIconFallback\Site_Health;

use SiteIconFallback;
use SiteIconFallback\Server_Config;

defined( 'ABSPATH' ) || exit;

/** Caches the loopback result so the dashboard does not re-request on every load. */
const REACHABILITY_TRANSIENT = 'site_icon_fallback_reachable';

/**
 * Identifier for the test, and the tail of its Ajax action.
 *
 * Deliberately carries no underscores. Site Health's JavaScript builds the action name as
 * `'health-check-' + test.replace( '_', '-' )`, and a string argument to replace() swaps
 * only the first match — so `site_icon_fallback_root` would ask for
 * `health-check-site-icon_fallback_root`. Core's own async tests each happen to contain a
 * single underscore, which is why nothing in core trips over it.
 */
const TEST_SLUG = 'site-icon-fallback-root';

/** Ajax action Site Health calls to run the test. */
const AJAX_ACTION = 'health-check-' . TEST_SLUG;

/**
 * Register the Site Health test.
 *
 * Registered as async, not direct. Direct tests are run inline while the Site Health page
 * renders, and this one makes a loopback HTTP request with a three-second timeout — core
 * registers its own loopback test as async for exactly that reason. The transient bounds
 * how often the request happens but not what it costs when it does.
 *
 * async_direct_test is what the weekly cron run uses. Without it, cron posts the raw test
 * slug to admin-ajax as the action name rather than the prefixed one the browser sends,
 * so the test would report itself unavailable on every scheduled run.
 *
 * @param array<string, array<string, mixed>> $tests Registered tests.
 * @return array<string, array<string, mixed>> Filtered tests.
 */
function register_site_health_test( array $tests ): array {
	$tests['async'][ TEST_SLUG ] = [
		'label'             => __( 'Root icon requests reach WordPress', 'site-icon-fallback' ),
		'test'              => TEST_SLUG,
		'async_direct_test' => __NAMESPACE__ . '\\run_reachability_test',
	];

	return $tests;
}

/**
 * Answer Site Health's Ajax request for this test.
 *
 * @return void
 */
function ajax_reachability_test(): void {
	check_ajax_referer( 'health-check-site-status' );

	if ( ! current_user_can( 'view_site_health_checks' ) ) {
		wp_send_json_error();
	}

	wp_send_json_success( run_reachability_test() );
}

/**
 * Report whether the root handler is reachable and has an icon to serve.
 *
 * Two independent things can be wrong and they need different fixes, so they are reported
 * separately: the web server may never pass these paths to PHP, or it may pass them
 * through to a site that has no Site Icon set.
 *
 * @return array<string, mixed> Site Health result.
 */
function run_reachability_test(): array {
	$result = [
		'label'       => __( 'Root icon requests reach WordPress', 'site-icon-fallback' ),
		'status'      => 'good',
		'badge'       => [
			'label' => __( 'Site Icon', 'site-icon-fallback' ),
			'color' => 'blue',
		],
		'description' => '<p>' . esc_html__(
			'Requests for /apple-touch-icon.png and /favicon.ico reach WordPress and are answered from your Site Icon.',
			'site-icon-fallback'
		) . '</p>',
		'actions'     => '',
		'test'        => TEST_SLUG,
	];

	if ( SiteIconFallback\get_icon_url( SiteIconFallback\DEFAULT_TOUCH_ICON_SIZE ) === '' ) {
		$result['status']      = 'recommended';
		$result['label']       = __( 'No Site Icon is set', 'site-icon-fallback' );
		$result['description'] = '<p>' . esc_html__(
			'Root icon requests reach WordPress, but there is no Site Icon to serve, so they return a 404. Set a Site Icon to fix this.',
			'site-icon-fallback'
		) . '</p>';
		$result['actions']     = sprintf(
			'<p><a href="%s">%s</a></p>',
			esc_url( admin_url( 'options-general.php' ) ),
			esc_html__( 'Set a Site Icon', 'site-icon-fallback' )
		);

		return $result;
	}

	if ( is_root_handler_reachable() ) {
		return $result;
	}

	$result['status']      = 'recommended';
	$result['label']       = __( 'Root icon requests do not reach WordPress', 'site-icon-fallback' );
	$result['description'] = '<p>' . esc_html__(
		'Your web server answers /apple-touch-icon.png itself instead of passing it to WordPress, so Safari, Applebot and link unfurlers get a 404. Add the rule below to your server configuration to fix this.',
		'site-icon-fallback'
	) . '</p>';
	$result['actions']     = sprintf(
		'<pre style="overflow:auto;padding:1em;background:#f6f7f7;">%s</pre>',
		esc_html( Server_Config\get_server_snippet() )
	);

	return $result;
}

/**
 * Whether a root icon request is answered by this plugin.
 *
 * Checks for our marker header rather than the status code. A 404 alone is ambiguous:
 * it is what the web server sends when it never routed the request, and also what this
 * plugin sends when there is no icon to serve. Only the header distinguishes them.
 *
 * @return bool
 */
function is_root_handler_reachable(): bool {
	$cached = get_transient( REACHABILITY_TRANSIENT );

	if ( is_string( $cached ) ) {
		return $cached === 'yes';
	}

	// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- vip_safe_wp_remote_get() is not available outside VIP, and this plugin is distributed standalone. The call is admin-only, cached, and its failure mode is already handled below.
	$response = wp_remote_get(
		home_url( '/apple-touch-icon.png' ),
		[
			'timeout'     => 3,
			'redirection' => 0,
		]
	);

	$reachable = ! is_wp_error( $response )
		&& wp_remote_retrieve_header( $response, strtolower( SiteIconFallback\MARKER_HEADER ) ) !== '';

	set_transient( REACHABILITY_TRANSIENT, $reachable ? 'yes' : 'no', 5 * MINUTE_IN_SECONDS );

	return $reachable;
}
