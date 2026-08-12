<?php
/**
 * Site Icon Fallback
 *
 * @package   SiteIconFallback
 * @author    Stuart Shields <stuart@humanmade.com>
 * @license   GPL-2.0-or-later
 *
 * @wordpress-plugin
 *
 * Plugin Name:       Site Icon Fallback
 * Description:       Answers the root icon paths that Safari, Applebot and link unfurlers probe for, using the Site Icon you already set in Settings &rarr; General. Also declares the full set of sized apple-touch-icon tags so fewer clients need to probe at all.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Stuart Shields
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

const VERSION = '1.0.0';

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
require_once __DIR__ . '/inc/htaccess.php';
require_once __DIR__ . '/inc/lifecycle.php';
require_once __DIR__ . '/inc/admin-notices.php';

bootstrap();
