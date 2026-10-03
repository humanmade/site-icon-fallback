<?php
/**
 * Serves icon requests made against the site root.
 *
 * @package SiteIconFallback
 */

declare( strict_types=1 );

namespace SiteIconFallback\Root_Handler;

use SiteIconFallback;
use SiteIconFallback\Icon_Fetch;
use SiteIconFallback\Icon_Stream;

defined( 'ABSPATH' ) || exit;

/**
 * Matches apple-touch-icon.png along with its precomposed and sized variants.
 *
 * The branch reset `(?|` lets every alternative number its dimensions 1 and 2, so
 * suffix order costs no extra groups. See CLAUDE.md: "-precomposed goes on either side."
 */
const TOUCH_ICON_PATTERN = '#^apple-touch-icon(?:-precomposed|(?|-(\d+)x(\d+)-precomposed|-precomposed-(\d+)x(\d+)|-(\d+)x(\d+)))?\.png$#';

/** Matches favicon.ico and favicon.png. */
const FAVICON_PATTERN = '#^favicon\.(?:ico|png)$#';

/**
 * Answer a root icon request from the Site Icon.
 *
 * Hooked late on `init`: after media and CDN URL filters register, before the main query
 * runs. See CLAUDE.md: "Path matching on init:100, not a rewrite rule."
 *
 * @return void
 */
function maybe_serve_root_icon(): void {
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
		return;
	}

	$path = get_request_path();

	if ( $path === '' ) {
		return;
	}

	if ( preg_match( TOUCH_ICON_PATTERN, $path, $matches ) === 1 ) {
		serve_icon( resolve_touch_icon_size( $matches ) );
	}

	if ( preg_match( FAVICON_PATTERN, $path ) === 1 ) {
		serve_icon( SiteIconFallback\FAVICON_SIZE );
	}
}

/**
 * The requested path, relative to the site root and stripped of its query string.
 *
 * Subdirectory installs are handled by removing the home URL's own path, so that
 * /blog/apple-touch-icon.png resolves the same way /apple-touch-icon.png does.
 *
 * @return string Path with no leading slash, or an empty string when unavailable.
 */
function get_request_path(): string {
	if ( empty( $_SERVER['REQUEST_URI'] ) ) {
		return '';
	}

	$request_uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
	$path        = wp_parse_url( $request_uri, PHP_URL_PATH );

	if ( ! is_string( $path ) ) {
		return '';
	}

	$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );

	if ( $home_path !== '' && $home_path !== '/' && str_starts_with( $path, $home_path ) ) {
		$path = substr( $path, strlen( $home_path ) );
	}

	return ltrim( $path, '/' );
}

/**
 * Resolve the icon size for a matched apple-touch-icon request.
 *
 * Only a square, supported size is served as itself. Any other dimensions get the default,
 * so apple-touch-icon-9999x9999.png gets an icon without driving image generation.
 * See CLAUDE.md: "Sizes are allow-listed."
 *
 * @param array<int, string> $matches Matches produced by TOUCH_ICON_PATTERN.
 * @return int Size in pixels, or 0 when the fallback is filtered off and the request gets a 404.
 */
function resolve_touch_icon_size( array $matches ): int {
	if ( ! isset( $matches[1] ) || ! isset( $matches[2] ) ) {
		return SiteIconFallback\DEFAULT_TOUCH_ICON_SIZE;
	}

	$width  = (int) $matches[1];
	$height = (int) $matches[2];

	if ( $width === $height && in_array( $width, SiteIconFallback\SUPPORTED_SIZES, true ) ) {
		return $width;
	}

	/**
	 * Filters whether an unsupported or non-square size gets the default icon instead of a 404.
	 *
	 * @param bool $enabled Whether to serve DEFAULT_TOUCH_ICON_SIZE.
	 */
	$fallback_enabled = (bool) apply_filters( 'site_icon_fallback_default_for_unsupported_sizes', true );

	return $fallback_enabled ? SiteIconFallback\DEFAULT_TOUCH_ICON_SIZE : 0;
}

/**
 * Answer a request for the Site Icon at a given size, or send a 404.
 *
 * @param int $size Size in pixels. Zero sends a 404.
 * @return void
 */
function serve_icon( int $size ): void {
	$url = $size > 0 ? SiteIconFallback\get_icon_url( $size ) : '';

	if ( $url === '' ) {
		send_missing_icon();
	}

	if ( get_serve_mode() === 'stream' ) {
		$icon = Icon_Fetch\fetch_icon( $url, $size );

		if ( $icon !== null ) {
			Icon_Stream\send_icon_bytes( $icon );
		}

		// Reading the bytes failed; a redirect still gets the client to an icon.
	}

	send_icon_redirect( $url );
}

/**
 * How root icon requests are answered.
 *
 * @return string Either 'stream' or 'redirect'.
 */
function get_serve_mode(): string {
	/**
	 * Filters how root icon requests are answered.
	 *
	 * 'stream' returns the image bytes with a 200, so clients that do not follow
	 * redirects still get an icon. 'redirect' sends a 302, which costs no PHP time
	 * but assumes the client follows it.
	 *
	 * @param string $mode Either 'stream' or 'redirect'.
	 */
	$mode = apply_filters( 'site_icon_fallback_serve_mode', 'stream' );

	return $mode === 'redirect' ? 'redirect' : 'stream';
}

/**
 * Redirect to the Site Icon and stop.
 *
 * A 302, cached briefly. Changing the Site Icon deletes what this points at, so anything
 * longer-lived leaves clients replaying a redirect into a 404.
 *
 * @param string $url Site Icon URL.
 * @return void
 */
function send_icon_redirect( string $url ): void {
	header( SiteIconFallback\MARKER_HEADER . ': redirect' );
	header( 'Cache-Control: public, max-age=' . SiteIconFallback\get_redirect_max_age() );

	// Not wp_safe_redirect(): the Site Icon legitimately lives on a CDN host, which the
	// safe variant would reject and rewrite back to the home URL.
	wp_redirect( $url, 302 );

	exit;
}

/**
 * Send a 404 for an icon that cannot be supplied.
 *
 * Not core's do_favicon() behaviour, which falls back to the WordPress logo. A site with
 * no Site Icon should look like it has no icon, not like WordPress.
 *
 * @return void
 */
function send_missing_icon(): void {
	header( SiteIconFallback\MARKER_HEADER . ': missing' );
	header( 'Cache-Control: public, max-age=' . SiteIconFallback\get_missing_max_age() );
	status_header( 404 );

	exit;
}
