#!/usr/bin/env bash
#
# Install the Site Icon Fallback nginx rules into an nginx config file.
#
# The rules are fenced between BEGIN/END markers, mirroring how WordPress manages its own
# .htaccess block. Re-running replaces the fenced block rather than appending a second
# copy, which nginx would reject as a duplicate location.

set -euo pipefail

readonly MARKER_NAME='Site Icon Fallback'
readonly BEGIN_MARKER="# BEGIN ${MARKER_NAME}"
readonly END_MARKER="# END ${MARKER_NAME}"

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
readonly PLUGIN_DIR
readonly SNIPPET_FILE="${PLUGIN_DIR}/nginx.conf.example"

target=''
base='/'
dry_run=0
remove=0

usage() {
	cat <<'USAGE'
Install the Site Icon Fallback nginx rules into an nginx config file.

Usage:
  install-nginx-config.sh                 Install into the detected Altis config
  install-nginx-config.sh --target PATH   Install into a specific file
  install-nginx-config.sh --base PATH     Root the rules at a subdirectory install
  install-nginx-config.sh --dry-run       Show the result, write nothing
  install-nginx-config.sh --remove        Remove the fenced block
  install-nginx-config.sh --help          Show this message

Re-running is safe: the fenced block is replaced, never duplicated.
USAGE
}

fail() {
	printf 'error: %s\n' "$1" >&2
	exit 1
}

# Walk up from the plugin looking for an Altis checkout, identified by a .config
# directory alongside a composer.json.
detect_target() {
	local dir="${PLUGIN_DIR}"

	while [ "${dir}" != '/' ]; do
		if [ -d "${dir}/.config" ] && [ -f "${dir}/composer.json" ]; then
			printf '%s\n' "${dir}/.config/nginx-additions.conf"
			return 0
		fi
		dir="$(dirname "${dir}")"
	done

	return 1
}

# Print a file with our fenced block removed. A file without the block passes through
# unchanged, which is what makes the first install and every later one the same operation.
#
# Markers are matched by containment rather than equality, because nginx config is nested
# and a block pasted inside a server {} block is normally indented. Equality would leave an
# indented block in place and then append a second copy, which nginx rejects as a duplicate
# location. The PHP half (remove_marker_block) matches the same way, so the two removers
# cannot disagree about what is fenced.
strip_block() {
	awk -v begin="${BEGIN_MARKER}" -v end="${END_MARKER}" '
		index( $0, begin ) { skip = 1 }
		!skip              { print }
		index( $0, end )   { skip = 0 }
	' "$1"
}

# Print the snippet, rooted at --base. The bundled file is written for an install at the
# domain root, which is both the common case and what keeps it valid nginx to paste as it
# is. A subdirectory install needs the location patterns and the try_files fallback moved
# together, matching what get_nginx_snippet() does on the PHP side.
render_snippet() {
	if [ "${base}" = '/' ]; then
		cat "${SNIPPET_FILE}"
		return
	fi

	sed -e "s|\^/|^${base}|g" -e "s| /index\.php| ${base}index.php|g" "${SNIPPET_FILE}"
}

# Print the desired contents: any unrelated config, then a freshly fenced block. Command
# substitution drops trailing newlines, so repeated runs cannot accumulate blank lines.
render() {
	local file="$1"
	local existing=''

	if [ -f "${file}" ]; then
		existing="$(strip_block "${file}")"
	fi

	if [ -n "${existing}" ]; then
		printf '%s\n\n' "${existing}"
	fi

	printf '%s\n' "${BEGIN_MARKER}"
	render_snippet
	printf '%s\n' "${END_MARKER}"
}

while [ $# -gt 0 ]; do
	case "$1" in
		--target)
			[ $# -ge 2 ] || fail '--target needs a path'
			target="$2"
			shift 2
			;;
		--base)
			[ $# -ge 2 ] || fail '--base needs a path'
			# Normalised to leading and trailing slashes, so /blog, blog/ and /blog/ are
			# the same argument and the substitution below has one shape to handle.
			trimmed="$(printf '%s' "$2" | sed -e 's|^/*||' -e 's|/*$||')"
			base="/${trimmed:+${trimmed}/}"
			shift 2
			;;
		--dry-run)
			dry_run=1
			shift
			;;
		--remove)
			remove=1
			shift
			;;
		-h|--help)
			usage
			exit 0
			;;
		*)
			fail "unknown argument: $1"
			;;
	esac
done

[ -f "${SNIPPET_FILE}" ] || fail "snippet not found: ${SNIPPET_FILE}"

if [ -z "${target}" ]; then
	target="$(detect_target)" || fail 'could not find an Altis .config directory; pass --target PATH'
fi

if [ -e "${target}" ] && [ ! -w "${target}" ]; then
	fail "not writable: ${target}"
fi

if [ "${remove}" -eq 1 ]; then
	[ -f "${target}" ] || fail "no such file: ${target}"

	if ! grep -qF "${BEGIN_MARKER}" "${target}"; then
		printf 'Nothing to remove: no %s block in %s\n' "${MARKER_NAME}" "${target}"
		exit 0
	fi

	stripped="$(strip_block "${target}")"

	if [ "${dry_run}" -eq 1 ]; then
		printf '# Would write to %s:\n\n%s\n' "${target}" "${stripped}"
		exit 0
	fi

	printf '%s\n' "${stripped}" > "${target}"
	printf 'Removed the %s block from %s\n' "${MARKER_NAME}" "${target}"
	exit 0
fi

rendered="$(render "${target}")"

if [ -f "${target}" ] && [ "${rendered}" = "$(cat "${target}")" ]; then
	printf 'Already up to date: %s\n' "${target}"
	exit 0
fi

if [ "${dry_run}" -eq 1 ]; then
	printf '# Would write to %s:\n\n%s\n' "${target}" "${rendered}"
	exit 0
fi

# Redirect onto the existing path so file ownership and mode survive the write.
printf '%s\n' "${rendered}" > "${target}"

printf 'Installed the %s block into %s\n' "${MARKER_NAME}" "${target}"
printf 'Restart or reload nginx for it to take effect.\n'
