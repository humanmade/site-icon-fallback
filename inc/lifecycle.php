<?php
/**
 * Activation.
 *
 * @package SiteIconFallback
 */

declare( strict_types=1 );

namespace SiteIconFallback\Lifecycle;

use SiteIconFallback\CLI;
use SiteIconFallback\Server_Config;

defined( 'ABSPATH' ) || exit;

/**
 * Refuse to activate on a server that reports itself as something other than nginx.
 *
 * The plugin only generates nginx configuration, so elsewhere it can do nothing about the
 * problem it reports. wp_die() is the whole mechanism: core fires this hook before writing
 * active_plugins, so there is nothing to undo. Never runs from mu-plugins.
 * See CLAUDE.md: "Activation is refused when the server reports itself as non-nginx."
 *
 * @return void
 */
function on_activation(): void {
	if ( ! nginx_is_required() || Server_Config\is_nginx() ) {
		return;
	}

	// Nothing identified itself: this is WP-CLI, which never sets SERVER_SOFTWARE. Refusing
	// here would block every scripted deploy, and Site Health still reports the truth once
	// real requests arrive.
	if ( Server_Config\get_server_software() === '' ) {
		CLI\warn(
			__( 'Site Icon Fallback supports nginx only. No web server was available to check against, so activation went ahead — run `wp site-icon-fallback status` against the live site to confirm it works.', 'site-icon-fallback' )
		);

		return;
	}

	wp_die(
		wp_kses(
			sprintf(
				/* translators: %s: the site_icon_fallback_require_nginx filter name, in code tags. */
				__( 'Site Icon Fallback only supports nginx, and this server reports itself as something else. If that is wrong — nginx proxying to Apache reports Apache — return false from the %s filter in an mu-plugin to activate anyway.', 'site-icon-fallback' ),
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
 * Detection reads a header the server chooses to send, and nginx proxying to Apache reports
 * Apache. Without this filter, a wrong answer locks an install out of its own plugin.
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
