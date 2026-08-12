<?php
/**
 * Declares the sized apple-touch-icon tags in the page head.
 *
 * @package SiteIconFallback
 */

declare( strict_types=1 );

namespace SiteIconFallback\Meta_Tags;

use SiteIconFallback;

defined( 'ABSPATH' ) || exit;

/**
 * Sizes considered for the page head.
 *
 * Every current iOS device maps to one of these. The legacy sizes in SUPPORTED_SIZES are
 * still answered at the root but not declared here: they exist for pre-iOS 7 hardware,
 * and a link tag costs bytes in the head of every page whereas a root path costs nothing
 * until something asks for it.
 *
 * These are candidates rather than guarantees. Any that resolve to the same image are
 * collapsed into one tag — see get_declarable_icons().
 */
const DECLARED_SIZES = [ 120, 152, 167, 180 ];

/**
 * Replace core's single bare apple-touch-icon tag with a sized set.
 *
 * Core emits one <link rel="apple-touch-icon"> carrying no sizes attribute, so a client
 * after a specific size has no exact match to choose and downscales the 180 instead.
 * Declaring each size gives it one. This runs on any host with no server configuration,
 * which is the half of the problem that does not depend on requests reaching PHP.
 *
 * @param string[] $meta_tags Tags core is about to output.
 * @return string[] Filtered tags.
 */
function filter_meta_tags( array $meta_tags ): array {
	/**
	 * Filters the apple-touch-icon sizes declared in the page head.
	 *
	 * @param int[] $sizes Square sizes in pixels.
	 */
	$sizes = (array) apply_filters( 'site_icon_fallback_declared_sizes', DECLARED_SIZES );

	$meta_tags = array_values(
		array_filter( $meta_tags, __NAMESPACE__ . '\\is_not_touch_icon_tag' )
	);

	foreach ( get_declarable_icons( $sizes ) as $size => $url ) {
		$meta_tags[] = sprintf(
			'<link rel="apple-touch-icon" sizes="%1$dx%1$d" href="%2$s" />',
			$size,
			esc_url( $url )
		);
	}

	return $meta_tags;
}

/**
 * The sizes worth declaring, mapped to their icon URL.
 *
 * WordPress only generates four Site Icon derivatives — 270, 192, 180 and 32 — and
 * resolves any other request to the smallest generated size at least as large, while
 * reporting back the dimensions that were asked for. Ask it for 120, 152, 167 and 180 on
 * an install with no image service and all four hand back the same 180x180 file, which
 * would put four tags in the head claiming four sizes for one image.
 *
 * Grouping by URL avoids that without needing to know whether an image service is in
 * play: where each size yields a distinct derivative they are all declared, and where
 * they collapse onto one file it is declared once. Sizes are walked in ascending order so
 * the size kept for each image is the largest that resolved to it, which is the closest
 * any of them get to the real dimensions — and never larger, since a resolved derivative
 * is always at least the size requested.
 *
 * @param array<int, int|string> $sizes Requested square sizes in pixels.
 * @return array<int, string> Size in pixels mapped to icon URL.
 */
function get_declarable_icons( array $sizes ): array {
	$sizes = array_filter(
		array_map( 'intval', $sizes ),
		static function ( int $size ): bool {
			return $size > 0;
		}
	);

	sort( $sizes );

	$largest_for_url = [];

	foreach ( $sizes as $size ) {
		$url = SiteIconFallback\get_icon_url( $size );

		if ( $url !== '' ) {
			$largest_for_url[ $url ] = $size;
		}
	}

	return array_flip( $largest_for_url );
}

/**
 * Whether a meta tag is something other than an apple-touch-icon link.
 *
 * Used to strip core's undifferentiated tag before the sized set is appended, so the two
 * do not both appear.
 *
 * @param mixed $tag A single entry from the site_icon_meta_tags array.
 * @return bool True to keep the tag.
 */
function is_not_touch_icon_tag( $tag ): bool {
	return ! is_string( $tag ) || ! str_contains( $tag, 'rel="apple-touch-icon"' );
}
