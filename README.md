# Site Icon Fallback

Set a Site Icon in **Settings → General**. This plugin then answers `/apple-touch-icon.png`, `/favicon.ico` and `/favicon.png` from it, instead of a 404. Nothing is hardcoded and no files are copied into your web root. Change the Site Icon and the root paths follow.

WordPress declares the Site Icon in the page head, but a lot of clients never read that markup. Applebot, Safari's Favourites thumbnailer, Add to Home Screen, Reading List, and the link unfurlers in Slack and iMessage ask the domain root directly. Apple documents that root lookup as a fallback for when no declared `<link>` matches, so the plugin covers both halves.

## What it does

**Declares the sized icon tags.** Core emits one `apple-touch-icon` link with no `sizes` attribute. A client after a specific size has no exact match to pick. The plugin considers 120, 152, 167 and 180, then declares each size backed by a genuinely different image. This half works on any host with no configuration.

**Answers the root paths.** A request for `/apple-touch-icon*.png`, `/favicon.ico` or `/favicon.png` gets a 200 with the image bytes, an `ETag` and a day-long `Cache-Control`. I serve bytes rather than a redirect, because nothing guarantees an icon fetcher follows one. This half needs the request to reach PHP.

## Requirements

- WordPress 6.7 or later, and PHP 8.2 or later.
- A Site Icon set in **Settings → General**. Without one the root paths return 404, and an admin notice tells you.
- nginx. The plugin refuses to activate on a server that reports itself as anything else.
- Root path requests must reach PHP. A standard nginx `try_files` configuration already sends them there.

## Installing it

