<?php
/**
 * Runs the WP-CLI commands against a fake WP_CLI and reports what they did.
 *
 * A subprocess, because the WP_CLI constant and class have to exist before inc/cli.php is
 * loaded — and tests/test-routing.php asserts the opposite, that everything degrades when
 * they are absent. Both halves of that guard need testing, so they need separate processes.
 *
 * This also pins the name resolution. inc/cli.php sits in SiteIconFallback\CLI and reaches
 * WP-CLI through `use WP_CLI;`, which makes WP_CLI\Utils\format_items() resolve to the
 * global \WP_CLI\Utils\format_items(). Get that wrong and it silently becomes
 * SiteIconFallback\CLI\WP_CLI\Utils\format_items(), which does not exist.
 *
 * Braced namespaces throughout: declaring \WP_CLI\Utils in the same file as the global-scope
 * stubs is only legal that way.
 */

declare( strict_types=1 );

namespace {
	define( 'ABSPATH', '/tmp/' );
	define( 'KB_IN_BYTES', 1024 );
	define( 'DAY_IN_SECONDS', 86400 );
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'WP_CLI', true );

	$GLOBALS['__recorded'] = [
		'commands' => [],
		'lines'    => [],
		'warnings' => [],
		'halt'     => null,
		'rows'     => [],
		'format'   => '',
	];

	class WP_CLI {

		public static function add_command( $name, $callable, $args = [] ) {
			$GLOBALS['__recorded']['commands'][ $name ] = [
				'callable'  => is_string( $callable ) ? $callable : 'closure',
				'shortdesc' => $args['shortdesc'] ?? '',
			];
		}

		public static function line( $message = '', $newline = true ) {
			$GLOBALS['__recorded']['lines'][] = $message;
		}

		public static function warning( $message ) {
			$GLOBALS['__recorded']['warnings'][] = $message;
		}

		public static function halt( $code ) {
			$GLOBALS['__recorded']['halt'] = $code;
		}
	}

	// The site under test: an icon on a CDN, nothing reporting itself as a server, and a
	// loopback that answers.
	$GLOBALS['__site_icon']  = 'https://cdn.example.com/icon.png';
	$GLOBALS['__home_url']   = 'https://example.com/';
	$GLOBALS['__transients'] = [];
	$GLOBALS['__reachable']  = true;
	$GLOBALS['is_nginx']     = false;

	function get_site_icon_url( $size = 512 ) {
		return $GLOBALS['__site_icon'] === '' ? '' : $GLOBALS['__site_icon'] . '?size=' . $size;
	}

	function home_url( $path = '' ) { return rtrim( $GLOBALS['__home_url'], '/' ) . $path; }
	function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
	function trailingslashit( $s ) { return rtrim( (string) $s, '/\\' ) . '/'; }
	function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
	function wp_unslash( $s ) { return is_string( $s ) ? stripslashes( $s ) : $s; }
	function __( $s, $d = '' ) { return $s; }
	function esc_html__( $s, $d = '' ) { return $s; }
	function esc_html( $s ) { return $s; }
	function esc_url( $u ) { return $u; }
	function admin_url( $p = '' ) { return 'https://example.com/wp-admin/' . $p; }
	function apply_filters( $tag, $value, ...$a ) { return $value; }
	function get_transient( $k ) { return $GLOBALS['__transients'][ $k ] ?? false; }
	function set_transient( $k, $v, $t ) { $GLOBALS['__transients'][ $k ] = $v; return true; }
	function delete_transient( $k ) { unset( $GLOBALS['__transients'][ $k ] ); return true; }
	function is_wp_error( $r ) { return false; }
	function wp_remote_get( $url, $args = [] ) { return []; }
	function wp_remote_retrieve_header( $r, $h ) { return $GLOBALS['__reachable'] ? 'stream' : ''; }
	function add_filter( ...$a ) {}
	function add_action( ...$a ) {}
	function register_activation_hook( ...$a ) {}
	function wp_kses( $s, $allowed ) { return $s; }
	function wp_die( ...$a ) { throw new \Exception( 'wp_die' ); }
}

namespace WP_CLI\Utils {
	function format_items( $format, $items, $fields ) {
		$GLOBALS['__recorded']['format'] = $format;
		$GLOBALS['__recorded']['rows']   = $items;
	}
}

namespace {
	$base = dirname( __DIR__ ) . '/inc';
	require_once $base . '/namespace.php';
	require_once $base . '/root-handler.php';
	require_once $base . '/icon-fetch.php';
	require_once $base . '/icon-stream.php';
	require_once $base . '/server-config.php';
	require_once $base . '/cli.php';
	require_once $base . '/lifecycle.php';
	require_once $base . '/site-health.php';

	$mode = $argv[1] ?? 'status';

	\SiteIconFallback\CLI\register_commands();

	if ( $mode === 'status' ) {
		\SiteIconFallback\CLI\status_command( [], [ 'format' => 'json' ] );
	}

	if ( $mode === 'status-strict' ) {
		// No Site Icon and no loopback: two failures, which --strict turns into an exit code.
		$GLOBALS['__site_icon'] = '';
		$GLOBALS['__reachable'] = false;
		\SiteIconFallback\CLI\status_command( [], [ 'strict' => true, 'fresh' => true ] );
	}

	if ( $mode === 'nginx-config' ) {
		// Called the way WP-CLI calls it — with both argument arrays, which this command
		// declares no parameters for. PHP ignores the surplus for user-defined functions.
		\SiteIconFallback\CLI\nginx_config_command( [], [] );
	}

	if ( $mode === 'activation' ) {
		// No SERVER_SOFTWARE, exactly as WP-CLI leaves it.
		unset( $_SERVER['SERVER_SOFTWARE'] );
		\SiteIconFallback\Lifecycle\on_activation();
	}

	echo json_encode( $GLOBALS['__recorded'] );
}
