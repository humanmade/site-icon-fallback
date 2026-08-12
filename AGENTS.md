# Site Icon Fallback

A standalone WordPress plugin. It answers the root icon paths clients probe for — `/apple-touch-icon*.png`, `/favicon.ico`, `/favicon.png` — from the Site Icon already set in Settings, instead of letting them 404.

## The problem it solves

WordPress declares the Site Icon in the page head, but a lot of clients never read that markup. Applebot, Safari's Favourites thumbnailer, Add to Home Screen, Reading List, and the link unfurlers in Slack and iMessage request icons straight from the domain root and get a 404.

Apple documents the root lookup as a *fallback*: declared `<link>` elements win, and the root is searched when none match. Both halves of the plugin follow from that.

## Architecture

Two independent layers. Layer 1 works everywhere unaided; Layer 2 needs requests to reach PHP.

| File | Responsibility |
| --- | --- |
| `plugin.php` | Header, `VERSION`, requires, calls `bootstrap()` |
| `inc/namespace.php` | Hook wiring, supported sizes, cache lifetimes |
| `inc/meta-tags.php` | **Layer 1** — sized `apple-touch-icon` tags via `site_icon_meta_tags` |
| `inc/root-handler.php` | **Layer 2** — path matching, size resolution, response dispatch |
| `inc/icon-fetch.php` | Getting the icon bytes: cache, local disk, HTTP, type allow-list |
| `inc/icon-stream.php` | Emitting those bytes: headers, `ETag`/304 |
| `inc/site-health.php` | Loopback test reporting whether requests reach PHP |
| `inc/server-config.php` | nginx detection, and the snippet rooted at `home_url()` |
| `inc/lifecycle.php` | Refuses activation when the server reports as non-nginx |
| `inc/cli.php` | WP-CLI commands, and the guard every `WP_CLI` call sits behind |
| `inc/admin-notices.php` | Warning when no Site Icon is set |
| `uninstall.php` | Deletes the cached bytes and the reachability transient |
| `bin/install-nginx-config.sh` | Idempotent, marker-fenced nginx config installer |

## Decisions that are load-bearing

Change these only with the reasoning in mind — each one exists because the obvious alternative is wrong.

**Path matching on `init:100`, not a rewrite rule.** Rewrite rules need a flush to take effect and a deploy cannot be relied on to perform one. Priority 100 is late enough for CDN and media URL filters to have registered, early enough to skip the main query.

**Serve bytes, don't redirect.** Nothing guarantees an icon fetcher follows a redirect, and those fetchers are the entire audience. Redirecting remains available via `site_icon_fallback_serve_mode` and is the fallback when fetching bytes fails.

**Content and redirects get different cache lifetimes.** Bytes are content and can be cached hard — a Site Icon change also changes the URL, so stale bytes cannot surface under a live address. A redirect is a *pointer*, and a Site Icon change deletes what it points at, so a long-cached redirect leaves clients replaying it into a 404. This was a real shipped bug; don't collapse the two.

**Declared sizes are deduplicated by URL.** Core generates only four Site Icon derivatives — 270, 192, 180, 32 — and resolves anything else to the smallest one at least as large. Without an image service, 120/152/167/180 all return the same 180x180 file, and declaring all four would claim four sizes for one image. `get_declarable_icons()` collapses them.

**Sizes are allow-listed.** `SUPPORTED_SIZES` is a closed set and non-square filenames are refused, so the endpoint cannot be driven as an arbitrary image-resize service.

**Content types are allow-listed too.** `ALLOWED_TYPES` is closed, and both the local and HTTP paths run their declared type through `get_servable_type()`. What is served here goes out under a `.png` or `.ico` URL, from the site's own origin, cached for a day: without the list an image CDN's 200 HTML error page gets stored and echoed as `text/html`, and an SVG Site Icon is served as `image/svg+xml`, which a browser will run script from on direct navigation. A refused type falls through to a redirect. `nosniff` accompanies the bytes.

**Failed fetches are cached, briefly.** Only successes were cached originally, so a Site Icon on a slow or unreachable host cost a fresh blocking three-second request on *every* probe — and this path exists to serve crawlers arriving in bursts. `FETCH_FAILED` is stored under the same key for `get_failure_cache_lifetime()` (5 minutes), deliberately far below `get_content_max_age()`, since the fallback in the meantime is a working redirect.

