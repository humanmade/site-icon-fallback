<?php
/**
 * WP-CLI commands.
 *
 * @package SiteIconFallback
 */

declare( strict_types=1 );

namespace SiteIconFallback\CLI;

use SiteIconFallback;
use SiteIconFallback\Root_Handler;
use SiteIconFallback\Server_Config;
use SiteIconFallback\Site_Health;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Whether this request is a WP-CLI invocation.
 *
 * @return bool
 */
function is_running(): bool {
	return defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' );
}

/**
 * Warn the operator, when there is one listening.
 *
 * A no-op in a web request, where activation has no channel for a warning. Exists for the
 * path where the plugin activates without having confirmed the server.
 *
 * @param string $message Warning text.
 * @return void
 */
function warn( string $message ): void {
	if ( ! is_running() ) {
		return;
	}

	WP_CLI::warning( $message );
}

/**
 * Register the commands.
 *
 * @return void
 */
function register_commands(): void {
	if ( ! is_running() ) {
		return;
	}

	WP_CLI::add_command(
		'site-icon-fallback status',
		__NAMESPACE__ . '\\status_command',
		[
			'shortdesc' => 'Reports whether the plugin can actually serve root icon requests.',
			'synopsis'  => [
				[
					'type'        => 'flag',
					'name'        => 'fresh',
					'optional'    => true,
					'description' => 'Re-run the loopback request instead of reading the cached result.',
				],
				[
					'type'        => 'flag',
					'name'        => 'strict',
					'optional'    => true,
					'description' => 'Exit with a failure code when any check fails.',
				],
				[
					'type'        => 'assoc',
					'name'        => 'format',
					'optional'    => true,
					'default'     => 'table',
					'options'     => [ 'table', 'json', 'csv', 'yaml' ],
					'description' => 'Render output in a particular format.',
				],
			],
		]
	);

	WP_CLI::add_command(
		'site-icon-fallback nginx-config',
		__NAMESPACE__ . '\\nginx_config_command',
		[
			'shortdesc' => 'Prints the nginx rules for this install, rooted at its home path.',
		]
	);
}

/**
 * Report what the plugin can and cannot do on this install.
 *
 * Answers the two questions a deploy needs: is there an icon to serve, and does anything
 * reach PHP to serve it. Site Health answers the same two, but only in a browser.
 *
 * @param array<int, string>    $args       Positional arguments.
 * @param array<string, string> $assoc_args Flags and options.
 * @return void
 */
function status_command( array $args, array $assoc_args = [] ): void {
	if ( ! empty( $assoc_args['fresh'] ) ) {
		delete_transient( Site_Health\REACHABILITY_TRANSIENT );
	}

	$icon_url  = SiteIconFallback\get_icon_url( SiteIconFallback\DEFAULT_TOUCH_ICON_SIZE );
	$software  = Server_Config\get_server_software();
	$reachable = Site_Health\is_root_handler_reachable();

	$checks = [
		[
			'check'  => 'Site Icon',
			'status' => $icon_url === '' ? 'fail' : 'ok',
			'detail' => $icon_url === '' ? 'not set in Settings → General' : $icon_url,
		],
		[
			'check'  => 'Server',
			'status' => Server_Config\is_nginx() ? 'ok' : 'warn',
			'detail' => $software === '' ? 'not reported (WP-CLI has no web server)' : $software,
		],
		[
			'check'  => 'Root icon requests',
			'status' => $reachable ? 'ok' : 'fail',
			'detail' => $reachable ? 'answered by this plugin' : 'never reach WordPress — add the nginx rules',
		],
		[
			'check'  => 'Serve mode',
			'status' => 'ok',
			'detail' => Root_Handler\get_serve_mode(),
		],
	];

	WP_CLI\Utils\format_items(
		$assoc_args['format'] ?? 'table',
		$checks,
		[ 'check', 'status', 'detail' ]
	);

	$failed = count( array_filter( $checks, fn ( $check ) => $check['status'] === 'fail' ) );

	if ( $failed > 0 && ! empty( $assoc_args['strict'] ) ) {
		WP_CLI::halt( 1 );
	}
}

/**
 * Print the nginx rules for this install.
 *
 * Same snippet Site Health shows, rooted at this install's home path. Piping it into a
 * config file is the remote-host equivalent of bin/install-nginx-config.sh.
 *
 * @return void
 */
function nginx_config_command(): void {
	WP_CLI::line( Server_Config\get_nginx_snippet() );
}
