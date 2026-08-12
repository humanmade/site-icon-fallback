<?php
/**
 * Activation and deactivation, including multisite.
 *
 * @package SiteIconFallback
 */

declare( strict_types=1 );

namespace SiteIconFallback\Lifecycle;

use SiteIconFallback\Htaccess;

defined( 'ABSPATH' ) || exit;

/**
 * Network option listing the sites with the plugin individually active.
 *
 * Server rules are install-wide rather than per-site: one .htaccess, one nginx config.
 * That makes deactivation on a network dangerous, because tearing the rules down for the
 * site in front of you would break every other site still relying on them. Tracking who
 * is active makes the last one out responsible for turning off the lights.
 *
 * Network activation does not populate this — it activates everywhere at once and
 * deactivates the same way, so there is nothing to count.
 */
const ACTIVE_SITES_OPTION = 'site_icon_fallback_active_sites';

/**
 * Add server rules when the plugin is activated.
 *
 * @param bool $network_wide Whether the plugin was network activated.
 * @return void
 */
function on_activation( bool $network_wide = false ): void {
	if ( is_multisite() && ! $network_wide ) {
		set_active_sites( array_merge( get_active_sites(), [ get_current_blog_id() ] ) );
	}

	Htaccess\maybe_write();
}

/**
 * Remove server rules when the plugin is deactivated.
 *
 * Leaving them behind is worse than never having added them: the rewrite would keep
 * handing icon requests to WordPress, which no longer has anything to answer them with,
 * so every request would render a full themed 404 in place of the web server's tiny one.
 *
 * @param bool $network_wide Whether the plugin was network deactivated.
 * @return void
 */
function on_deactivation( bool $network_wide = false ): void {
	if ( is_multisite() && ! $network_wide ) {
		set_active_sites( array_diff( get_active_sites(), [ get_current_blog_id() ] ) );

		if ( get_active_sites() !== [] ) {
			return;
		}
	}

	if ( $network_wide ) {
		set_active_sites( [] );
	}

	Htaccess\maybe_remove();
}

/**
 * Sites with the plugin individually active.
 *
 * @return int[] Blog IDs.
 */
function get_active_sites(): array {
	$sites = get_site_option( ACTIVE_SITES_OPTION, [] );

	if ( ! is_array( $sites ) ) {
		return [];
	}

	return array_values( array_unique( array_map( 'intval', $sites ) ) );
}

/**
 * Record which sites have the plugin individually active.
 *
 * @param array<int, int|string> $sites Blog IDs.
 * @return void
 */
function set_active_sites( array $sites ): void {
	update_site_option(
		ACTIVE_SITES_OPTION,
		array_values( array_unique( array_map( 'intval', $sites ) ) )
	);
}
