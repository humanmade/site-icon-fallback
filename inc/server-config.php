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
 * (wp-includes/vars.php), which is reliable when nginx talks to PHP-FPM directly and wrong
 * where nginx proxies to Apache — that reports Apache. Activation depends on this answer,
 * so the gate around it is filterable. See Lifecycle\nginx_is_required().
 *
 * @return bool
 */
function is_nginx(): bool {
	global $is_nginx;

	return (bool) $is_nginx;
}

/**
 * What the server called itself, if anything did.
 *
 * There is a difference between a server saying it is not nginx and no server saying
 * anything, and only this tells them apart. Core erases it: wp_fix_server_vars() defaults
 * SERVER_SOFTWARE to '' (wp-includes/load.php) before $is_nginx is derived, so both cases
 * arrive as `false`.
 *
 * WP-CLI is the case that matters. It sets four $_SERVER keys and SERVER_SOFTWARE is not
 * among them, so every scripted `wp plugin activate` looks exactly like a request served by
 * the wrong web server.
 *
 * @return string Empty when nothing identified itself.
 */
function get_server_software(): string {
	if ( empty( $_SERVER['SERVER_SOFTWARE'] ) ) {
		return '';
	}

	return sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) );
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