1. Download the zip attached to the [latest release](https://github.com/humanmade/Site-icon-fallback/releases), which extracts to `site-icon-fallback/`.
2. Put that directory in `wp-content/plugins/`.
3. Activate the plugin in wp-admin, or run `wp plugin activate site-icon-fallback`.
4. Set a Site Icon in **Settings → General**, if you have not already.
5. Confirm both halves work, which the next section covers.

The plugin writes no files and stores no options. Activating and deactivating it changes nothing on disk or in your database.

## Checking that it works

`wp site-icon-fallback status` answers the two questions that decide it: is a Site Icon set, and do root requests reach WordPress. In a browser, open **Tools → Site Health** and look for *Root icon requests reach WordPress*.

To check the response itself:

```sh
curl -I https://your-site/apple-touch-icon.png
```

A 200 carrying `X-Site-Icon-Fallback: stream` came from the plugin. A 404 with no such header means the request never reached PHP, which the next section fixes.

## When root requests do not reach PHP

Some tuned nginx configurations answer static paths themselves. The rules in `nginx.conf.example` hand those paths to WordPress, and on Altis the bundled script installs them for you:

```sh
./bin/install-nginx-config.sh                 # install into the Altis .config/nginx-additions.conf
./bin/install-nginx-config.sh --target PATH   # install into a specific file
./bin/install-nginx-config.sh --base blog     # root the rules at a subdirectory install
./bin/install-nginx-config.sh --dry-run       # print the result, write nothing
./bin/install-nginx-config.sh --remove        # take the block back out
```

Reload nginx afterwards, which locally usually means restarting the container. The block sits between `# BEGIN Site Icon Fallback` and `# END Site Icon Fallback`. Re-running the script replaces that block rather than appending a second copy, because nginx rejects duplicate `location` directives.

Two things to know before you paste the rules in:

- Your config needs `root` declared at server level. The `try_files` fallback and the `favicon.ico` rewrite both resolve a path against whatever `root` is in scope. Declare it only inside individual locations, and a real file at the web root stops being found.
- On a remote host without the script, run `wp site-icon-fallback nginx-config` instead. It prints the same rules, rooted at this install's home path.

Hosts that pin `/favicon.ico` with `location = /favicon.ico` are handled. nginx resolves an exact match before any regex, so the snippet reaches that path with a server-level `rewrite` instead. A rewrite runs before a location is selected at all. A real `favicon.ico` at the web root still wins over both.

## When activation is refused

Activation stops with an error when the server reports itself as something other than nginx. WordPress reads that from `$_SERVER['SERVER_SOFTWARE']`, which is not always right: nginx proxying to Apache reports Apache. If you are on nginx and the check disagrees, return `false` from `site_icon_fallback_require_nginx` in an mu-plugin.

Activating with WP-CLI always works. A CLI run has no web server to ask, so `SERVER_SOFTWARE` is never set, and the check warns instead of blocking. Your deploys will not get stuck on this.

## WP-CLI commands

```sh
wp site-icon-fallback status            # can the plugin actually serve icons here?
wp site-icon-fallback status --fresh    # re-test instead of reading the cached result
wp site-icon-fallback status --strict   # exit non-zero when a check fails
wp site-icon-fallback nginx-config      # print the nginx rules for this install
```

`status` reports what Site Health reports, somewhere a deploy script can read it. It takes `--format=table|json|csv|yaml`.

One caveat before you wire `--strict` into CI. The reachability check is a loopback request to your home URL, so it fails whenever the machine running `wp` cannot reach the site's public address. That is common in containers. Confirm `wp site-icon-fallback status` agrees with `curl -I https://your-site/favicon.ico` before trusting it as a gate.

## Sizes it answers

`/apple-touch-icon-152x152.png` and the rest of the `-precomposed` variants resolve to the size in the filename. These sizes are answered:

57, 60, 72, 76, 114, 120, 144, 152, 167, 180, 192

Anything else is refused, so the endpoint cannot be driven as an image-resize service. A bare `/apple-touch-icon.png` serves 180, and so do `/favicon.ico` and `/favicon.png`.

## Filters

| Filter | Default | What it changes |
| --- | --- | --- |
| `site_icon_fallback_require_nginx` | `true` | `false` allows activation on any server |
| `site_icon_fallback_serve_mode` | `stream` | `redirect` sends a 302 instead of the bytes |
| `site_icon_fallback_declared_sizes` | `[120, 152, 167, 180]` | Sizes considered for the page head |
| `site_icon_fallback_content_max_age` | `DAY_IN_SECONDS` | How long clients may cache the icon |
| `site_icon_fallback_redirect_max_age` | 5 minutes | How long a redirect may be cached |
| `site_icon_fallback_missing_max_age` | 5 minutes | How long a 404 may be cached |
| `site_icon_fallback_failure_cache_lifetime` | 5 minutes | How long a failed fetch is remembered server-side |

Keep the three short lifetimes well below the content one. Each points at something a Site Icon change invalidates, so caching them hard leaves clients replaying a stale answer.

## Developing on it

```sh
composer install       # phpcs and the Human Made coding standards
composer phpcs         # lint inc/, site-icon-fallback.php and uninstall.php
composer phpcbf        # fix what phpcs can fix on its own
npm test               # two PHP suites and three shell suites
npm run check:version  # does this branch ship a change that needs a version bump?
npm run env:start      # wp-env on port 3031
```

The tests need no WordPress bootstrap, no database and no PHPUnit. `tests/test-routing.php` stubs what it needs and runs in milliseconds. The shell suites drive the nginx installer, the release strip and the version gate against temporary files.

`AGENTS.md`, symlinked as `CLAUDE.md`, records the architecture and the reasoning behind every load-bearing decision. Read it before you change anything in `inc/`, because most of what looks like a simpler alternative is one that was tried. Neither file ships in the distributed plugin. `readme.txt` carries the user-facing FAQ.

## Uninstalling

Deleting the plugin clears its cached icon bytes, which is all it stores. There are no options to clean up. nginx rules are never removed for you, so run `bin/install-nginx-config.sh --remove` yourself.

## Licence

GPL-2.0-or-later.
