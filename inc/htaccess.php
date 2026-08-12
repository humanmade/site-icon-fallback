<?php
/**
 * Writing and removing this plugin's block in a shared .htaccess.
 *
 * @package SiteIconFallback
 */

declare( strict_types=1 );

namespace SiteIconFallback\Htaccess;

use SiteIconFallback\Server_Config;

defined( 'ABSPATH' ) || exit;

/** Marker used to fence this plugin's rules inside a shared .htaccess. */
const MARKER = 'Site Icon Fallback';

/**
 * Write this plugin's rules into .htaccess, between its own markers.
 *
 * Most Apache installs already route unknown paths to index.php through core's own
 * .htaccess block, in which case this changes nothing. It exists for hosts whose rewrite
 * block has been replaced or narrowed. Only ever touches the block it owns; core's block
 * is left alone.
 *
 * @return bool True when the rules were written.
 */
function maybe_write(): bool {
	$htaccess = get_writable_path();

	if ( $htaccess === '' ) {
		return false;
	}

	require_once ABSPATH . 'wp-admin/includes/misc.php';

	if ( ! got_mod_rewrite() ) {
		return false;
	}

	return insert_with_markers( $htaccess, MARKER, Server_Config\get_apache_rules() );
}

/**
 * Remove this plugin's rules from .htaccess.
 *
 * Not insert_with_markers() with an empty insertion — that still writes both markers and
 * core's boilerplate comment, leaving an empty fenced block behind rather than removing
 * it. The markers have to come out with the rules.
 *
 * @return bool True when a block was removed.
 */
function maybe_remove(): bool {
	$htaccess = get_writable_path();

	if ( $htaccess === '' ) {
		return false;
	}

	return remove_marker_block( $htaccess, MARKER );
}

/**
 * Path to a .htaccess this install may modify.
 *
 * @return string Empty string when there is nothing writable to work with.
 */
function get_writable_path(): string {
	if ( Server_Config\is_nginx() ) {
		return '';
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';

	$htaccess = get_home_path() . '.htaccess';

	// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_is_writable, WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Guarding a deliberate, marker-fenced write on self-hosted installs.
	if ( file_exists( $htaccess ) && ! is_writable( $htaccess ) ) {
		return '';
	}

	return $htaccess;
}

/**
 * Strip a marker-fenced block, and its markers, from a file.
 *
 * Matches with str_contains rather than equality, the same way core locates its own
 * markers, so an indented marker line is still recognised.
 *
 * @param string $filename Absolute path.
 * @param string $marker   Marker name, without the BEGIN/END prefix.
 * @return bool True when a block was found and removed.
 */
function remove_marker_block( string $filename, string $marker ): bool {
	// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_is_writable, WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Deactivation cleanup of a file this plugin wrote.
	if ( ! file_exists( $filename ) || ! is_writable( $filename ) ) {
		return false;
	}

	// phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown, WordPress.WP.AlternativeFunctions.file_system_operations_file -- Local file this plugin wrote during activation.
	$lines = file( $filename, FILE_IGNORE_NEW_LINES );

	if ( ! is_array( $lines ) ) {
		return false;
	}

	$kept   = [];
	$inside = false;
	$found  = false;

	foreach ( $lines as $line ) {
		if ( ! $inside && str_contains( $line, "# BEGIN {$marker}" ) ) {
			$inside = true;
			$found  = true;
			continue;
		}

		if ( $inside ) {
			$inside = ! str_contains( $line, "# END {$marker}" );
			continue;
		}

		$kept[] = $line;
	}

	if ( ! $found ) {
		return false;
	}

	// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Removing rules from the .htaccess this plugin wrote on activation; the path is core's own get_home_path().
	return file_put_contents( $filename, implode( "\n", $kept ) . "\n", LOCK_EX ) !== false;
}
