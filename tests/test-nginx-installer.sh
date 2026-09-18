#!/usr/bin/env bash
#
# Exercises bin/install-nginx-config.sh against temporary config files.
#
# The installer's whole contract is that running it twice is the same as running it once:
# nginx rejects a duplicated location block outright, so a failed removal is not a cosmetic
# problem, it is a config that will not boot.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
readonly SCRIPT_DIR
readonly INSTALLER="${SCRIPT_DIR}/bin/install-nginx-config.sh"

# The marker pair the installer matches on, and the only handle it has for finding a block
# it wrote earlier.
readonly BEGIN_MARKER='# BEGIN Site Icon Fallback'
readonly END_MARKER='# END Site Icon Fallback'

pass=0
fail=0

check() {
	local label="$1" actual="$2" expected="$3"

	if [ "${actual}" = "${expected}" ]; then
		pass=$(( pass + 1 ))
		printf '  ok    %s\n' "${label}"
		return
	fi

	fail=$(( fail + 1 ))
	printf '  FAIL  %s\n        expected %s, got %s\n' "${label}" "${expected}" "${actual}"
}

count_markers() {
	grep -cF "${BEGIN_MARKER}" "$1" || true
}

work="$(mktemp -d)"
trap 'rm -rf "${work}"' EXIT

target="${work}/nginx-additions.conf"

# The installer's own chatter is never what is under test, only the file it leaves behind.
install_to_target() {
	"${INSTALLER}" --target "${target}" "$@" > /dev/null
}

# Prints the exit status instead of leaving it in $?, so a failure assertion cannot be broken
# by anything inserted between the run and the check.
installer_status() {
	"${INSTALLER}" "$@" > /dev/null 2>&1
	printf '%d' "$?"
}

echo "Install"
printf 'server_tokens off;\n' > "${target}"
install_to_target
check 'block is written' "$(count_markers "${target}")" '1'
check 'unrelated config survives' "$(grep -c 'server_tokens off;' "${target}")" '1'
check 'the snippet body lands, not just the markers' "$(grep -c 'apple-touch-icon' "${target}")" '1'

echo
echo "Re-install is idempotent"
before="$(cat "${target}")"
install_to_target
check 'still exactly one block' "$(count_markers "${target}")" '1'
check 'file is unchanged' "$(cat "${target}")" "${before}"

echo
echo "Remove"
install_to_target --remove
check 'no block left' "$(count_markers "${target}")" '0'
check 'unrelated config still there' "$(cat "${target}")" 'server_tokens off;'
install_to_target --remove
check 'removing twice is harmless' "$(count_markers "${target}")" '0'

echo
echo "Indented markers"
# nginx config is nested, so a block pasted inside server {} arrives indented. Matching
# markers by equality left it in place and appended a second copy — a duplicate location,
# which stops nginx booting.
{
	printf 'server {\n'
	printf '    %s\n' "${BEGIN_MARKER}"
	printf '    location ~ ^/favicon\\.(ico|png)$ { try_files $uri /index.php?$args; }\n'
	printf '    %s\n' "${END_MARKER}"
	printf '    keep this;\n'
	printf '}\n'
} > "${target}"

install_to_target
check 'indented block replaced, not duplicated' "$(count_markers "${target}")" '1'
check 'surrounding config survives' "$(grep -c 'keep this;' "${target}")" '1'
check 'the old indented rule is gone' "$(grep -c '    location ~ \^/favicon' "${target}")" '0'

install_to_target --remove
check 'indented install removes cleanly' "$(count_markers "${target}")" '0'

echo
echo "Subdirectory base"
# The rules are matched against the path WordPress owns, not the domain root, so a
# subdirectory install needs the location patterns, the try_files fallback and the rewrite
# moved. tests/test-routing.php asserts this output matches what get_nginx_snippet() produces.
printf '' > "${target}"
install_to_target --base blog
check 'locations carry the base' "$(grep -c 'location ~ \^/blog/apple-touch-icon' "${target}")" '1'
check 'fallback points at the install' "$(grep -c 'try_files $uri /blog/index.php' "${target}")" '2'
check 'nothing is left at the domain root' "$(grep -c 'location ~ \^/apple-touch-icon' "${target}")" '0'
check 'the rewrite pattern carries the base' "$(grep -c 'rewrite \^/blog/favicon' "${target}")" '1'
check 'the rewrite target points at the install' "$(grep -cF '/blog/index.php?$args last;' "${target}")" '1'

printf '' > "${target}"
install_to_target --base /blog/
check 'a slashed base is the same argument' "$(grep -c 'location ~ \^/blog/apple-touch-icon' "${target}")" '1'

printf '' > "${target}"
install_to_target --base /
check 'an explicit root base changes nothing' "$(grep -c 'location ~ \^/apple-touch-icon' "${target}")" '1'

check 'a bare --base fails' "$(installer_status --target "${target}" --base)" '1'

echo
echo "Dry run"
printf 'server_tokens off;\n' > "${target}"
output="$( "${INSTALLER}" --target "${target}" --dry-run )"
check 'dry run writes nothing' "$(count_markers "${target}")" '0'
check 'dry run prints the block' "$(printf '%s' "${output}" | grep -cF "${BEGIN_MARKER}")" '1'

echo
echo "Argument handling"
check 'unknown argument fails' "$(installer_status --nonsense)" '1'
check 'help succeeds' "$(installer_status --help)" '0'
check 'removing from a missing file fails' "$(installer_status --target "${work}/missing.conf" --remove)" '1'

printf '\n%d passed, %d failed\n' "${pass}" "${fail}"
[ "${fail}" -eq 0 ]
