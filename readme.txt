=== Site Icon Fallback ===
Contributors: stuartshields
Tags: favicon, site icon, apple-touch-icon, safari, ios
Requires at least: 6.7
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 0.1.5
License: GPL-2.0-or-later
License URI: http://www.gnu.org/licenses/gpl-2.0.txt

A lightweight fallback that serves your Site Icon from the site root, reducing 404s.

== Description ==

WordPress lets you set a Site Icon, then declares it in the page head. A lot of clients never read that markup. Applebot, Safari's Favourites thumbnailer, Add to Home Screen, Reading List, and the link unfurlers in Slack and iMessage ask your domain root directly. They request `/apple-touch-icon.png`, `/apple-touch-icon-152x152.png` and `/favicon.ico`, and get a 404.

This plugin answers those paths from your Site Icon. Change the icon in Settings, and the root paths follow. Nothing is hardcoded and no files are copied into your web root.

It does two things:

1. **Declares the sized icon tags.** Core emits a single `apple-touch-icon` link with no `sizes` attribute, so a client after a specific size has no exact match to pick. This plugin considers 120, 152, 167 and 180, and declares each size backed by a genuinely different image.

   Sizes that resolve to the same file are declared once. WordPress generates only four Site Icon derivatives: 270, 192, 180 and 32. It resolves any other size to the smallest generated one at least as large, so without an image service in front, all four candidate sizes come back as the same 180x180 file. Declaring them all would claim four sizes for one image. With an image service such as Tachyon or Photon, every size gets its own derivative and all four are declared.

2. **Answers the root paths.** Requests for `/apple-touch-icon*.png`, `/favicon.ico` and `/favicon.png` return the Site Icon at the size asked for, as a 200 with the image bytes. This half needs those requests to reach WordPress, which they already do on a standard nginx configuration.

This plugin supports nginx, and refuses to activate on a server that reports itself as something else.

Where root requests do not reach WordPress, **Tools > Site Health** tests it directly and prints the configuration snippet you need. Some tuned nginx configurations answer static paths themselves, which is the usual cause.

== Installation ==

