<?php
/**
 * Runs uninstall.php against stubs and reports what it tried to delete.
 *
 * A subprocess rather than part of tests/test-routing.php, because the file's first act is
 * to exit when WP_UNINSTALL_PLUGIN is absent — which would take the test runner with it.
 * Called with no argument, this harness leaves the constant undefined and so exercises that
 * guard; called with 'run', it defines it and records the deletions.
 */

declare( strict_types=1 );

define( 'ABSPATH', '/tmp/' );
define( 'KB_IN_BYTES', 1024 );

if ( ( $argv[1] ?? '' ) === 'run' ) {
	define( 'WP_UNINSTALL_PLUGIN', 'site-icon-fallback/plugin.php' );
}

$GLOBALS['__deleted'] = [
	'site_options' => [],
	'transients'   => [],
	'queries'      => [],
];

function delete_site_option( $key ) {
	$GLOBALS['__deleted']['site_options'][] = $key;

	return true;
}

function delete_transient( $key ) {
	$GLOBALS['__deleted']['transients'][] = $key;

	return true;
}

class Stub_WPDB {
	public $options = 'wp_options';

	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	public function prepare( $query, ...$args ) {
		foreach ( $args as $arg ) {
			$query = preg_replace( '/%s/', "'" . $arg . "'", $query, 1 );
		}

		return $query;
	}

	public function query( $query ) {
		$GLOBALS['__deleted']['queries'][] = $query;

		return 1;
	}
}

$GLOBALS['wpdb'] = new Stub_WPDB();

require __DIR__ . '/../uninstall.php';

echo json_encode( $GLOBALS['__deleted'] );
