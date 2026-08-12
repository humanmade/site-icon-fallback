# Site Icon Fallback

Set a Site Icon in Settings → General. Get `/apple-touch-icon.png`, `/favicon.ico` and `/favicon.png` answered from it, instead of 404.

WordPress declares the Site Icon in the page head, but many clients never read that markup. Applebot, Safari's Favourites thumbnailer, Add to Home Screen, Reading List, and the link unfurlers in Slack and iMessage request icons straight from the domain root. This plugin answers them.

Nothing is hardcoded and no files are copied into your web root. Change the Site Icon and the root paths follow.

## What it does

**Declares the sized icon tags.** Core emits one `apple-touch-icon` link with no `sizes` attribute, so a client after a specific size has no exact match to pick. This plugin considers 120, 152, 167 and 180, and declares each size backed by a genuinely different image. Works on any host with no configuration.

**Answers the root paths.** Requests for `/apple-touch-icon*.png`, `/favicon.ico` and `/favicon.png` return the icon at the size asked for — a 200 with the image bytes, an `ETag`, and a day-long `Cache-Control`. It serves bytes rather than redirecting, because nothing guarantees an icon fetcher follows a redirect. This half needs those requests to reach PHP.

Sizes answered: 57, 60, 72, 76, 114, 120, 144, 152, 167, 180, 192. Anything else is refused, so the endpoint cannot be driven as an image-resize service.

## Requirements

- WordPress 6.0 or later, PHP 8.0 or later.
- A Site Icon set in **Settings → General**. Without one, the root paths return 404 and an admin notice says so.
- **nginx.** The plugin refuses to activate on a server it cannot identify as nginx.
- Requests for the root paths must reach PHP. A standard nginx `try_files` configuration already does this.

## Install

Put the plugin in `wp-content/plugins/site-icon-fallback` and activate it. It writes no files and no options — activating and deactivating changes nothing on disk or in your database.

Activation stops with an error if the server does not report itself as nginx. WordPress decides that from `$_SERVER['SERVER_SOFTWARE']`, which is not always right: nginx proxying to Apache reports Apache. If you are on nginx and the check disagrees, return `false` from `site_icon_fallback_require_nginx` in an mu-plugin.

Then open **Tools → Site Health**. The check named *Root icon requests reach WordPress* tells you whether the root paths are reaching PHP, and prints the nginx rules to add if they are not.

## nginx

Some tuned nginx configurations answer static paths themselves, so the requests never reach PHP. The rules in `nginx.conf.example` fix that. On Altis, `bin/install-nginx-config.sh` installs them for you:

```sh
./bin/install-nginx-config.sh                 # install into .config/nginx-additions.conf
./bin/install-nginx-config.sh --target PATH   # install into a specific file
./bin/install-nginx-config.sh --base blog     # subdirectory install
./bin/install-nginx-config.sh --dry-run       # show the result, write nothing
./bin/install-nginx-config.sh --remove        # take the block back out
```

The block is fenced between `# BEGIN Site Icon Fallback` and `# END Site Icon Fallback`. Re-running replaces it rather than appending a second copy — nginx rejects duplicate `location` directives. Reload nginx afterwards.

One limitation: where a host declares `location = /favicon.ico`, that exact match beats every regex and cannot be overridden. `/favicon.png` still works.

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

The three short lifetimes are deliberately far below the content one. Each points at something a Site Icon change invalidates, so caching them hard leaves clients replaying a stale answer.

## Development

```sh
composer install     # phpcs and the Human Made coding standards
composer phpcs       # lint inc/, plugin.php and uninstall.php
npm test             # both test suites
npm run env:start    # wp-env on port 3031
```

The tests need no WordPress bootstrap, no database and no PHPUnit — `tests/test-routing.php` stubs what it needs and runs in milliseconds. `tests/test-nginx-installer.sh` drives the installer against temporary files.

`AGENTS.md` documents the architecture and the reasoning behind each load-bearing decision. Read it before changing anything in `inc/` — most of what looks like a simpler alternative is one that was tried. `readme.txt` carries the user-facing FAQ.

## Uninstalling

Deleting the plugin clears its cached icon bytes. That is all it stores — there are no options to clean up. nginx rules are never removed automatically, so run `bin/install-nginx-config.sh --remove` yourself.

## License

GPL-2.0-or-later.
