<?php
/**
 * The nginx configuration needed where the web server answers root paths itself.
 *
 * Generated only, for a human or a deploy to place: nginx has no per-directory
 * configuration a plugin could write, so Site Health prints this and stops there.
 *
 * nginx is the only server this generates for. Apache needs nothing — core's own .htaccess
 * block already sends non-existent paths to index.php, which is exactly what this asks
 * nginx to do.
 *
 * @package SiteIconFallback
 */

declare( strict_types=1 );

namespace SiteIconFallback\Server_Config;

defined( 'ABSPATH' ) || exit;

/**
 * Whether the site is served by nginx.
 *
 * Core sets $is_nginx from a substring match on $_SERVER['SERVER_SOFTWARE']
 * (wp-includes/vars.php), with no fallback. That is reliable when nginx talks to PHP-FPM
 * directly, and wrong in two directions otherwise: nginx proxying to Apache reports Apache,
 * and any context without SERVER_SOFTWARE reports nothing at all. Activation depends on
 * this answer, so the gate around it is filterable — see Lifecycle\nginx_is_required().
 *
 * @return bool
 */
function is_nginx(): bool {
	global $is_nginx;

	return (bool) $is_nginx;
}

/**
 * The path WordPress is installed under, always with both slashes.
 *
 * The request handler answers paths relative to the home URL, so rules written against the
 * domain root would never match on a subdirectory install.
 *
 * @return string A path such as '/' or '/blog/'.
 */
function get_home_root(): string {
	$home_root = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );

	return $home_root === '' ? '/' : trailingslashit( $home_root );
}

/**
 * nginx configuration routing root icon paths to WordPress.
 *
 * Read from the bundled nginx.conf.example rather than duplicated here, so that what
 * Site Health tells you to paste and what bin/install-nginx-config.sh writes cannot
 * drift apart.
 *
 * @return string
 */
function get_nginx_snippet(): string {
	// phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a static file bundled with this plugin, not remote or user-supplied data.
	$snippet = file_get_contents( dirname( __DIR__ ) . '/nginx.conf.example' );

	return is_string( $snippet ) ? apply_home_root( trim( $snippet ) ) : '';
}

/**
 * Rebase a snippet written for a root install onto this install's own path.
 *
 * The bundled file is written for the common case, so that it stays valid nginx and can be
 * pasted or installed as it is. A subdirectory install needs both halves moved: the
 * location patterns, which otherwise match paths this WordPress does not own, and the
 * try_files fallback, which otherwise points at whatever sits at the domain root instead of
 * at this install's index.php.
 *
 * @param string $snippet Config written against '/'.
 * @return string Config rooted at this install's home path.
 */
function apply_home_root( string $snippet ): string {
	$home_root = get_home_root();

	if ( $home_root === '/' ) {
		return $snippet;
	}

	return str_replace(
		[ '^/', ' /index.php' ],
		[ '^' . $home_root, ' ' . $home_root . 'index.php' ],
		$snippet
	);
}
