<?php
/**
 * Plugin Name:       Site Icon Fallback
 * Description:       A lightweight fallback that serves your Site Icon from the site root, reducing 404s.
 * Version:           0.1.1
 * Requires at least: 6.7
 * Requires PHP:      8.2
 * Author:            Human Made
 * Author URI:        https://www.humanmade.com
 * Text Domain:       site-icon-fallback
 * License:           GPL-2.0-or-later
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 */

declare( strict_types=1 );

namespace SiteIconFallback;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION = '0.1.1';

/**
 * Absolute path to this file.
 *
 * Needed by register_activation_hook(), which keys on the main plugin file. It is a
 * no-op when the plugin is loaded from mu-plugins, where activation never fires.
 */
const PLUGIN_FILE = __FILE__;

require_once __DIR__ . '/inc/namespace.php';
require_once __DIR__ . '/inc/meta-tags.php';
require_once __DIR__ . '/inc/root-handler.php';
require_once __DIR__ . '/inc/icon-fetch.php';
require_once __DIR__ . '/inc/icon-stream.php';
require_once __DIR__ . '/inc/site-health.php';
require_once __DIR__ . '/inc/server-config.php';
require_once __DIR__ . '/inc/cli.php';
require_once __DIR__ . '/inc/lifecycle.php';
require_once __DIR__ . '/inc/admin-notices.php';

bootstrap();
