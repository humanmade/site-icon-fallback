# Site Icon Fallback

A standalone WordPress plugin. It answers the root icon paths clients probe for — `/apple-touch-icon*.png`, `/favicon.ico`, `/favicon.png` — from the Site Icon already set in Settings, instead of letting them 404.

## The problem it solves

WordPress declares the Site Icon in the page head, but a lot of clients never read that markup. Applebot, Safari's Favourites thumbnailer, Add to Home Screen, Reading List, and the link unfurlers in Slack and iMessage request icons straight from the domain root and get a 404.

Apple documents the root lookup as a *fallback*: declared `<link>` elements win, and the root is searched when none match. Both halves of the plugin follow from that.

## Architecture

Two independent layers. Layer 1 works everywhere unaided; Layer 2 needs requests to reach PHP.

| File | Responsibility |
| --- | --- |
| `site-icon-fallback.php` | Header, `VERSION`, requires, calls `bootstrap()` |
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

**A response that declares no type is sniffed, not refused.** Altis Tachyon answers image requests with a 200, the real bytes and no `Content-Type` header at all, and collapsing "declared nothing" into "declared something refused" cost the byte path on every install behind it — the symptom is a 302 with `X-Site-Icon-Fallback: redirect` where a 200 was expected. `sniff_type()` reads the leading bytes and is consulted **only** when the header is absent: a declared type is the origin's answer and stands whether or not we like it, so nothing here is a second opinion. `TYPE_SIGNATURES` maps only into `ALLOWED_TYPES`, so recognising bytes can name a type and never admit a new one, and the two things the list exists to keep out — an HTML error page and an SVG — carry no image signature and still fall through to a redirect. WebP and AVIF need their own checks because both are containers that name their format after byte zero, so a prefix comparison cannot tell a WebP from a WAV.

**Failed fetches are cached, briefly.** Only successes were cached originally, so a Site Icon on a slow or unreachable host cost a fresh blocking three-second request on *every* probe — and this path exists to serve crawlers arriving in bursts. `FETCH_FAILED` is stored under the same key for `get_failure_cache_lifetime()` (5 minutes), deliberately far below `get_content_max_age()`, since the fallback in the meantime is a working redirect.

**`MAX_ICON_BYTES` applies to both fetch paths.** The local read checks `filesize()` *before* `file_get_contents()`, so an oversized original is never pulled into memory; the HTTP request passes `limit_response_size` as the cap **plus one**, because that truncates rather than errors and a body stopped exactly at the cap would look like one that fits. A Site Icon set with `wp option update site_icon <id>` generates no `site_icon-*` derivatives, so the URL resolves to the full-size upload — this is the common trigger, not a hypothetical one.

**The attachment is read before the network.** `read_local_icon()` decides "is this local?" by testing the URL against the uploads base URL, which answers no on every install behind an image service — Altis rewrites the Site Icon to `/tachyon/…`, so the check fails and the bytes get fetched back over HTTP from the site's own front end. That loopback is the fragile part: it is a three-second blocking request, it fails outright on a local environment whose certificate PHP will not trust, and when it fails the icon falls back to a 302 while the file sat on disk the whole time. `read_attachment_icon()` resolves the `site_icon` option to its file through `get_attached_file()` and the size metadata, which is the same source `get_site_icon_url()` itself starts from. It runs *after* the URL read, so installs where that already worked are untouched, and *before* the HTTP request, which is now only reached when there is no local file at all. The trade-off is deliberate: a `get_site_icon_url` filter pointing at a genuinely different image is no longer honoured on the byte path when the attachment resolves, because the option is what defines the Site Icon. Sizes resolve the way core resolves them — smallest square derivative at least as large, original when none is — so a request for 120 gets the 180 file rather than an exact 120 the image service would have generated.

**HTTP fetches send `Accept: image/png`.** Image services content-negotiate on `Accept` and will return WebP under a `.png` URL to anything that offers it.

**`get_site_icon_url()` does not always return a string.** It hands back whatever `wp_get_attachment_image_url()` returned, which is `false` when the `site_icon` option names an attachment that no longer exists — and nothing reliably clears that option, because the hook that would (`WP_Site_Icon::delete_attachment_data`) is registered only inside one admin AJAX action. A `get_site_icon_url` filter may return anything at all, which is why core's own callers test the result for truthiness rather than comparing it to `''`. Comparing `=== ''` therefore lets `false` past the guard, and passing it into a string parameter under `strict_types` is a fatal on the one code path built for anonymous traffic. `get_icon_url()` normalises it in one place so no caller has to.

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

**The release branch is stripped, and `git archive` is what decides by how much.** `release` is built by hm-github-actions' `build-to-release-branch`, which reverse-applies main's entire diff and reads no ignore file, so left alone the branch is a full mirror of main. The one seam is `build_script`: it runs after main's tree is staged and before the `commit --amend` that publishes it, which makes it the only point where the commit's contents can be narrowed. `.github/strip-dev-files.sh` drops the dev files from the index there. It asks `git archive` what survives rather than re-reading `.gitattributes` through `git check-attr` — an `export-ignore` on a directory pattern such as `/tests` matches the directory entry alone, and check-attr reports nothing for the files inside it, so the obvious implementation silently ships the whole test suite. Archive's traversal is also exactly what GitHub runs to build a tag's source archive, so deferring to it is what stops the branch, the tag archive and the release zip from drifting apart.

