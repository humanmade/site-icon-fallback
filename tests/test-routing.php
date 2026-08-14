<?php
/**
 * Exercises the pure routing and streaming logic of site-icon-fallback
 * without a WordPress bootstrap.
 */

declare( strict_types=1 );

define( 'ABSPATH', '/tmp/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'KB_IN_BYTES', 1024 );

$GLOBALS['__home_url']  = 'https://example.com/';
$GLOBALS['__site_icon'] = 'https://cdn.example.com/icon.png';
$GLOBALS['__filters']   = [];
$GLOBALS['__generated'] = null;
$GLOBALS['__uploads']   = [ 'baseurl' => 'https://example.com/wp-content/uploads', 'basedir' => sys_get_temp_dir() . '/sif-uploads' ];

function home_url( $path = '' ) { return rtrim( $GLOBALS['__home_url'], '/' ) . $path; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_unslash( $s ) { return is_string( $s ) ? stripslashes( $s ) : $s; }
function trailingslashit( $s ) { return rtrim( (string) $s, '/\\' ) . '/'; }
function esc_url( $u ) { return $u; }
function add_filter( ...$a ) {}
function add_action( ...$a ) {}
function wp_upload_dir() {
	return $GLOBALS['__uploads'];
}

function is_wp_error( $t ) {
	return false;
}

function __( $s, $d = '' ) { return $s; }
function esc_html__( $s, $d = '' ) { return $s; }
function esc_html( $s ) { return $s; }
function admin_url( $p = '' ) { return 'https://example.com/wp-admin/' . $p; }

// Transients are stored for real, with their lifetime, so caching behaviour is observable.
$GLOBALS['__transients'] = [];

function get_transient( $k ) {
	if ( ! array_key_exists( $k, $GLOBALS['__transients'] ) ) {
		return false;
	}

	return $GLOBALS['__transients'][ $k ]['value'];
}

function set_transient( $k, $v, $t ) {
	$GLOBALS['__transients'][ $k ] = [ 'value' => $v, 'ttl' => $t ];

	return true;
}

// wp_check_filetype() resolves by extension against the allowed mime types, so what it
// returns for a given upload is site configuration, not a constant.
$GLOBALS['__filetype'] = [ 'type' => 'image/png', 'ext' => 'png' ];

function wp_check_filetype( $f ) {
	return $GLOBALS['__filetype'];
}

// The site_icon attachment. Zero by default, so every test that predates the attachment read
// keeps exercising the path it was written for.
$GLOBALS['__options']    = [ 'site_icon' => 0 ];
$GLOBALS['__attached']   = '';
$GLOBALS['__attachment'] = [];

function get_option( $name, $default_value = false ) {
	return $GLOBALS['__options'][ $name ] ?? $default_value;
}

function get_attached_file( $id ) {
	return $GLOBALS['__attached'];
}

function wp_get_attachment_metadata( $id ) {
	return $GLOBALS['__attachment'];
}

// A scripted HTTP response, plus a record of what was asked for. Call counting is what
// makes "the failure was not retried" testable at all.
$GLOBALS['__http']       = [ 'code' => 0, 'body' => '', 'type' => '' ];
$GLOBALS['__http_calls'] = 0;
$GLOBALS['__http_args']  = [];

function wp_remote_get( $url, $args = [] ) {
	$GLOBALS['__http_calls']++;
	$GLOBALS['__http_args'] = $args;

	return [];
}

function wp_remote_retrieve_response_code( $r ) {
	return $GLOBALS['__http']['code'];
}

function wp_remote_retrieve_body( $r ) {
	return $GLOBALS['__http']['body'];
}

function wp_remote_retrieve_header( $r, $h ) {
	return $GLOBALS['__http']['type'];
}

function apply_filters( $tag, $value, ...$args ) {
	return array_key_exists( $tag, $GLOBALS['__filters'] ) ? $GLOBALS['__filters'][ $tag ] : $value;
}

/**
 * When __generated is null, every size resolves to its own URL — an image service.
 * When it is a list of generated sizes, the smallest one at least as large wins, which is
 * what core's image_get_intermediate_size() does.
 *
 * Setting __site_icon to false models the option pointing at a deleted attachment, where
 * core returns wp_get_attachment_image_url()'s false rather than the string its docblock
 * promises. That is a real return value, not a hypothetical one, and the plugin has to
 * survive it.
 */
function get_site_icon_url( $size = 512, $url = '', $blog_id = 0 ) {
	if ( $GLOBALS['__site_icon'] === false ) {
		return false;
	}

	if ( $GLOBALS['__site_icon'] === '' ) {
		return '';
	}

	if ( $GLOBALS['__generated'] === null ) {
		return $GLOBALS['__site_icon'] . '?size=' . $size;
	}

	foreach ( $GLOBALS['__generated'] as $generated ) {
		if ( $generated >= $size ) {
			return $GLOBALS['__site_icon'] . '-' . $generated . '.png';
		}
	}

	return $GLOBALS['__site_icon'];
}

// What the server reports, which is all core has to go on.
$GLOBALS['is_nginx'] = true;

function register_activation_hook( ...$a ) {}
function wp_kses( $s, $allowed ) { return $s; }

// wp_die() ends the request in WordPress. Throwing models "execution stopped here" without
// stopping the runner, which is the only way to assert that activation was refused.
class Activation_Refused extends Exception {}

function wp_die( $message = '', $title = '', $args = [] ) {
	throw new Activation_Refused( is_string( $message ) ? $message : '' );
}

$base = dirname( __DIR__ ) . '/inc';
require_once $base . '/namespace.php';
require_once $base . '/meta-tags.php';
require_once $base . '/root-handler.php';
require_once $base . '/icon-fetch.php';
require_once $base . '/icon-stream.php';
require_once $base . '/server-config.php';
require_once $base . '/cli.php';
require_once $base . '/lifecycle.php';
require_once $base . '/site-health.php';

use function SiteIconFallback\Root_Handler\get_request_path;
use function SiteIconFallback\Root_Handler\resolve_touch_icon_size;
use function SiteIconFallback\Meta_Tags\filter_meta_tags;
use function SiteIconFallback\Root_Handler\get_serve_mode;
use function SiteIconFallback\Icon_Fetch\read_local_icon;
use function SiteIconFallback\Icon_Stream\get_if_none_match;

$pass = 0;
$fail = 0;

function check( string $label, $actual, $expected ): void {
	global $pass, $fail;
	if ( $actual === $expected ) {
		$pass++;
		printf( "  ok    %s\n", $label );
		return;
	}
	$fail++;
	printf( "  FAIL  %s\n        expected %s, got %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
}

/** Resolve a request URI the way maybe_serve_root_icon() would. */
function route( string $uri ): ?int {
	$_SERVER['REQUEST_URI'] = $uri;
	$path = get_request_path();

	if ( preg_match( SiteIconFallback\Root_Handler\TOUCH_ICON_PATTERN, $path, $m ) === 1 ) {
		return resolve_touch_icon_size( $m );
	}
	if ( preg_match( SiteIconFallback\Root_Handler\FAVICON_PATTERN, $path ) === 1 ) {
		return SiteIconFallback\FAVICON_SIZE;
	}
	return null;
}

echo "Touch icon routing\n";
check( 'bare apple-touch-icon.png -> 180', route( '/apple-touch-icon.png' ), 180 );
check( 'precomposed -> 180', route( '/apple-touch-icon-precomposed.png' ), 180 );
check( 'sized 152 -> 152', route( '/apple-touch-icon-152x152.png' ), 152 );
check( 'precomposed sized 120 -> 120', route( '/apple-touch-icon-precomposed-120x120.png' ), 120 );
check( 'query string ignored', route( '/apple-touch-icon-180x180.png?v=2' ), 180 );

echo "\nRefusals\n";
check( 'out-of-allowlist 9999 refused', route( '/apple-touch-icon-9999x9999.png' ), 0 );
check( 'non-square refused', route( '/apple-touch-icon-100x200.png' ), 0 );
check( 'unsupported size 300 refused', route( '/apple-touch-icon-300x300.png' ), 0 );

echo "\nFavicon routing\n";
check( 'favicon.ico -> 32', route( '/favicon.ico' ), 32 );
check( 'favicon.png -> 32', route( '/favicon.png' ), 32 );

echo "\nNon-matches pass through\n";
check( 'nested path not matched', route( '/wp-content/apple-touch-icon.png' ), null );
check( 'trailing junk not matched', route( '/favicon.icox' ), null );
check( 'homepage not matched', route( '/' ), null );
check( 'jpg not matched', route( '/apple-touch-icon.jpg' ), null );

echo "\nSubdirectory install\n";
$GLOBALS['__home_url'] = 'https://example.com/blog/';
check( 'subdir root icon -> 180', route( '/blog/apple-touch-icon.png' ), 180 );
check( 'subdir favicon -> 32', route( '/blog/favicon.ico' ), 32 );
$GLOBALS['__home_url'] = 'https://example.com/';

echo "\nMeta tags\n";
$core_tags = [
	'<link rel="icon" href="https://cdn.example.com/icon.png?size=32" sizes="32x32" />',
	'<link rel="icon" href="https://cdn.example.com/icon.png?size=192" sizes="192x192" />',
	'<link rel="apple-touch-icon" href="https://cdn.example.com/icon.png?size=180" />',
	'<meta name="msapplication-TileImage" content="https://cdn.example.com/icon.png?size=270" />',
];
$filtered = filter_meta_tags( $core_tags );
$touch    = array_values( array_filter( $filtered, fn( $t ) => str_contains( $t, 'apple-touch-icon' ) ) );

check( 'core bare touch-icon tag removed', (int) ( count( array_filter( $filtered, fn( $t ) => $t === $core_tags[2] ) ) ), 0 );
check( 'four sized touch-icon tags emitted', count( $touch ), 4 );
check( 'non-touch tags preserved', count( $filtered ) - count( $touch ), 3 );
check( 'sizes attribute present', str_contains( $touch[0], 'sizes="120x120"' ), true );

echo "\nNo Site Icon set\n";
$GLOBALS['__site_icon'] = '';
check( 'no touch-icon tags emitted', count( array_filter( filter_meta_tags( $core_tags ), fn( $t ) => str_contains( $t, 'apple-touch-icon' ) ) ), 0 );
$GLOBALS['__site_icon'] = 'https://cdn.example.com/icon.png';

echo "\nSite Icon set to a deleted attachment\n";
// get_site_icon_url() returns false here, not ''. Comparing === '' let that through and
// passed a bool into a string parameter under strict_types, fatalling on /favicon.ico.
$GLOBALS['__site_icon'] = false;
check( 'false normalises to an empty string', SiteIconFallback\get_icon_url( 180 ), '' );
check( 'nothing is declarable', SiteIconFallback\Meta_Tags\get_declarable_icons( [ 120, 180 ] ), [] );
check( 'no touch-icon tags emitted', count( array_filter( filter_meta_tags( $core_tags ), fn( $t ) => str_contains( $t, 'apple-touch-icon' ) ) ), 0 );
$GLOBALS['__site_icon'] = 'https://cdn.example.com/icon.png';
check( 'a real icon still resolves', SiteIconFallback\get_icon_url( 180 ), 'https://cdn.example.com/icon.png?size=180' );

echo "\nSize dedupe against core's four generated derivatives\n";
// What WordPress actually generates: 32, 180, 192, 270. No image service.
$GLOBALS['__generated'] = [ 32, 180, 192, 270 ];

$collapsed = SiteIconFallback\Meta_Tags\get_declarable_icons( [ 120, 152, 167, 180 ] );
check( 'four sizes collapse to one tag', count( $collapsed ), 1 );
check( 'kept size is the largest of the group', array_key_first( $collapsed ), 180 );
check( 'kept URL is the real 180 derivative', reset( $collapsed ), 'https://cdn.example.com/icon.png-180.png' );

$mixed = SiteIconFallback\Meta_Tags\get_declarable_icons( [ 120, 192 ] );
check( 'distinct derivatives stay separate', count( $mixed ), 2 );
check( 'sizes preserved across distinct files', array_keys( $mixed ), [ 120, 192 ] );

$head = filter_meta_tags( $core_tags );
check( 'head carries one touch-icon tag, not four', count( array_filter( $head, fn( $t ) => str_contains( $t, 'apple-touch-icon' ) ) ), 1 );

// Back to an image service, where every size resolves exactly.
$GLOBALS['__generated'] = null;
check( 'image service still declares all four', count( SiteIconFallback\Meta_Tags\get_declarable_icons( [ 120, 152, 167, 180 ] ) ), 4 );
check( 'no Site Icon yields nothing to declare', ( function () { $GLOBALS['__site_icon'] = ''; $r = SiteIconFallback\Meta_Tags\get_declarable_icons( [ 180 ] ); $GLOBALS['__site_icon'] = 'https://cdn.example.com/icon.png'; return $r; } )(), [] );
check( 'zero and negative sizes ignored', SiteIconFallback\Meta_Tags\get_declarable_icons( [ 0, -5 ] ), [] );

echo "\nServe mode\n";
check( 'defaults to stream', get_serve_mode(), 'stream' );
$GLOBALS['__filters']['site_icon_fallback_serve_mode'] = 'redirect';
check( 'filter can select redirect', get_serve_mode(), 'redirect' );
$GLOBALS['__filters']['site_icon_fallback_serve_mode'] = 'nonsense';
check( 'unknown mode falls back to stream', get_serve_mode(), 'stream' );
unset( $GLOBALS['__filters']['site_icon_fallback_serve_mode'] );

echo "\nLocal icon reads\n";
$dir = $GLOBALS['__uploads']['basedir'] . '/2018/12';
@mkdir( $dir, 0777, true );
$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' );
file_put_contents( $dir . '/icon.png', $png );

$local = read_local_icon( 'https://example.com/wp-content/uploads/2018/12/icon.png' );
check( 'reads a file under uploads', is_array( $local ) && $local['body'] === $png, true );
check( 'reports content type', is_array( $local ) ? $local['type'] : null, 'image/png' );

$local_q = read_local_icon( 'https://example.com/wp-content/uploads/2018/12/icon.png?fit=180,180' );
check( 'strips query before resolving path', is_array( $local_q ) && $local_q['body'] === $png, true );

check( 'CDN URL is not read locally', read_local_icon( 'https://cdn.example.com/icon.png' ), null );
check( 'missing file returns null', read_local_icon( 'https://example.com/wp-content/uploads/nope.png' ), null );
check( 'traversal outside uploads rejected', read_local_icon( 'https://evil.test/wp-content/uploads/2018/12/icon.png' ), null );

echo "\nOversized local icons\n";
// The cap is only worth having if it applies to the common path. A Site Icon set with
// `wp option update site_icon <id>` never generates the site_icon-* derivatives, so the
// URL resolves to the full-size original — which for a site icon is at least 512x512.
// Two files rather than one rewritten twice: filesize() reads PHP's stat cache, so the
// second size would not be seen.
file_put_contents( $dir . '/over.png', str_repeat( 'x', SiteIconFallback\Icon_Fetch\MAX_ICON_BYTES + 1 ) );
file_put_contents( $dir . '/at.png', str_repeat( 'x', SiteIconFallback\Icon_Fetch\MAX_ICON_BYTES ) );

check( 'a file over the cap is refused', read_local_icon( 'https://example.com/wp-content/uploads/2018/12/over.png' ) === null, true );
check( 'a file exactly at the cap is still read', is_array( read_local_icon( 'https://example.com/wp-content/uploads/2018/12/at.png' ) ), true );

unlink( $dir . '/over.png' );
unlink( $dir . '/at.png' );

echo "\nContent types\n";
// These bytes are emitted under a .png or .ico URL from the site's own origin and held for
// a day, so what may be labelled here is a closed list.
$icon_url = 'https://example.com/wp-content/uploads/2018/12/icon.png';

$GLOBALS['__filetype'] = [ 'type' => 'image/svg+xml', 'ext' => 'svg' ];
check( 'an SVG Site Icon is not served', read_local_icon( $icon_url ), null );

$GLOBALS['__filetype'] = [ 'type' => false, 'ext' => false ];
check( 'an unrecognised local type is refused', read_local_icon( $icon_url ), null );

$GLOBALS['__filetype'] = [ 'type' => 'image/x-icon', 'ext' => 'ico' ];
$ico = read_local_icon( $icon_url );
check( 'ico is served', is_array( $ico ) ? $ico['type'] : null, 'image/x-icon' );

$GLOBALS['__filetype'] = [ 'type' => 'image/png', 'ext' => 'png' ];

$GLOBALS['__http'] = [ 'code' => 200, 'body' => $png, 'type' => 'text/html; charset=UTF-8' ];
check( "an image CDN's HTML error page is refused", SiteIconFallback\Icon_Fetch\request_icon( 'https://cdn.example.com/icon.png' ), null );

$GLOBALS['__http']['type'] = 'IMAGE/PNG; charset=binary';
$negotiated = SiteIconFallback\Icon_Fetch\request_icon( 'https://cdn.example.com/icon.png' );
check( 'parameters are stripped and the type lowercased', is_array( $negotiated ) ? $negotiated['type'] : null, 'image/png' );
check( 'the response body is capped before it is read', $GLOBALS['__http_args']['limit_response_size'] ?? null, SiteIconFallback\Icon_Fetch\MAX_ICON_BYTES + 1 );

// Declaring nothing is not the same claim as declaring something refused. Altis Tachyon
// answers image requests with a 200, the real bytes, and no Content-Type header at all, so
// treating the two alike costs the byte path on every install sitting behind it.
$GLOBALS['__http'] = [ 'code' => 200, 'body' => $png, 'type' => '' ];
$undeclared = SiteIconFallback\Icon_Fetch\request_icon( 'https://cdn.example.com/icon.png' );
check( 'an undeclared type is recognised from the bytes', is_array( $undeclared ) ? $undeclared['type'] : null, 'image/png' );

// What keeps the allow-list intact: sniffing can only ever name a type already in it, and
// none of the things the list exists to refuse carry an image signature.
$GLOBALS['__http']['body'] = '<!DOCTYPE html><html><body>404 Not Found</body></html>';
check( 'an undeclared HTML error page is still refused', SiteIconFallback\Icon_Fetch\request_icon( 'https://cdn.example.com/icon.png' ), null );

$GLOBALS['__http']['body'] = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
check( 'an undeclared SVG is still refused', SiteIconFallback\Icon_Fetch\request_icon( 'https://cdn.example.com/icon.png' ), null );

$GLOBALS['__http']['body'] = "\x00\x00\x01\x00\x01\x00\x10\x10";
$sniffed_ico = SiteIconFallback\Icon_Fetch\request_icon( 'https://cdn.example.com/icon.ico' );
check( 'an undeclared ico is recognised', is_array( $sniffed_ico ) ? $sniffed_ico['type'] : null, 'image/x-icon' );

// RIFF and ISO base media containers name their format in a later field, so a signature
// compared at byte zero cannot tell a WebP from any other RIFF file.
$GLOBALS['__http']['body'] = 'RIFF' . "\x24\x00\x00\x00" . 'WEBPVP8 ';
$sniffed_webp = SiteIconFallback\Icon_Fetch\request_icon( 'https://cdn.example.com/icon.png' );
check( 'an undeclared webp is recognised past its container header', is_array( $sniffed_webp ) ? $sniffed_webp['type'] : null, 'image/webp' );

$GLOBALS['__http']['body'] = 'RIFF' . "\x24\x00\x00\x00" . 'WAVEfmt ';
check( 'a RIFF container that is not an image is refused', SiteIconFallback\Icon_Fetch\request_icon( 'https://cdn.example.com/icon.png' ), null );

// A declared type is still the one that counts. Sniffing is the fallback for silence, not
// a second opinion on an answer already given.
$GLOBALS['__http'] = [ 'code' => 200, 'body' => $png, 'type' => 'text/html; charset=UTF-8' ];
check( 'a declared type is not overridden by the bytes', SiteIconFallback\Icon_Fetch\request_icon( 'https://cdn.example.com/icon.png' ), null );

echo "\nFailed fetches are not retried on every request\n";
// Every miss otherwise costs a blocking three-second request, and the traffic on these
// paths is anonymous crawlers arriving in bursts.
$GLOBALS['__transients'] = [];
$GLOBALS['__http']       = [ 'code' => 0, 'body' => '', 'type' => '' ];
$GLOBALS['__http_calls'] = 0;

$gone     = 'https://cdn.example.com/gone.png';
$gone_key = SiteIconFallback\Icon_Fetch\BYTES_TRANSIENT_PREFIX . md5( $gone );

check( 'a failed fetch returns null', SiteIconFallback\Icon_Fetch\fetch_icon( $gone, 180 ), null );
check( 'one request was made', $GLOBALS['__http_calls'], 1 );
check( 'the next call also returns null', SiteIconFallback\Icon_Fetch\fetch_icon( $gone, 180 ), null );
check( 'the request was not repeated', $GLOBALS['__http_calls'], 1 );
check( 'the failure is held for less time than the icon', ( $GLOBALS['__transients'][ $gone_key ]['ttl'] ?? 0 ) < SiteIconFallback\get_content_max_age(), true );

$GLOBALS['__http'] = [ 'code' => 200, 'body' => $png, 'type' => 'image/png' ];
$good     = 'https://cdn.example.com/good.png';
$good_key = SiteIconFallback\Icon_Fetch\BYTES_TRANSIENT_PREFIX . md5( $good );

check( 'a successful fetch returns the bytes', ( SiteIconFallback\Icon_Fetch\fetch_icon( $good, 180 )['body'] ?? null ), $png );
check( 'and is cached for the content lifetime', $GLOBALS['__transients'][ $good_key ]['ttl'] ?? null, SiteIconFallback\get_content_max_age() );
check( 'two requests in total', $GLOBALS['__http_calls'], 2 );
check( 'a cached icon is served from the cache', ( SiteIconFallback\Icon_Fetch\fetch_icon( $good, 180 )['body'] ?? null ), $png );
check( 'with no further request', $GLOBALS['__http_calls'], 2 );

echo "\nReading the icon from its attachment\n";
// The case this exists for: an image service has rewritten the Site Icon URL off the uploads
// path, so read_local_icon() cannot match it and the bytes would otherwise be fetched back
// over HTTP from the site's own front end.
$GLOBALS['__transients'] = [];
$GLOBALS['__http']       = [ 'code' => 0, 'body' => '', 'type' => '' ];
$GLOBALS['__http_calls'] = 0;

$icon_dir = $GLOBALS['__uploads']['basedir'] . '/2026/08';
@mkdir( $icon_dir, 0777, true );
file_put_contents( $icon_dir . '/favicon.png', $png . 'full' );
file_put_contents( $icon_dir . '/favicon-180x180.png', $png . '180' );
file_put_contents( $icon_dir . '/favicon-192x192.png', $png . '192' );

$GLOBALS['__options']['site_icon'] = 7;
$GLOBALS['__attached']             = $icon_dir . '/favicon.png';
$GLOBALS['__attachment']           = [
	'file'  => '2026/08/favicon.png',
	'sizes' => [
		'site_icon-180' => [ 'file' => 'favicon-180x180.png', 'width' => 180, 'height' => 180 ],
		'site_icon-192' => [ 'file' => 'favicon-192x192.png', 'width' => 192, 'height' => 192 ],
		// Not a Site Icon size, and not square. Picking it would serve a 300x200 image under
		// a square filename, and the file does not exist so the read would fail outright.
		'medium'        => [ 'file' => 'favicon-300x200.png', 'width' => 300, 'height' => 200 ],
	],
];

$rewritten = 'https://example.com/tachyon/2026/08/favicon.png?fit=180,180';

check( 'a rewritten URL is served from disk', ( SiteIconFallback\Icon_Fetch\fetch_icon( $rewritten, 180 )['body'] ?? null ), $png . '180' );
check( 'with no HTTP request at all', $GLOBALS['__http_calls'], 0 );

$GLOBALS['__transients'] = [];
check( 'a smaller size takes the next derivative up', ( SiteIconFallback\Icon_Fetch\fetch_icon( $rewritten . '&a', 120 )['body'] ?? null ), $png . '180' );
$GLOBALS['__transients'] = [];
check( 'an exact size takes its own derivative', ( SiteIconFallback\Icon_Fetch\fetch_icon( $rewritten . '&b', 192 )['body'] ?? null ), $png . '192' );
$GLOBALS['__transients'] = [];
check( 'a size above every derivative falls back to the original', ( SiteIconFallback\Icon_Fetch\fetch_icon( $rewritten . '&c', 270 )['body'] ?? null ), $png . 'full' );
check( 'and none of that touched the network', $GLOBALS['__http_calls'], 0 );

// Precedence: a URL that does map into uploads is still read by URL, so the existing path is
// unchanged wherever it already worked.
$GLOBALS['__transients'] = [];
check( 'an uploads URL still wins over the attachment', ( SiteIconFallback\Icon_Fetch\fetch_icon( 'https://example.com/wp-content/uploads/2018/12/icon.png', 180 )['body'] ?? null ), $png );

$GLOBALS['__transients']           = [];
$GLOBALS['__options']['site_icon'] = 0;
$GLOBALS['__http']                 = [ 'code' => 200, 'body' => $png, 'type' => 'image/png' ];
check( 'without the option the network is still used', ( SiteIconFallback\Icon_Fetch\fetch_icon( $rewritten, 180 )['body'] ?? null ), $png );
check( 'and that took a request', $GLOBALS['__http_calls'], 1 );

$GLOBALS['__transients']           = [];
$GLOBALS['__options']['site_icon'] = 7;
$GLOBALS['__attached']             = '';
check( 'an attachment with no file on disk falls through', ( SiteIconFallback\Icon_Fetch\fetch_icon( $rewritten, 180 )['body'] ?? null ), $png );
check( 'which also took a request', $GLOBALS['__http_calls'], 2 );

$GLOBALS['__options']['site_icon'] = 0;
unlink( $icon_dir . '/favicon.png' );
unlink( $icon_dir . '/favicon-180x180.png' );
unlink( $icon_dir . '/favicon-192x192.png' );
unlink( $dir . '/icon.png' );

echo "\nnginx snippet\n";
// The only server config the plugin generates. The request handler answers paths relative
// to the home URL, so on a subdirectory install rules written against the domain root match
// paths this WordPress does not own — and the try_files fallback points at whatever sits at
// the domain root instead of at this install's index.php.
$root_snippet = SiteIconFallback\Server_Config\get_nginx_snippet();

check( 'root install is left alone', str_contains( $root_snippet, 'location ~ ^/apple-touch-icon' ), true );
check( 'root install falls back to its own index.php', str_contains( $root_snippet, 'try_files $uri /index.php?$args;' ), true );

// try_files $uri, not a bare rewrite: a real favicon.ico sitting at the web root has to
// keep being served. This is the whole of the plugin's file-wins guarantee now.
check( 'a real file still wins', substr_count( $root_snippet, 'try_files $uri' ), 2 );

$GLOBALS['__home_url'] = 'https://example.com/blog/';
$subdir_snippet        = SiteIconFallback\Server_Config\get_nginx_snippet();

check( 'subdirectory locations carry the base', str_contains( $subdir_snippet, 'location ~ ^/blog/apple-touch-icon' ), true );
check( 'subdirectory favicon location carries the base', str_contains( $subdir_snippet, 'location ~ ^/blog/favicon' ), true );
check( 'subdirectory fallback points at the install', str_contains( $subdir_snippet, 'try_files $uri /blog/index.php?$args;' ), true );
check( 'no rule is left at the domain root', str_contains( $subdir_snippet, '^/apple-touch-icon' ), false );

// A location added to nginx.conf.example without being rebased would match at the domain
// root on every subdirectory install, silently. Counting rather than naming the two we
// know about is what makes that a test failure instead of a surprise.
$locations = preg_match_all( '/^location ~ \^/m', $subdir_snippet );
$rebased   = preg_match_all( '/^location ~ \^\/blog\//m', $subdir_snippet );
check( 'every location is rebased, not just the two we assert on', $rebased, $locations );

// The shell installer writes the same block on hosts where WordPress cannot. It reads the
// same file, so only the rebasing can drift — and a mismatch is a config that routes icons
// to the wrong place on exactly the installs that need the flag.
$installer = escapeshellarg( dirname( __DIR__ ) . '/bin/install-nginx-config.sh' );
$blank     = tempnam( sys_get_temp_dir(), 'sif-nginx' );
$shell     = (string) shell_exec( "bash {$installer} --target " . escapeshellarg( $blank ) . ' --base blog --dry-run 2>/dev/null' );
$fenced    = [];
preg_match( '/# BEGIN Site Icon Fallback\n(.*)\n# END Site Icon Fallback/s', $shell, $fenced );
unlink( $blank );

check( 'the installer produced a block', isset( $fenced[1] ), true );
check( 'PHP and the shell installer agree on the rebased block', trim( $fenced[1] ?? '' ), trim( $subdir_snippet ) );

$GLOBALS['__home_url'] = 'https://example.com/';

echo "\nSite Health registration\n";
// Direct tests run inline while the Site Health page renders, and this one makes a loopback
// request with a three-second timeout. Core registers its own loopback test as async for
// the same reason.
$tests = SiteIconFallback\Site_Health\register_site_health_test( [ 'direct' => [], 'async' => [] ] );

check( 'the test is async', array_keys( $tests['async'] ), [ SiteIconFallback\Site_Health\TEST_SLUG ] );
check( 'nothing is registered as direct', $tests['direct'], [] );
check( 'cron has a way to run it', is_callable( $tests['async'][ SiteIconFallback\Site_Health\TEST_SLUG ]['async_direct_test'] ), true );

// Site Health's JavaScript builds the Ajax action as 'health-check-' + test.replace('_','-'),
// and a string argument to replace() swaps only the first match. A slug with two underscores
// would therefore be asked for under a name we never registered, and the test would spin
// forever in the browser.
$slug     = $tests['async'][ SiteIconFallback\Site_Health\TEST_SLUG ]['test'];
$js_built = 'health-check-' . preg_replace( '/_/', '-', $slug, 1 );

check( 'the action Site Health calls is the one we register', $js_built, SiteIconFallback\Site_Health\AJAX_ACTION );
check( 'the result identifies the same test', SiteIconFallback\Site_Health\run_reachability_test()['test'], $slug );

echo "\nUninstall\n";
// Run out of process: uninstall.php exits when WP_UNINSTALL_PLUGIN is absent, which would
// otherwise take this runner with it — and that guard is the only thing standing between a
// direct request for the file and a delete.
$harness = escapeshellarg( __DIR__ . '/uninstall-harness.php' );

check( 'nothing happens without WP_UNINSTALL_PLUGIN', trim( (string) shell_exec( "php {$harness} 2>&1" ) ), '' );

$uninstalled = json_decode( (string) shell_exec( "php {$harness} run 2>&1" ), true );

check( 'the reachability transient is removed', $uninstalled['transients'] ?? null, [ SiteIconFallback\Site_Health\REACHABILITY_TRANSIENT ] );

// The plugin registers no activation hook and owns no option. Everything it stores is a
// transient, and this is what keeps that true — the harness still stubs delete_site_option,
// so an option creeping back in shows up here rather than in someone's database.
check( 'no options are deleted, because none are written', $uninstalled['site_options'] ?? null, [] );
check( 'one query sweeps the cached bytes', count( $uninstalled['queries'] ?? [] ), 1 );

// An underscore is a single-character wildcard in LIKE, and these key names are mostly
// underscores. Unescaped, the sweep matches option names that are not ours.
$query = $uninstalled['queries'][0] ?? '';

check( 'the bytes prefix is matched', str_contains( $query, str_replace( '_', '\\_', SiteIconFallback\Icon_Fetch\BYTES_TRANSIENT_PREFIX ) ), true );
check( 'the LIKE patterns are escaped', str_contains( $query, "'_transient_" ), false );
check( 'timeouts go too', substr_count( $query, 'LIKE' ), 2 );

echo "\nConditional requests\n";
unset( $_SERVER['HTTP_IF_NONE_MATCH'] );
check( 'absent If-None-Match is empty', get_if_none_match(), '' );
$_SERVER['HTTP_IF_NONE_MATCH'] = '  "abc123"  ';
check( 'If-None-Match is trimmed', get_if_none_match(), '"abc123"' );
unset( $_SERVER['HTTP_IF_NONE_MATCH'] );

echo "\nCache lifetimes\n";
// A redirect points at a URL that a Site Icon change deletes, so it must not be held as
// long as the icon itself — that is what left stale 302s replaying into 404s.
check( 'content cached for a day', SiteIconFallback\get_content_max_age(), DAY_IN_SECONDS );
check( 'redirect cached briefly', SiteIconFallback\get_redirect_max_age(), 300 );
check( 'redirect much shorter than content', SiteIconFallback\get_redirect_max_age() < SiteIconFallback\get_content_max_age(), true );
check( 'missing cached briefly', SiteIconFallback\get_missing_max_age(), 300 );

echo "\nActivation is gated on nginx\n";
// Core fires the activation hook before it writes active_plugins, so a wp_die() here is the
// whole mechanism — the plugin is simply never recorded as active.

/** Run on_activation() and report whether it refused. */
function refused(): bool {
	try {
		SiteIconFallback\Lifecycle\on_activation();
	} catch ( Activation_Refused $e ) {
		return true;
	}

	return false;
}

$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.24.0';
$GLOBALS['is_nginx']        = true;
check( 'nginx activates', refused(), false );

$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.58';
$GLOBALS['is_nginx']        = false;
check( 'a server that says it is not nginx is refused', refused(), true );

// WP-CLI sets four $_SERVER keys and SERVER_SOFTWARE is not one of them, while core's
// wp_fix_server_vars() defaults it to '' — so $is_nginx is false for every scripted
// activation, exactly as if the server had answered Apache. Refusing here would mean no
// deploy could ever install this plugin.
unset( $_SERVER['SERVER_SOFTWARE'] );
check( 'no server means nothing to contradict, so activation proceeds', refused(), false );
check( 'and that is not the same as nginx being detected', SiteIconFallback\Server_Config\is_nginx(), false );

$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.58';

// Detection reads a header the server chooses to send: nginx proxying to Apache reports
// Apache, and a context with no SERVER_SOFTWARE reports nothing. Without a way out, a false
// negative locks the install out of its own plugin permanently.
$GLOBALS['__filters']['site_icon_fallback_require_nginx'] = false;
check( 'the filter is a way past a wrong answer', refused(), false );
unset( $GLOBALS['__filters']['site_icon_fallback_require_nginx'] );

check( 'and the gate closes again after it', refused(), true );
$GLOBALS['is_nginx'] = true;
unset( $_SERVER['SERVER_SOFTWARE'] );

echo "\nServer identification\n";
// Core collapses "said nothing" and "said Apache" into the same false, so the plugin has to
// read the raw value to tell a CLI run from the wrong web server.
check( 'nothing reported is an empty string', SiteIconFallback\Server_Config\get_server_software(), '' );
$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.24.0';
check( 'what the server says is what comes back', SiteIconFallback\Server_Config\get_server_software(), 'nginx/1.24.0' );
unset( $_SERVER['SERVER_SOFTWARE'] );

echo "\nWP-CLI\n";
// Nothing here loads WP-CLI, so these assert the guard that keeps the plugin from calling
// into a class that is not there — every WP_CLI call in cli.php sits behind it.
check( 'not running under WP-CLI', SiteIconFallback\CLI\is_running(), false );
check( 'registering commands outside WP-CLI is a no-op', SiteIconFallback\CLI\register_commands(), null );
check( 'warning outside WP-CLI is a no-op', SiteIconFallback\CLI\warn( 'unheard' ), null );

// The other half, out of process: WP_CLI has to exist before inc/cli.php loads, which this
// runner cannot arrange for itself while also asserting the absent case above.
$cli = escapeshellarg( __DIR__ . '/cli-harness.php' );

/** Run the CLI harness in one of its modes and return what it recorded. */
function cli( string $mode ): array {
	global $cli;

	return (array) json_decode( (string) shell_exec( "php {$cli} {$mode} 2>&1" ), true );
}

$registered = cli( 'status' );

check( 'both commands are registered', array_keys( $registered['commands'] ?? [] ), [ 'site-icon-fallback status', 'site-icon-fallback nginx-config' ] );
check( 'status is wired to its callable', $registered['commands']['site-icon-fallback status']['callable'] ?? null, 'SiteIconFallback\\CLI\\status_command' );

// format_items() is reached through `use WP_CLI;`, which resolves WP_CLI\Utils\ to the
// global namespace. Written without that import it becomes SiteIconFallback\CLI\WP_CLI\...,
// which does not exist — and nothing here would say so except this.
check( 'the format helper resolves to the global namespace', $registered['format'] ?? null, 'json' );
check( 'every check is reported', count( $registered['rows'] ?? [] ), 4 );
check( 'the Site Icon is found', $registered['rows'][0]['status'] ?? null, 'ok' );
check( 'an unidentified server is a warning, not a failure', $registered['rows'][1]['status'] ?? null, 'warn' );

$strict = cli( 'status-strict' );

check( '--strict exits non-zero when checks fail', $strict['halt'] ?? null, 1 );
check( 'a missing Site Icon is a failure', $strict['rows'][0]['status'] ?? null, 'fail' );
check( 'an unreachable handler is a failure', $strict['rows'][2]['status'] ?? null, 'fail' );

$printed = cli( 'nginx-config' );

check( 'nginx-config prints the snippet', str_contains( $printed['lines'][0] ?? '', 'location ~ ^/apple-touch-icon' ), true );

// The whole point of the exercise: a scripted activation must not be refused for want of a
// header WP-CLI never sends.
$activated = cli( 'activation' );

check( 'CLI activation warns instead of refusing', count( $activated['warnings'] ?? [] ), 1 );
check( 'and it says why it could not check', str_contains( $activated['warnings'][0] ?? '', 'No web server was available' ), true );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail === 0 ? 0 : 1 );
