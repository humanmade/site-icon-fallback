#!/usr/bin/env bash
#
# Exercises .github/check-version-bump.sh against throwaway git repositories.
#
# The cases that matter are the uncommitted and untracked ones. `git diff base...HEAD` alone
# reports neither, and a check written that way passes a branch whose shipping changes are
# simply not committed yet — which is the state a branch spends most of its life in.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
readonly SCRIPT_DIR
readonly CHECK="${SCRIPT_DIR}/.github/check-version-bump.sh"

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

git_q() {
	git -C "$1" -c user.email=test@example.com -c user.name=Test "${@:2}"
}

plugin_file() {
	printf ' * Version:           %s\n' "$1"
}

# A repository with main at 0.1.0 and a branch checked out, which is where a change lands.
make_repo() {
	local dir="${work}/$1"

	mkdir -p "${dir}/inc" "${dir}/tests" "${dir}/.github"
	plugin_file '0.1.0' > "${dir}/site-icon-fallback.php"
	printf 'runtime\n' > "${dir}/inc/namespace.php"
	printf 'suite\n' > "${dir}/tests/test-routing.php"
	{
		printf '/tests export-ignore\n'
		printf '/.github export-ignore\n'
		printf '/AGENTS.md export-ignore\n'
		printf '/.gitattributes export-ignore\n'
	} > "${dir}/.gitattributes"
	printf 'notes\n' > "${dir}/AGENTS.md"

	git_q "${dir}" init -q
	git_q "${dir}" checkout -q -b main
	git_q "${dir}" add -A
	git_q "${dir}" commit -q -m 'initial'
	git_q "${dir}" checkout -q -b feature

	printf '%s\n' "${dir}"
}

set_version() {
	plugin_file "$2" > "$1/site-icon-fallback.php"
}

run_check() {
	( cd "$1" && bash "${CHECK}" main > /dev/null 2>&1 )
}

echo "A shipping change without a bump"
repo="$(make_repo unbumped)"
printf 'changed\n' >> "${repo}/inc/namespace.php"
git_q "${repo}" add -A
git_q "${repo}" commit -q -m 'change runtime'
run_check "${repo}"
check 'is rejected' "$?" '1'

echo
echo "The same change with a bump"
set_version "${repo}" '0.1.1'
git_q "${repo}" add -A
git_q "${repo}" commit -q -m 'bump'
run_check "${repo}"
check 'is accepted' "$?" '0'

echo
echo "A branch that ships nothing"
repo="$(make_repo ignored)"
printf 'more\n' >> "${repo}/tests/test-routing.php"
printf 'more\n' >> "${repo}/AGENTS.md"
git_q "${repo}" add -A
git_q "${repo}" commit -q -m 'tests and notes only'
run_check "${repo}"
check 'needs no bump' "$?" '0'

echo
echo "Uncommitted work counts"
# The bug this file exists for: nothing is committed, so a base...HEAD diff sees an empty
# branch and reports success.
repo="$(make_repo uncommitted)"
printf 'changed\n' >> "${repo}/inc/namespace.php"
run_check "${repo}"
check 'a modified shipping file is rejected' "$?" '1'

set_version "${repo}" '0.1.1'
run_check "${repo}"
check 'and accepted once bumped, still uncommitted' "$?" '0'

echo
echo "Untracked work counts"
repo="$(make_repo untracked)"
printf 'new module\n' > "${repo}/inc/new-thing.php"
run_check "${repo}"
check 'a new shipping file is rejected' "$?" '1'

repo="$(make_repo untracked_ignored)"
printf 'new test\n' > "${repo}/tests/test-new.php"
run_check "${repo}"
check 'a new ignored file is not' "$?" '0'

echo
echo "The version must go up, not merely differ"
repo="$(make_repo backwards)"
printf 'changed\n' >> "${repo}/inc/namespace.php"
set_version "${repo}" '0.0.9'
run_check "${repo}"
check 'a lowered version is rejected' "$?" '1'

set_version "${repo}" '0.2.0'
run_check "${repo}"
check 'a minor bump is accepted' "$?" '0'

set_version "${repo}" '1.0.0'
run_check "${repo}"
check 'a major bump is accepted' "$?" '0'

echo
echo "Escape hatch"
repo="$(make_repo hatch)"
printf 'changed\n' >> "${repo}/inc/namespace.php"
( cd "${repo}" && ALLOW_UNVERSIONED=1 bash "${CHECK}" main > /dev/null 2>&1 )
check 'ALLOW_UNVERSIONED=1 passes a shipping change' "$?" '0'

echo
echo "An untouched branch"
repo="$(make_repo clean)"
run_check "${repo}"
check 'passes with nothing changed' "$?" '0'

echo
echo "A missing base"
repo="$(make_repo nobase)"
( cd "${repo}" && bash "${CHECK}" nonexistent > /dev/null 2>&1 )
check 'is an error, not a pass' "$?" '1'

printf '\n%d passed, %d failed\n' "${pass}" "${fail}"
[ "${fail}" -eq 0 ]