1. Download the zip attached to the [latest release](https://github.com/humanmade/Site-icon-fallback/releases), which extracts to `site-icon-fallback/`.
2. Put that directory in `wp-content/plugins/`.
3. Activate the plugin in wp-admin, or run `wp plugin activate site-icon-fallback`.
4. Set a Site Icon in **Settings > General**, if you have not already.
5. Open **Tools > Site Health** and look for *Root icon requests reach WordPress*.

Most nginx configurations need nothing further. Where Site Health reports that root requests are not arriving, see "Configuring nginx" below.

Without a Site Icon the root paths return a 404, and an admin notice tells you. The plugin writes no files and stores no options, so activating and deactivating changes nothing on disk or in your database.

== Configuring nginx ==

You only need this where Site Health reports that root requests do not reach WordPress. The rules live in `nginx.conf.example`. On Altis, the bundled script installs them for you:

    ./bin/install-nginx-config.sh                 # install into .config/nginx-additions.conf
    ./bin/install-nginx-config.sh --target PATH   # install into a specific file
    ./bin/install-nginx-config.sh --base blog     # root the rules at a subdirectory install
    ./bin/install-nginx-config.sh --dry-run       # print the result, write nothing
    ./bin/install-nginx-config.sh --remove        # take the block back out

Reload nginx afterwards. On Altis Cloud the configuration ships with a deploy.

The block is fenced between `# BEGIN Site Icon Fallback` and `# END Site Icon Fallback`, the same way WordPress manages its own `.htaccess` section. Re-running the script replaces that block rather than appending a second copy. Appending would take the site down, because nginx rejects duplicate `location` directives. Everything outside the markers is left alone, and `--remove` restores the file exactly as it was.

Two things to check before you install the rules:

* Your configuration needs `root` declared at server level. The rules resolve a path against whatever `root` is in scope, so a real file at the web root stops being found otherwise.
* On a remote host without the script, run `wp site-icon-fallback nginx-config`. It prints the same rules, rooted at this install's home path.

== WP-CLI ==

    wp site-icon-fallback status              # can the plugin actually serve icons here?
    wp site-icon-fallback status --fresh      # re-test instead of reading the cached result
    wp site-icon-fallback status --strict     # exit non-zero when a check fails, for CI
    wp site-icon-fallback nginx-config        # print the nginx rules for this install

`status` answers the two questions Site Health answers, in a place a deploy script can read. Is a Site Icon set, and do root requests reach WordPress. It takes `--format=table|json|csv|yaml`.

One caveat before you wire `--strict` into CI. The reachability check is a loopback request to your home URL, so it fails wherever the machine running `wp` cannot reach your public address. That is common in containers. Confirm it agrees with `curl -I https://your-site/favicon.ico` first.

== Frequently Asked Questions ==

= Why is activation refused? =

The plugin supports nginx only, and refuses to activate when your web server reports itself as something else. WordPress works that out from `$_SERVER['SERVER_SOFTWARE']`, which the server chooses what to send. nginx sitting in front of Apache reports Apache, for instance. If you are on nginx and the check disagrees, return false from the `site_icon_fallback_require_nginx` filter in an mu-plugin.

Activating with WP-CLI always works. A CLI run has no web server to ask, so the check has nothing to go on and prints a warning instead of standing in the way of a deploy.

= Does it work on Apache? =

Only nginx is supported and tested. The gate is on activation alone, so returning false from `site_icon_fallback_require_nginx` lets the plugin run, and core's own `.htaccess` rules already send unknown paths to `index.php`. I generate no Apache configuration, because on Apache there is nothing to generate.

= Do I need to change my server configuration? =

Usually no. The standard nginx recipe already routes unknown paths to `index.php` with `try_files`. Site Health tells you where yours does not, and gives you the snippet to add.

= Does it redirect, or serve the image? =

It serves the image: a 200 with the bytes, an `ETag`, and a long `Cache-Control`. Redirecting would be cheaper, but nothing guarantees an icon fetcher follows a redirect, and those fetchers are exactly the clients this plugin exists for. A conditional request with a matching `If-None-Match` gets a 304.

The bytes come from disk when the Site Icon lives in the uploads directory, and over HTTP when a CDN or image service has rewritten the URL. Either way the result is cached, so PHP does the work once per cache period rather than once per request. The HTTP fetch asks for PNG explicitly, which stops an image service content-negotiating WebP into a URL ending in `.png`.

To redirect instead:

    add_filter( 'site_icon_fallback_serve_mode', fn() => 'redirect' );

That sends a 302, never a 301. Browsers cache a permanent redirect more or less forever, so changing your Site Icon would never reach anyone who had already requested it.

= What happens if no Site Icon is set? =

The root paths return a 404. This is deliberately not what core does for `/favicon.ico`, which falls back to the WordPress logo. A site with no icon should look like it has no icon, rather than like WordPress.

= Which sizes are supported? =

57, 60, 72, 76, 114, 120, 144, 152, 167, 180 and 192. Sizes outside that list are refused, so the endpoint cannot be used to generate arbitrary image derivatives. A bare `/apple-touch-icon.png` serves 180, and so do `/favicon.ico` and `/favicon.png`.

== Changelog ==

= 0.1.5 =
* `/favicon.ico` and `/favicon.png` now serve the Site Icon at 180px instead of 32px. Google Search recommends a favicon larger than 48x48, which the old size was under. 180 is one of the four sizes WordPress generates, so every site serves it exactly, with or without an image service.

= 0.1.4 =
* `/favicon.ico` is now answered on hosts that pin the path with an exact-match nginx location, such as Altis, where it previously returned a blank 1x1 image. An exact match cannot be overridden by another location, so the snippet reaches it with a rewrite instead.
* Reinstall the nginx rules for this to take effect: `bin/install-nginx-config.sh`, or copy the block from Tools > Site Health. A hand-placed `favicon.ico` at the web root is still served untouched.
* The rewrite runs before nginx picks a location, so it now takes precedence over a `location = /favicon.ico` of your own as well. If you were using one to suppress the path, remove it or put a real `favicon.ico` at the web root.

= 0.1.3 =
* Icon requests are now answered when `-precomposed` follows the dimensions, as in `/apple-touch-icon-152x152-precomposed.png` — the first filename iOS asks for.
* The nginx snippet matches the same variants. Reinstall it with `bin/install-nginx-config.sh`, or copy the block from Tools > Site Health.

= 0.1.2 =
* The Site Icon is now read from its attachment on disk when an image service has rewritten its URL, instead of being fetched back over HTTP from the site's own front end.
* Fixes icon requests falling back to a redirect on sites behind an image service, where that fetch could not succeed.

= 0.1.1 =
* Releases now include an installable site-icon-fallback.zip, instead of only the generated source archive.
* That archive extracts to a stable site-icon-fallback directory, so a manual upload no longer renames the plugin folder on every release.
* Development files are excluded from the release branch as well as from tagged archives.

= 0.1.0 =
* Initial release.
