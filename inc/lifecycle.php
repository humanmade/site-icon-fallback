<?php
/**
 * Activation.
 *
 * @package SiteIconFallback
 */

declare( strict_types=1 );

namespace SiteIconFallback\Lifecycle;

use SiteIconFallback\Server_Config;

defined( 'ABSPATH' ) || exit;

/**
 * Refuse to activate on anything but nginx.
 *
 * The plugin generates nginx configuration and nothing else, so on another server it can
 * offer no answer to the one problem it exists to report. Failing at activation says so
 * once, in front of the person who can act on it, rather than leaving a plugin that looks
 * installed and silently is not the thing they wanted.
 *
 * wp_die() here is enough on its own: core fires this hook before it writes the plugin into
 * the active_plugins option (wp-admin/includes/plugin.php), so stopping here means the
 * plugin is never recorded as active. There is nothing to undo.
 *
 * Never fires for a plugin loaded from mu-plugins, where activation does not exist.
 *
 * @return void
 */
function on_activation(): void {
	if ( ! nginx_is_required() || Server_Config\is_nginx() ) {
		return;
	}

	wp_die(
		wp_kses(
			sprintf(
				/* translators: %s: the site_icon_fallback_require_nginx filter name, in code tags. */
				__( 'Site Icon Fallback only supports nginx, and this server does not report itself as nginx. If that detection is wrong — nginx proxying to Apache reports Apache, and WP-CLI may report nothing — return false from the %s filter in an mu-plugin to activate anyway.', 'site-icon-fallback' ),
				'<code>site_icon_fallback_require_nginx</code>'
			),
			[ 'code' => [] ]
		),
		esc_html__( 'Plugin could not be activated', 'site-icon-fallback' ),
		[ 'back_link' => true ]
	);
}

/**
 * Whether activation is gated on nginx being detected.
 *
 * The escape hatch for the detection being wrong. Core has no reliable answer for what is
 * in front of PHP — it reads a header the server chooses to send — so an install that knows
 * better must be able to say so, rather than being locked out by a missing string.
 *
 * @return bool
 */
function nginx_is_required(): bool {
	/**
	 * Filters whether the plugin refuses to activate on a server it cannot identify as nginx.
	 *
	 * @param bool $required Whether nginx detection gates activation.
	 */
	return (bool) apply_filters( 'site_icon_fallback_require_nginx', true );
}