**The release zip is `git archive --prefix`, not GitHub's generated source archive.** The generated zipball extracts to a version-named directory, which becomes the plugin's directory name on a manual wp-admin upload and so changes with every release. `--prefix=site-icon-fallback/` pins it. Nothing has to be installed to do this: the release branch is already the distributable tree, so `wp dist-archive` would only re-derive the same answer from a second list. `.distignore` is kept for running that by hand, and stays in step with `.gitattributes`.

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
- `defined( 'ABSPATH' ) || exit;` after the namespace and any `use` imports, in every file. `site-icon-fallback.php` keeps the `if` form the plugin-header convention uses.
- **One concern per file, and the tell is the word "and".** A file whose description needs one is two files. `icon-fetch.php` gets the icon's bytes and `icon-stream.php` emits them; the dependency points one way, and the caller imports both (`Icon_Fetch\fetch_icon()`, then `Icon_Stream\send_icon_bytes()`).
- Length is the symptom, not the rule. Past roughly 200 lines, look for the seam — but don't cut where there isn't one. `icon-fetch.php` is 218 lines of a single concern, and pulling `ALLOWED_TYPES` out would only separate the allow-list from its two callers.
- Comments explain *why*, particularly where a simpler-looking alternative is wrong.
- **Docblocks stay short: summary line, at most three lines of rationale, then the tags.** The *why* still belongs at the code, but a reader after the signature should not have to parse an essay to reach it. Reasoning that needs more room goes in the "Decisions that are load-bearing" section above, with a one-line pointer at the code — `See CLAUDE.md: "Content types are allow-listed too."` One copy of an argument cannot drift from the other.

## Versioning

**Bump the version once per branch, on the first change that ships — then never again on that branch.**

Four places carry it and all four move together:

| Where | Form |
| --- | --- |
| `site-icon-fallback.php:5` | ` * Version:           x.y.z` |
| `site-icon-fallback.php:23` | `const VERSION = 'x.y.z';` |
| `readme.txt:7` | `Stable tag: x.y.z` |
| `readme.txt` changelog | a new `= x.y.z =` heading |

**Whether this branch has bumped already is answered by git, not by memory.** Read the version off `main` and compare:

```
git show main:site-icon-fallback.php | sed -n 's/^ \* Version: *//p'
```

Equal to the working tree means this branch has not bumped yet, so bump now and add the changelog heading. Different means the bump already happened on this branch — add to the existing changelog entry rather than opening a second one, and leave all four numbers alone. This is what keeps a five-commit branch from arriving at 0.1.5. Never bump on `main` itself: branch first, per the global protocol.

**A change that ships nothing gets no bump.** `.gitattributes` already defines what ships, so the question is decidable rather than a judgement call — if every changed path is `export-ignore`d (`tests/`, `.github/`, `AGENTS.md`, `docs/`, tooling config), the distributed plugin is byte-identical and there is no new version to name. Release tooling and test changes are the common case here.

**Do not answer that from `git diff main...HEAD`.** It reports only what is already on the branch as a commit, and a branch under review has modified and untracked work at the same time — the answer it gives is "nothing changed", which reads as "nothing ships" and is wrong in the direction of not bumping. Three sources or none:

```
{ git diff --name-only main...HEAD;             # already on the branch
  git diff --name-only HEAD;                    # modified, not yet recorded
  git ls-files --others --exclude-standard; }   # new, untracked
```

Classify each result with `git check-attr export-ignore`, **walking up the parents too** — `/tests` does not match `tests/foo.php`, the same trap `strip-dev-files.sh` exists for. `npm run check:version` does all of this; run it rather than reimplementing it.

Patch for fixes, minor for new behaviour, major for a break.

**Three gates enforce this, and each catches what the others cannot.**

| Gate | Catches |
| --- | --- |
| `.github/check-version-bump.sh` (PR) | A shipping change that raised nothing, or lowered the version |
| `tests/test-version.php` (PR) | The four locations disagreeing with each other |
| `tag-and-release.yml` (release) | A tag that does not match the `Version:` header |

The first two are the ones that matter, because they fail on a branch rather than at a release. Note what the second cannot see on its own: four locations agreeing on the *old* number is a passing state, so consistency checking alone never notices a bump that did not happen. Together the three pin the tag to all four locations and to a number strictly above `main`, which is why the release workflow needs no four-way check of its own.

## Commands

| Command | What it does |
| --- | --- |
| `composer install` | Installs `humanmade/coding-standards` (the only dependency) |
| `composer phpcs` | Lints `inc/`, `site-icon-fallback.php` and `uninstall.php` against the HM standard via `.phpcs.xml` |
| `composer phpcbf` | Auto-fixes what phpcs can |
| `npm test` | Runs both suites below. CI runs this on every PR via `tests.yml`, unfiltered by path |
| `npm run test:php` | The PHP suites, both plain PHP with no WordPress bootstrap — `test-routing.php` covers routing and streaming, `test-version.php` asserts the four version locations agree |
| `npm run test:sh` | The shell suites — `test-nginx-installer.sh` drives the installer against temp files, `test-strip-dev-files.sh` and `test-version-bump.sh` drive the release strip and the version gate against throwaway repositories |
| `npm run check:version` | Answers "does this branch ship a change that needs a version bump?" — judges the working tree, so it is not part of `npm test` |
| `wp site-icon-fallback status` | The plugin's own check: icon set, server, reachability, serve mode |
| `wp site-icon-fallback nginx-config` | Prints the snippet for this install |
| `npm run env:start` | Boots `@wordpress/env` on port 3031 with Query Monitor |

The coding standard is `humanmade/coding-standards` (HM), not the `WordPress-VIP-Go` set used during development against the host project's `vendor/`.
