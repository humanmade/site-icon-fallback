<?php
/**
 * Bootstrap and shared configuration.
 *
 * @package SiteIconFallback
 */

declare( strict_types=1 );

namespace SiteIconFallback;

use SiteIconFallback\CLI;
use SiteIconFallback\Site_Health;

defined( 'ABSPATH' ) || exit;

/**
 * Sizes the root handler will answer for.
 *
 * Every apple-touch-icon size Apple has shipped a device for, plus 192 for Android/Chrome.
 * A closed set, so the endpoint cannot be driven as an image-resize service.
 */
const SUPPORTED_SIZES = [ 57, 60, 72, 76, 114, 120, 144, 152, 167, 180, 192 ];

/**
 * Size used for a bare /apple-touch-icon.png carrying no dimensions in its filename.
 *
 * 180 is what current iOS devices ask for, and what core declares in the page head.
 */
const DEFAULT_TOUCH_ICON_SIZE = 180;

/**
 * Size used for /favicon.ico and /favicon.png.
 *
 * Matches core's do_favicon(), so the two paths agree on which derivative they serve.
 */
const FAVICON_SIZE = 32;

/**
 * Response header marking a response as ours.
 *
 * Site Health needs to tell our 404 from the web server's, and only this header does.
 */
const MARKER_HEADER = 'X-Site-Icon-Fallback';

/**
 * Wire up the plugin.
 *
 * @return void
 */
function bootstrap(): void {
	add_filter( 'site_icon_meta_tags', __NAMESPACE__ . '\\Meta_Tags\\filter_meta_tags' );
	add_action( 'init', __NAMESPACE__ . '\\Root_Handler\\maybe_serve_root_icon', 100 );
	add_filter( 'site_status_tests', __NAMESPACE__ . '\\Site_Health\\register_site_health_test' );
	add_action( 'wp_ajax_' . Site_Health\AJAX_ACTION, __NAMESPACE__ . '\\Site_Health\\ajax_reachability_test' );
	add_action( 'admin_notices', __NAMESPACE__ . '\\Admin_Notices\\render_missing_icon_notice' );

	register_activation_hook( PLUGIN_FILE, __NAMESPACE__ . '\\Lifecycle\\on_activation' );

	CLI\register_commands();
}

/**
 * The Site Icon URL at a given size, normalised to a string.
 *
 * get_site_icon_url() can return false despite its documented string return, so callers
 * must not compare its result to ''. See CLAUDE.md: "get_site_icon_url() does not always
 * return a string."
 *
 * @param int $size Square size in pixels.
 * @return string Icon URL, or an empty string when there is none to serve.
 */
function get_icon_url( int $size ): string {
	$url = get_site_icon_url( $size );

	return is_string( $url ) ? $url : '';
}

/**
 * How long clients and edge caches may keep the icon itself.
 *
 * Cacheable hard: a Site Icon change also changes the URL, so stale bytes cannot surface
 * under a live address.
 *
 * @return int Seconds.
 */
function get_content_max_age(): int {
	/**
	 * Filters the cache lifetime of a served icon.
	 *
	 * @param int $max_age Lifetime in seconds.
	 */
	return (int) apply_filters( 'site_icon_fallback_content_max_age', DAY_IN_SECONDS );
}

/**
 * How long a redirect to the icon may be cached.
 *
 * Much shorter than the icon itself. A redirect is a pointer, and changing the Site Icon
 * deletes what it points at, leaving cached copies replaying into a 404.
 *
 * @return int Seconds.
 */
function get_redirect_max_age(): int {
	/**
	 * Filters the cache lifetime of a root icon redirect.
	 *
	 * @param int $max_age Lifetime in seconds.
	 */
	return (int) apply_filters( 'site_icon_fallback_redirect_max_age', 5 * MINUTE_IN_SECONDS );
}

/**
 * How long a failed icon fetch is remembered.
 *
 * A transient lifetime, not a Cache-Control value. Without it, a slow or unreachable icon
 * host costs a blocking HTTP request on every probe. Kept short because the failure is
 * usually temporary and a redirect still works in the meantime.
 *
 * @return int Seconds.
 */
function get_failure_cache_lifetime(): int {
	/**
	 * Filters how long a failed icon fetch is remembered before being retried.
	 *
	 * @param int $lifetime Lifetime in seconds.
	 */
	return (int) apply_filters( 'site_icon_fallback_failure_cache_lifetime', 5 * MINUTE_IN_SECONDS );
}

/**
 * How long a "no icon available" 404 may be cached.
 *
 * Short: this usually means no Site Icon is set yet, and setting one should take effect
 * promptly rather than after a day of cached 404s.
 *
 * @return int Seconds.
 */
function get_missing_max_age(): int {
	/**
	 * Filters the cache lifetime of a 404 for an unavailable icon.
	 *
	 * @param int $max_age Lifetime in seconds.
	 */
	return (int) apply_filters( 'site_icon_fallback_missing_max_age', 5 * MINUTE_IN_SECONDS );
}