**`MAX_ICON_BYTES` applies to both fetch paths.** The local read checks `filesize()` *before* `file_get_contents()`, so an oversized original is never pulled into memory; the HTTP request passes `limit_response_size` as the cap **plus one**, because that truncates rather than errors and a body stopped exactly at the cap would look like one that fits. A Site Icon set with `wp option update site_icon <id>` generates no `site_icon-*` derivatives, so the URL resolves to the full-size upload — this is the common trigger, not a hypothetical one.

**HTTP fetches send `Accept: image/png`.** Image services content-negotiate on `Accept` and will return WebP under a `.png` URL to anything that offers it.

**Marker header `X-Site-Icon-Fallback`.** A 404 alone is ambiguous — it is what the web server sends when it never routed the request *and* what this plugin sends when there is no icon. Only the header distinguishes them, and Site Health depends on it.

**nginx only, and the plugin writes nothing.** Apache is not generated for, because Apache needs nothing: core's own `.htaccess` block already sends non-existent paths to `index.php`, which is precisely what the nginx snippet asks nginx to do. The plugin previously wrote its own `.htaccess` block for the narrow case of a host that had gutted core's rewrite rules — that cost `htaccess.php`, a multisite active-site registry and a network option, to automate four lines on the one platform where automation was least needed. nginx, where requests genuinely do not reach PHP, has no per-directory config a plugin could write at all. So there is no deactivation hook, nothing is ever written to disk, and the plugin owns no options.

**Activation is refused when the server reports itself as non-nginx**, so that a plugin which can only speak nginx says so once, to the person who can act on it, rather than sitting there looking installed. `wp_die()` in the activation hook is the entire mechanism — core fires that hook *before* writing `active_plugins`, so nothing is recorded and there is nothing to undo. Never runs from mu-plugins, where activation does not exist.

**"Not nginx" and "nothing answered" are different, and only the raw value tells them apart.** Core collapses both into `$is_nginx === false`: `wp_fix_server_vars()` defaults `SERVER_SOFTWARE` to `''` (`wp-includes/load.php`) before `vars.php` derives the flag. WP-CLI is why it matters — it sets four `$_SERVER` keys and `SERVER_SOFTWARE` is not among them, so every scripted `wp plugin activate` looks exactly like activation on the wrong web server. Gating on `$is_nginx` alone would mean no deploy could ever install this plugin. `get_server_software()` returns the raw string, and an empty one skips the gate with a CLI warning rather than blocking.

**The `site_icon_fallback_require_nginx` escape hatch is not optional.** Detection reads a header the server chooses to send, and nginx proxying to Apache reports Apache — a positive, wrong answer that the CLI carve-out does not cover. Without a way past it, a false negative locks an install out of its own plugin with no route back through wp-admin.

**The snippet lets a real file win.** `try_files $uri` first: a hand-placed `favicon.ico` at the web root must keep being served, and only reaching `index.php` when the file is absent is what guarantees it.

**Server config is rooted at `home_url()`, not at `/`.** The request handler answers paths relative to the home URL, so rules written against the domain root never match on a subdirectory install. `get_nginx_snippet()` rebases the bundled `nginx.conf.example` through `apply_home_root()`, moving both the `location` patterns and the `try_files` fallback — the fallback matters as much, since `/index.php` at the domain root is not this install. The shell installer takes `--base` for the same reason, and a test asserts the two produce the same block, since only the rebasing can drift.

**The Site Health test is async.** Direct tests run inline while the Site Health page renders, and this one makes a loopback request with a three-second timeout; core registers its own loopback test as async for the same reason. Two details are load-bearing: `TEST_SLUG` carries **no underscores**, because Site Health's JavaScript builds the Ajax action as `'health-check-' + test.replace( '_', '-' )` and a string argument to `replace()` swaps only the first match (core's own async tests each happen to contain exactly one underscore); and `async_direct_test` is supplied, because the weekly cron run otherwise posts the raw slug as the action name rather than the prefixed one the browser sends.

**Every module file carries `defined( 'ABSPATH' ) || exit;`.** Not decoration — a direct request for `inc/icon-stream.php` fatals on the undefined `KB_IN_BYTES` without it. The `PSR1.Files.SideEffects` exclusion in `.phpcs.xml` is justified on the grounds that the guard is present, so the two have to stay true together.

