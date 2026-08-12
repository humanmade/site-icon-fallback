<?php
/**
 * Admin notices.
 *
 * @package SiteIconFallback
 */

declare( strict_types=1 );

namespace SiteIconFallback\Admin_Notices;

defined( 'ABSPATH' ) || exit;

/**
 * Warn when there is no Site Icon for the plugin to serve.
 *
 * Not dismissible, and deliberately so: without a Site Icon every root icon request
 * returns a 404, which means the plugin is doing nothing at all. The notice disappears
 * the moment an icon is set, so it cannot outstay the problem it describes.
 *
 * Site Icons are per-site on multisite, so this hangs off admin_notices rather than
 * network_admin_notices — the network admin has no Site Icon of its own to set.
 *
 * @return void
 */
function render_missing_icon_notice(): void {
	if ( ! current_user_can( 'manage_options' ) || has_site_icon() ) {
		return;
	}

	$message = sprintf(
		/* translators: %s: URL of the Settings > General screen. */
		__(
			'<strong>Site Icon Fallback</strong> has nothing to serve. No Site Icon is set, so requests for <code>/apple-touch-icon.png</code> and <code>/favicon.ico</code> return a 404. <a href="%s">Set a Site Icon</a> to fix this.',
			'site-icon-fallback'
		),
		esc_url( admin_url( 'options-general.php' ) )
	);

	printf(
		'<div class="notice notice-warning"><p>%s</p></div>',
		wp_kses( $message, get_notice_allowed_html() )
	);
}

/**
 * Tags permitted inside a notice.
 *
 * @return array<string, array<string, bool>> Allowed HTML for wp_kses().
 */
function get_notice_allowed_html(): array {
	return [
		'strong' => [],
		'code'   => [],
		'a'      => [ 'href' => true ],
	];
}
