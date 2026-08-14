#!/usr/bin/env bash
#
# Exercises .github/strip-dev-files.sh against throwaway git repositories.
#
# The script decides what the release branch, the tag's source archive and the release zip
# each contain, and it only ever runs for real on a push to main — a mistake here surfaces
# to whoever installs the plugin, not to CI. The case worth guarding is directory patterns:
# `/tests export-ignore` prunes the whole directory in git archive but reports nothing
# through git check-attr, so the plausible rewrite ships the test suite while still passing
# any test that only covers file patterns.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
readonly SCRIPT_DIR
readonly STRIP="${SCRIPT_DIR}/.github/strip-dev-files.sh"

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

work="$(mktemp -d)"
trap 'rm -rf "${work}"' EXIT

# Stands in for the tree the release build stages: plugin files that must ship, alongside
# the development files that must not. Nothing is committed, because the script reads the
# index rather than HEAD — which is also why this needs no git identity configured.
make_repo() {
	local dir="${work}/$1"

	mkdir -p "${dir}/inc" "${dir}/bin" "${dir}/tests" "${dir}/.github/workflows"
	printf 'entrypoint\n' > "${dir}/site-icon-fallback.php"
	printf 'runtime\n' > "${dir}/inc/namespace.php"
	printf 'installer\n' > "${dir}/bin/install-nginx-config.sh"
	printf '{}\n' > "${dir}/composer.json"
	printf 'readme\n' > "${dir}/readme.txt"
	printf 'suite\n' > "${dir}/tests/test-routing.php"
	printf 'ci\n' > "${dir}/.github/workflows/php.yml"
	printf 'rules\n' > "${dir}/.phpcs.xml"
	printf 'notes\n' > "${dir}/AGENTS.md"

	git -C "${dir}" init -q
	printf '%s\n' "${dir}"
}

stage() {
	git -C "$1" add -A
}

strip_in() {
	( cd "$1" && bash "${STRIP}" )
}

is_staged() {
	if git -C "$1" ls-files --error-unmatch "$2" > /dev/null 2>&1; then
		printf 'yes'
	else
		printf 'no'
	fi
}

on_disk() {
	if [ -f "$1/$2" ]; then
		printf 'yes'
	else
		printf 'no'
	fi
}

echo "Directory patterns"
# The regression this file exists for. Every assertion here passes under a check-attr
# implementation only if the path is named directly, which none of these are.
repo="$(make_repo dirs)"
{
	printf '/tests export-ignore\n'
	printf '/.github export-ignore\n'
	printf '/.phpcs.xml export-ignore\n'
} > "${repo}/.gitattributes"
stage "${repo}"
strip_in "${repo}" > /dev/null

check 'a file inside an ignored directory is dropped' "$(is_staged "${repo}" tests/test-routing.php)" 'no'
check 'a file nested two levels down is dropped' "$(is_staged "${repo}" .github/workflows/php.yml)" 'no'
check 'a plain file pattern is dropped' "$(is_staged "${repo}" .phpcs.xml)" 'no'

echo
echo "Unlisted files survive"
check 'the entrypoint survives' "$(is_staged "${repo}" site-icon-fallback.php)" 'yes'
check 'runtime code survives' "$(is_staged "${repo}" inc/namespace.php)" 'yes'
check 'bin/ survives' "$(is_staged "${repo}" bin/install-nginx-config.sh)" 'yes'
check 'composer.json survives' "$(is_staged "${repo}" composer.json)" 'yes'
check 'an unlisted dev file survives' "$(is_staged "${repo}" AGENTS.md)" 'yes'

echo
echo "The working tree is untouched"
# The action commits the index, then the next run diffs main against this working tree to
# rebuild it. A script that deleted files from disk would corrupt that comparison.
check 'a stripped file is still on disk' "$(on_disk "${repo}" tests/test-routing.php)" 'yes'
check 'so is a stripped nested file' "$(on_disk "${repo}" .github/workflows/php.yml)" 'yes'

echo
echo "Running twice"
staged_before="$(git -C "${repo}" ls-files | wc -l | tr -d ' ')"
output="$(strip_in "${repo}")"
status=$?
staged_after="$(git -C "${repo}" ls-files | wc -l | tr -d ' ')"
check 'a second run succeeds' "${status}" '0'
check 'and reports nothing left to strip' "$(printf '%s' "${output}" | grep -c 'Nothing to strip')" '1'
check 'and changes nothing' "${staged_after}" "${staged_before}"

echo
echo "A tree with no rules"
repo="$(make_repo norules)"
stage "${repo}"
output="$(strip_in "${repo}")"
status=$?
check 'succeeds with no .gitattributes' "${status}" '0'
check 'and keeps every file' "$(is_staged "${repo}" tests/test-routing.php)" 'yes'
check 'and says so' "$(printf '%s' "${output}" | grep -c 'Nothing to strip')" '1'

echo
echo "A tree that ignores everything"
# Publishing an empty plugin is worse than publishing nothing, so this must fail the build
# rather than strip the tree bare and let the amend commit it.
repo="$(make_repo everything)"
printf '* export-ignore\n' > "${repo}/.gitattributes"
stage "${repo}"
strip_in "${repo}" > /dev/null 2>&1
check 'stripping everything fails loudly' "$?" '1'
check 'and leaves the index alone' "$(is_staged "${repo}" site-icon-fallback.php)" 'yes'

echo
echo "The shipped list"
# Against the real .gitattributes, so the list this plugin actually releases under is
# exercised and not just the mechanism.
repo="$(make_repo shipped)"
cp "${SCRIPT_DIR}/.gitattributes" "${repo}/.gitattributes"
stage "${repo}"
strip_in "${repo}" > /dev/null

check 'the entrypoint ships' "$(is_staged "${repo}" site-icon-fallback.php)" 'yes'
check 'readme.txt ships' "$(is_staged "${repo}" readme.txt)" 'yes'
check 'bin/ ships, because the plugin points users at it' "$(is_staged "${repo}" bin/install-nginx-config.sh)" 'yes'
check 'composer.json ships, because it carries the package type' "$(is_staged "${repo}" composer.json)" 'yes'
check 'the test suite does not ship' "$(is_staged "${repo}" tests/test-routing.php)" 'no'
check 'CI config does not ship' "$(is_staged "${repo}" .github/workflows/php.yml)" 'no'
check 'the coding standard config does not ship' "$(is_staged "${repo}" .phpcs.xml)" 'no'
check 'internal notes do not ship' "$(is_staged "${repo}" AGENTS.md)" 'no'
check '.gitattributes strips itself' "$(is_staged "${repo}" .gitattributes)" 'no'

printf '\n%d passed, %d failed\n' "${pass}" "${fail}"
[ "${fail}" -eq 0 ]
