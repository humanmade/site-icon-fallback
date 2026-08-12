<?php
/**
 * Emitting the Site Icon's bytes as the response.
 *
 * Where the bytes come from is inc/icon-fetch.php's job. Everything here ends in exit.
 *
 * @package SiteIconFallback
 */

declare( strict_types=1 );

namespace SiteIconFallback\Icon_Stream;

use SiteIconFallback;

defined( 'ABSPATH' ) || exit;

/**
 * Emit the icon and stop.
 *
 * @param array{body: string, type: string} $icon Icon bytes and content type.
 * @return void
 */
function send_icon_bytes( array $icon ): void {
	$etag = '"' . md5( $icon['body'] ) . '"';

	header( SiteIconFallback\MARKER_HEADER . ': stream' );
	header( 'Content-Type: ' . $icon['type'] );

	// The type is already allow-listed, so this only closes the gap between what we claim
	// and what a browser decides the bytes are.
	header( 'X-Content-Type-Options: nosniff' );
	header( 'Cache-Control: public, max-age=' . SiteIconFallback\get_content_max_age() );
	header( 'ETag: ' . $etag );

	if ( get_if_none_match() === $etag ) {
		status_header( 304 );
		exit;
	}

	header( 'Content-Length: ' . strlen( $icon['body'] ) );

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary image data; escaping would corrupt it.
	echo $icon['body'];

	exit;
}

/**
 * The request's If-None-Match header, if any.
 *
 * @return string Empty string when absent.
 */
function get_if_none_match(): string {
	if ( empty( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) {
		return '';
	}

	return trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) );
}
