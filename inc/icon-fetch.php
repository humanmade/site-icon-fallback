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
 * A closed list keeps out an image CDN's HTML error page and an SVG Site Icon a browser
 * would run script from. Anything else falls through to a redirect.
 * See CLAUDE.md: "Content types are allow-listed too."
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
 * Leading bytes that identify an image format, for responses that declare no type.
 *
 * Every value here is already in ALLOWED_TYPES, so recognising bytes can name a type but
 * never admit a new one.
 */
const TYPE_SIGNATURES = [
	"\x89PNG\r\n\x1a\n" => 'image/png',
	"\xFF\xD8\xFF"      => 'image/jpeg',
	'GIF87a'            => 'image/gif',
	'GIF89a'            => 'image/gif',
	"\x00\x00\x01\x00"  => 'image/x-icon',
	'BM'                => 'image/bmp',
];

/**
 * The icon at a URL, as bytes plus content type.
 *
 * Reads from disk when the URL maps into the uploads directory, and falls back to HTTP
 * when it does not — the case wherever a CDN or image service rewrites the URL.
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

	// A remembered failure. Retrying every request turns a slow icon host into a blocking
	// three-second wait per probe, on the one code path built for bot traffic.
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

	// Checked before the read, so an oversized original is never pulled into memory. A Site
	// Icon set with `wp option update site_icon <id>` generates no derivatives, so the URL
	// resolves to the full-size upload.
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
 * Compares the media type alone, case-insensitively: `IMAGE/PNG; charset=binary` is the
 * same claim as `image/png`.
 *
 * @param string $declared Content-Type header value, or a type from wp_check_filetype().
 * @return string|null Null when the type is not in ALLOWED_TYPES.
 */
function get_servable_type( string $declared ): ?string {
	$type = strtolower( trim( (string) strtok( $declared, ';' ) ) );

	return in_array( $type, ALLOWED_TYPES, true ) ? $type : null;
}

/**
 * The content type a body's own leading bytes identify it as.
 *
 * Consulted only when a response declares no type at all — some image services return the
 * bytes with no Content-Type header. See CLAUDE.md: "A response that declares no type is
 * sniffed, not refused."
 *
 * @param string $body Response body.
 * @return string|null Null when the bytes match no format we will serve.
 */
function sniff_type( string $body ): ?string {
	foreach ( TYPE_SIGNATURES as $signature => $type ) {
		if ( str_starts_with( $body, (string) $signature ) ) {
			return $type;
		}
	}

	// Both are container formats naming their contents in a later field, so a signature
	// compared at byte zero cannot tell a WebP from a WAV, or an AVIF from any other MP4.
	if ( str_starts_with( $body, 'RIFF' ) && substr( $body, 8, 4 ) === 'WEBP' ) {
		return 'image/webp';
	}

	if ( substr( $body, 4, 4 ) === 'ftyp' && in_array( substr( $body, 8, 4 ), [ 'avif', 'avis' ], true ) ) {
		return 'image/avif';
	}

	return null;
}

/**
 * Fetch the icon over HTTP.
 *
 * Asks for PNG explicitly: image services content-negotiate on Accept and will return
 * WebP under a .png URL to anything that offers it.
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
			// One byte over the cap: this truncates rather than errors, so a body stopped
			// exactly at the cap would look like one that fits.
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

	$header   = wp_remote_retrieve_header( $response, 'content-type' );
	$declared = is_string( $header ) ? trim( $header ) : '';

	// A declared type is the answer, right or wrong: sniffing is the fallback for silence,
	// not a second opinion on something the origin already told us.
	$type = $declared !== '' ? get_servable_type( $declared ) : sniff_type( $body );

	if ( $type === null ) {
		return null;
	}

	return [
		'body' => $body,
		'type' => $type,
	];
}
