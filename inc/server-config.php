<?php
/**
 * The rules this site's web server needs to route root icon paths to WordPress.
 *
 * Generates them only. Writing them into a file is inc/htaccess.php's job, and on nginx it
 * is a human's — Site Health prints what get_server_snippet() returns.
 *
 * @package SiteIconFallback
 */

declare( strict_types=1 );

namespace SiteIconFallback\Server_Config;

defined( 'ABSPATH' ) || exit;

/**
 * The configuration snippet appropriate to this server.
 *
 * @return string
 */
function get_server_snippet(): string {
	return is_nginx() ? get_nginx_snippet() : implode( "\n", get_apache_rules() );
}

/**
 * Whether the site is served by nginx.
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
 * Both server configs need it, and they must agree: the request handler answers paths
 * relative to the home URL, so rules written against the domain root would never match on
 * a subdirectory install.
 *
 * @return string A path such as '/' or '/blog/'.
 */
function get_home_root(): string {
	$home_root = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );

	return $home_root === '' ? '/' : trailingslashit( $home_root );
}

/**
 * Apache rewrite rules routing root icon paths to WordPress.
 *
 * Each rewrite is guarded by a file-exists condition, matching the `try_files $uri` in the
 * nginx snippet: a real favicon.ico sitting at the web root must keep being served. Core's
 * own block carries the same guard, so for an existing file core's rule declines and
 * control reaches ours — which without this would rewrite it to index.php anyway and
 * silently stop serving a hand-placed icon on activation. A RewriteCond applies only to
 * the rule immediately following it, hence one per rule rather than one for the block.
 *
 * @return string[] One rule per line.
 */
function get_apache_rules(): array {
	$home_root = get_home_root();

	return [
		'<IfModule mod_rewrite.c>',
		'RewriteEngine On',
		'RewriteBase ' . $home_root,
		'RewriteCond %{REQUEST_FILENAME} !-f',
		'RewriteRule ^apple-touch-icon(-precomposed)?(-[0-9]+x[0-9]+)?\.png$ index.php [L]',
		'RewriteCond %{REQUEST_FILENAME} !-f',
		'RewriteRule ^favicon\.(ico|png)$ index.php [L]',
		'</IfModule>',
	];
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
