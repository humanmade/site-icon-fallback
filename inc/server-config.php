<?php
/**
 * The nginx configuration needed where the web server answers root paths itself.
 *
 * Generated only, never written: nginx has no per-directory config a plugin could write.
 * Apache needs nothing — core's .htaccess already sends non-existent paths to index.php.
 *
 * @package SiteIconFallback
 */

declare( strict_types=1 );

namespace SiteIconFallback\Server_Config;

defined( 'ABSPATH' ) || exit;

/**
 * Whether the site is served by nginx.
 *
 * Core derives $is_nginx from SERVER_SOFTWARE, which is wrong where nginx proxies to
 * Apache. Activation depends on this, so the gate around it is filterable — see
 * Lifecycle\nginx_is_required().
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
 * Distinguishes "not nginx" from "nothing answered", which core collapses into the same
 * $is_nginx === false. WP-CLI is the case that matters: it never sets SERVER_SOFTWARE.
 * See CLAUDE.md: "'Not nginx' and 'nothing answered' are different."
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
 * Read from the bundled nginx.conf.example rather than duplicated here, so Site Health and
 * bin/install-nginx-config.sh cannot drift apart.
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
 * A subdirectory install needs both halves moved: the location patterns, and the try_files
 * fallback, which otherwise points at whatever sits at the domain root.
 * See CLAUDE.md: "Server config is rooted at home_url(), not at /."
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
