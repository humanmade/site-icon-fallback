=== Site Icon Fallback ===
Contributors: stuartshields
Tags: favicon, site icon, apple-touch-icon, safari, ios
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: http://www.gnu.org/licenses/gpl-2.0.txt

Serves the root icon paths Safari, Applebot and link unfurlers probe for, from the Site Icon you already set.

== Description ==

WordPress lets you set a Site Icon, then declares it in the page head. But a lot of clients never read that markup. Applebot, Safari's Favourites thumbnailer, Add to Home Screen, Reading List, and the link unfurlers in Slack and iMessage all request icons directly from your domain root — `/apple-touch-icon.png`, `/apple-touch-icon-152x152.png`, `/favicon.ico` — and get a 404.

This plugin answers those paths from your Site Icon. Change the icon in Settings, and the root paths follow. Nothing is hardcoded and no files are copied into your web root.

It does two things:

1. **Declares the sized icon tags.** Core emits a single `apple-touch-icon` link with no `sizes` attribute, so a client after a specific size has no exact match to pick. This plugin considers 120, 152, 167 and 180, and declares each size that is backed by a genuinely different image.

   That last part matters. WordPress generates only four Site Icon derivatives — 270, 192, 180 and 32 — and resolves any other size to the smallest generated one at least as large. Without an image service in front, all four candidate sizes come back as the same 180x180 file, and declaring them all would put four tags in your head claiming four sizes for one image. Sizes that collapse onto the same file are declared once, at the largest size that resolved to it. With an image service such as Tachyon or Photon, every size resolves to its own derivative and all four are declared.

2. **Answers the root paths.** Requests for `/apple-touch-icon*.png`, `/favicon.ico` and `/favicon.png` return the Site Icon at the size asked for, as a 200 with the image bytes. This needs those requests to reach WordPress, which they already do on Apache and on standard nginx configurations.

Where they don't reach WordPress — some tuned nginx configurations answer static paths themselves — **Tools > Site Health** tests it directly and prints the exact configuration snippet you need.

== Installing the nginx rules ==

On nginx, the rules in `nginx.conf.example` need to be in your server configuration. On Altis, `bin/install-nginx-config.sh` will put them there for you:

    ./bin/install-nginx-config.sh              # install into .config/nginx-additions.conf
    ./bin/install-nginx-config.sh --dry-run    # show the result, write nothing
    ./bin/install-nginx-config.sh --target PATH
    ./bin/install-nginx-config.sh --remove

The block is fenced between `# BEGIN Site Icon Fallback` and `# END Site Icon Fallback`, the same way WordPress manages its own `.htaccess` section. Re-running replaces that block rather than appending a second copy — nginx rejects duplicate `location` directives, so appending blindly would take the site down. Everything outside the markers is left untouched, and `--remove` restores the file exactly as it was.

Restart or reload nginx afterwards. On Altis Cloud the configuration ships with a deploy.

== Frequently Asked Questions ==

= Do I need to change my server configuration? =

Usually no. Apache routes unknown paths to `index.php` through the rewrite block WordPress already writes, and the standard nginx recipe does the same with `try_files`. Site Health will tell you if yours doesn't, and give you the snippet.

= Does it redirect, or serve the image? =

It serves the image — a 200 with the bytes, an `ETag`, and a long `Cache-Control`. Redirecting would be cheaper, but nothing guarantees an icon fetcher follows a redirect, and those fetchers are exactly the clients this plugin exists for. A conditional request with a matching `If-None-Match` gets a 304.

The bytes come from disk when the Site Icon lives in the uploads directory, and over HTTP when a CDN or image service has rewritten the URL. Either way the result is cached, so PHP does the work once per cache period rather than once per request. The HTTP fetch asks for PNG explicitly, which stops an image service content-negotiating WebP into a URL ending in `.png`.

To redirect instead:

    add_filter( 'site_icon_fallback_serve_mode', fn() => 'redirect' );

That sends a 302, never a 301 — browsers cache a permanent redirect more or less forever, so changing your Site Icon would never reach anyone who had already requested it.

= What happens if no Site Icon is set? =

The root paths return a 404. Notably this is *not* what core does for `/favicon.ico`, which falls back to the WordPress logo — a site with no icon should look like it has no icon, not like WordPress.

= Which sizes are supported? =

57, 60, 72, 76, 114, 120, 144, 152, 167, 180 and 192. Sizes outside that list are refused, so the endpoint cannot be used to generate arbitrary image derivatives.

== Changelog ==

= 1.0.0 =
* Initial release.
