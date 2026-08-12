<?php
/**
 * Bootstrap and shared configuration.
 *
 * @package SiteIconFallback
 */

declare( strict_types=1 );

namespace SiteIconFallback;

use SiteIconFallback\Site_Health;

defined( 'ABSPATH' ) || exit;

/**
 * Sizes the root handler will answer for.
 *
 * Covers every apple-touch-icon size Apple has shipped a device for, plus the 192px
 * Android/Chrome size. Sizes outside this list are refused rather than passed to image
 * resizing, so the endpoint cannot be used to generate arbitrary derivatives on demand.
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
 * The Site Health check needs to tell our 404 apart from the web server's 404, which is
 * the whole question it exists to answer. A marker header is unambiguous where a status
 * code is not.
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
	register_deactivation_hook( PLUGIN_FILE, __NAMESPACE__ . '\\Lifecycle\\on_deactivation' );
}

/**
 * The Site Icon URL at a given size, normalised to a string.
 *
 * get_site_icon_url() is documented as returning a string and does not. It hands back
 * whatever wp_get_attachment_image_url() returned, which is `false` when the `site_icon`
 * option names an attachment that no longer exists — and nothing reliably clears that
 * option, because the hook that would (WP_Site_Icon::delete_attachment_data) is registered
 * only inside one admin AJAX action. A `get_site_icon_url` filter may return anything at
 * all for the same reason core's own callers test the result for truthiness rather than
 * comparing it to ''.
 *
 * Comparing `=== ''` therefore lets `false` past the guard, and passing it into a string
 * parameter under strict_types is a fatal on the one code path built for anonymous
 * traffic. Normalising here keeps that judgement in a single place.
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
 * Safe to keep for a while because it is the content, not a pointer to it: if the Site
 * Icon changes the URL changes with it, so nothing can serve the wrong bytes under the
 * right address.
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
 * Deliberately far shorter than the icon itself. A redirect is a pointer, and its target
 * stops existing the moment someone changes the Site Icon — at which point every client
 * holding a cached copy replays it into a 404 until the cache expires. Caching a pointer
 * for as long as the thing it points at is how a 302 acquires the worst property of a 301.
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
 * Server-side only: this is a transient lifetime, not a Cache-Control value. Without it a
 * Site Icon whose host is slow, down or 404ing costs a fresh blocking HTTP request on
 * every probe, and the traffic on these paths is anonymous crawlers and unfurlers arriving
 * in bursts — each one holding a PHP worker open for the timeout.
 *
 * Deliberately far shorter than the icon itself: the failure is usually temporary, and
 * until it expires the plugin is falling back to a redirect rather than serving nothing.
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
 * Deliberately short: this response usually means the site has no Site Icon yet, and
 * setting one should take effect promptly rather than after a day of cached 404s.
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
