<?php
/**
 * Removes what this plugin stored, when it is deleted.
 *
 * @package SiteIconFallback
 */

declare( strict_types=1 );

namespace SiteIconFallback\Uninstall;

use SiteIconFallback\Icon_Fetch;
use SiteIconFallback\Site_Health;

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// WordPress loads this file on its own, without the plugin, so the transient names have to
// come from somewhere. Requiring the modules that own them keeps this file from carrying a
// second copy of every string. Neither registers a hook when merely loaded.
require_once __DIR__ . '/inc/icon-fetch.php';
require_once __DIR__ . '/inc/site-health.php';

// Everything this plugin stores is a transient. It writes no options at all: it reads the
// Site Icon core already owns, and it registers no activation or deactivation hook.
delete_transient( Site_Health\REACHABILITY_TRANSIENT );

// The cached icon bytes are keyed by a hash of the icon URL, so they cannot be named — only
// matched. A no-op under an external object cache, where transients never reach the options
// table and expire in the cache instead.
global $wpdb;

// Escaped whole, not just the plugin's own prefix: an underscore is a single-character
// wildcard in LIKE, and `_transient_` is mostly underscores.
$bytes         = $wpdb->esc_like( '_transient_' . Icon_Fetch\BYTES_TRANSIENT_PREFIX ) . '%';
$bytes_timeout = $wpdb->esc_like( '_transient_timeout_' . Icon_Fetch\BYTES_TRANSIENT_PREFIX ) . '%';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Deleting rows that cannot be addressed by name; there is no API for a transient prefix, and caching a one-shot uninstall query would be meaningless.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$bytes,
		$bytes_timeout
	)
);

// Deliberately only this site's transients: what the other sites on a network hold expires
// within a day by itself, and core's daily delete_expired_transients() reclaims it.
// See CLAUDE.md: "Uninstall cleans this site, not the network."