**Uninstall cleans this site, not the network.** Everything the plugin stores is a transient — it owns no options. The cached-bytes ones are keyed by a hash of the icon URL, so they can only be matched, not named: one `LIKE` sweep of the current site's options table, with the whole pattern passed through `esc_like()` because `_` is a single-character wildcard and `_transient_` is mostly underscores. Other sites on a network are deliberately left alone: what they hold expires within a day by itself and core's daily `delete_expired_transients()` reclaims it, which beats an unbounded `switch_to_blog()` loop while an admin waits on a delete. A test asserts no option is deleted, because none should ever be written.

## Environment constraints

**Requests must reach PHP.** Apache is fine unaided — core's own `.htaccess` sends non-existent files to `index.php`, so the plugin works there with nothing generated for it. Standard nginx `try_files` is fine too. Tuned nginx configs that terminate static extensions are not, and are the reason `nginx.conf.example` exists.

**`location = /favicon.ico` cannot be overridden.** An exact-match location beats every regex regardless of ordering, and redeclaring it is a duplicate-location error that stops nginx booting. Where a host ships one (Altis does), `/favicon.ico` is unwinnable; `/favicon.png` still works.

**Managed hosts block writes outside uploads.** This is no longer a constraint to work around — the plugin writes no files anywhere. It is why it does not: any file-writing path would have been dead code on exactly the hosts this plugin is deployed to.

## Conventions

- Tabs for indentation. Spaces in `.yml`, `.md` frontmatter, and other formats that require them.
- `declare( strict_types=1 );` at the top of every PHP file, then the file's namespace.
- **One namespace per file, named after the file.** `inc/namespace.php` is the root `SiteIconFallback`; every other `inc/*.php` declares a sub-namespace matching its filename — `root-handler.php` → `SiteIconFallback\Root_Handler`. The HM standard enforces this (`HM.Files.FunctionFileName`), and phpcs fails otherwise.
- **Cross-file references must be qualified.** PHP falls back to the *global* namespace for unqualified functions and constants, never the parent, so a bare cross-module call parses cleanly and fatals at runtime. Import the namespace (`use SiteIconFallback\Icon_Fetch;`) and call through it (`Icon_Fetch\fetch_icon()`). Shared constants and cache-lifetime helpers live in the root namespace, reached via `use SiteIconFallback;`.
- Hook callbacks are strings, so nothing checks them at parse time. A callback in another module needs its full path: `__NAMESPACE__ . '\\Root_Handler\\maybe_serve_root_icon'`.
- Named functions in hooks, never closures — a closure cannot be unhooked.
- Type hints on parameters and returns.
- Text domain `site-icon-fallback`, matching the slug.
- `defined( 'ABSPATH' ) || exit;` after the namespace and any `use` imports, in every file. `plugin.php` keeps the `if` form the plugin-header convention uses.
- **One concern per file, and the tell is the word "and".** A file whose description needs one is two files. `icon-fetch.php` gets the icon's bytes and `icon-stream.php` emits them; the dependency points one way, and the caller imports both (`Icon_Fetch\fetch_icon()`, then `Icon_Stream\send_icon_bytes()`).
- Length is the symptom, not the rule. Past roughly 200 lines, look for the seam — but don't cut where there isn't one. `icon-fetch.php` is 218 lines of a single concern, and pulling `ALLOWED_TYPES` out would only separate the allow-list from its two callers.
- Comments explain *why*, particularly where a simpler-looking alternative is wrong.

## Commands

| Command | What it does |
| --- | --- |
| `composer install` | Installs `humanmade/coding-standards` (the only dependency) |
| `composer phpcs` | Lints `inc/`, `plugin.php` and `uninstall.php` against the HM standard via `.phpcs.xml` |
| `composer phpcbf` | Auto-fixes what phpcs can |
| `npm test` | Runs both suites below |
| `npm run test:php` | `tests/test-routing.php` — plain PHP, no WordPress bootstrap |
| `npm run test:sh` | `tests/test-nginx-installer.sh` — drives the installer against temp files |
| `wp site-icon-fallback status` | The plugin's own check: icon set, server, reachability, serve mode |
| `wp site-icon-fallback nginx-config` | Prints the snippet for this install |
| `npm run env:start` | Boots `@wordpress/env` on port 3031 with Query Monitor |

The coding standard is `humanmade/coding-standards` (HM), not the `WordPress-VIP-Go` set used during development against the host project's `vendor/`.
