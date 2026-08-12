<?php
/**
 * Getting the Site Icon's bytes, from wherever they live.
 *
 * Local disk first, then HTTP, with the result cached either way. Emitting them is
 * inc/icon-stream.php's job.
 *
 * @package SiteIconFallback
 */

declare( strict_types=1 );

namespace SiteIconFallback\Icon_Fetch;

use SiteIconFallback;

defined( 'ABSPATH' ) || exit;

/** Prefix for the cached-bytes transient. */
const BYTES_TRANSIENT_PREFIX = 'site_icon_fallback_bytes_';

/**
 * Largest icon we will hold in the object cache.
 *
 * A square PNG large enough to be a site icon lands well under this. The cap exists so a
 * misconfigured Site Icon pointing at a huge original cannot fill the cache.
 */
const MAX_ICON_BYTES = 512 * KB_IN_BYTES;

/**
 * Cached in place of the bytes when an icon could not be fetched.
 *
 * A string rather than `false`, because a transient holding `false` is indistinguishable
 * from one that has expired.
 */
const FETCH_FAILED = 'failed';

/**
 * Content types this plugin is willing to serve.
 *
 * These bytes go out under a .png or .ico URL, from the site's own origin, and are held
 * for a day. A closed list is what stops two things being laundered through that: an image
 * CDN's 200 HTML error page, which would otherwise be cached and echoed as text/html; and
 * an SVG Site Icon, which a browser will run script from on direct navigation. Anything
 * not listed here falls through to a redirect, which at least points at the real file
 * under its own name.
 */
const ALLOWED_TYPES = [
	'image/png',
	'image/jpeg',
	'image/gif',
	'image/webp',
	'image/avif',
	'image/bmp',
	'image/x-icon',
	'image/vnd.microsoft.icon',
];

/**
 * The icon at a URL, as bytes plus content type.
 *
 * Reads from disk when the URL maps into the uploads directory, and falls back to an HTTP
 * request when it does not — which is the case on any install where a CDN or an image
 * service rewrites the URL away from the local path.
 *
 * @param string $url Site Icon URL.
 * @return array{body: string, type: string}|null Null when the icon could not be read.
 */
function fetch_icon( string $url ): ?array {
	$key    = BYTES_TRANSIENT_PREFIX . md5( $url );
	$cached = get_transient( $key );

	if ( is_array( $cached ) && isset( $cached['body'] ) && isset( $cached['type'] ) ) {
		return $cached;
	}

	// A remembered failure. Retrying on every request is what turns a slow or unreachable
	// icon host into a blocking three-second wait per probe, on the one code path built
	// for bot traffic.
	if ( $cached === FETCH_FAILED ) {
		return null;
	}

	$icon = read_local_icon( $url ) ?? request_icon( $url );

	if ( $icon === null ) {
		set_transient( $key, FETCH_FAILED, SiteIconFallback\get_failure_cache_lifetime() );

		return null;
	}

	set_transient( $key, $icon, SiteIconFallback\get_content_max_age() );

	return $icon;
}

/**
 * Read the icon from the uploads directory.
 *
 * Only URLs sitting under the uploads base URL are considered, which is what keeps this
 * from being coaxed into reading an arbitrary path.
 *
 * @param string $url Site Icon URL.
 * @return array{body: string, type: string}|null Null when the URL is not a local upload.
 */
function read_local_icon( string $url ): ?array {
	$uploads = wp_upload_dir();
	$path    = (string) strtok( $url, '?' );

	if ( empty( $uploads['baseurl'] ) || empty( $uploads['basedir'] ) ) {
		return null;
	}

	if ( ! str_starts_with( $path, $uploads['baseurl'] ) ) {
		return null;
	}

	$file = $uploads['basedir'] . substr( $path, strlen( $uploads['baseurl'] ) );

	// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_is_readable -- Reading a file inside the uploads directory this install owns.
	if ( ! is_readable( $file ) ) {
		return null;
	}

	// Checked before the read, not after it. The point of the cap is to not pull a
	// multi-megabyte original into memory in the first place, and this is the path that
	// reaches it: a Site Icon set with `wp option update site_icon <id>` never generates
	// the site_icon-* derivatives, so the URL resolves to the full-size upload.
	$bytes = filesize( $file );

	if ( $bytes === false || $bytes > MAX_ICON_BYTES ) {
		return null;
	}

	$filetype = wp_check_filetype( $file );
	$declared = is_string( $filetype['type'] ?? null ) ? $filetype['type'] : '';
	$type     = get_servable_type( $declared );

	if ( $type === null ) {
		return null;
	}

	// phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local uploads file, already constrained to the uploads directory above.
	$body = file_get_contents( $file );

	if ( ! is_string( $body ) || $body === '' ) {
		return null;
	}

	return [
		'body' => $body,
		'type' => $type,
	];
}

/**
 * The content type to serve for a declared type, or null when it is not one we will serve.
 *
 * Compares the media type alone: parameters carry no bearing on what the bytes are, and
 * the type itself is case-insensitive, so `IMAGE/PNG; charset=binary` is the same claim as
 * `image/png`.
 *
 * @param string $declared Content-Type header value, or a type from wp_check_filetype().
 * @return string|null Null when the type is not in ALLOWED_TYPES.
 */
function get_servable_type( string $declared ): ?string {
	$type = strtolower( trim( (string) strtok( $declared, ';' ) ) );

	return in_array( $type, ALLOWED_TYPES, true ) ? $type : null;
}

/**
 * Fetch the icon over HTTP.
 *
 * Asks for PNG explicitly. Image services commonly content-negotiate on Accept and will
 * hand back WebP to anything that offers it, which is not what a client asking for a .png
 * expects — and some icon fetchers discard it.
 *
 * @param string $url Site Icon URL.
 * @return array{body: string, type: string}|null Null when the request failed.
 */
function request_icon( string $url ): ?array {
	// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- vip_safe_wp_remote_get() is not available outside VIP, and this plugin is distributed standalone. The result is cached and failure falls back to a redirect.
	$response = wp_remote_get(
		$url,
		[
			'timeout'             => 3,
			// One byte over the cap, not the cap itself. This truncates the body rather
			// than erroring, so a response stopped exactly at the cap would be
			// indistinguishable from one that fits; the extra byte is what makes an
			// oversized body recognisable as oversized below.
			'limit_response_size' => MAX_ICON_BYTES + 1,
			'headers'             => [ 'Accept' => 'image/png,image/*;q=0.8' ],
		]
	);

	if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
		return null;
	}

	$body = wp_remote_retrieve_body( $response );

	if ( $body === '' || strlen( $body ) > MAX_ICON_BYTES ) {
		return null;
	}

	$header = wp_remote_retrieve_header( $response, 'content-type' );
	$type   = is_string( $header ) ? get_servable_type( $header ) : null;

	if ( $type === null ) {
		return null;
	}

	return [
		'body' => $body,
		'type' => $type,
	];
}
