#!/usr/bin/env bash
#
# Strip development files from the release branch commit.
#
# Runs as the build step of humanmade/hm-github-actions' build-to-release-branch action,
# which places it between the reverse-applied merge — that leaves the index holding all of
# main's tree — and the `git commit --amend` that publishes it. Dropping paths from the
# index here keeps them out of the release commit without touching the working tree, and
# the next run re-applies main in full before this script runs again, so it is idempotent.

set -euo pipefail

# What to strip is decided by `git archive`, not by re-reading .gitattributes with
# check-attr. An export-ignore on a directory pattern such as `/tests` matches the
# directory entry alone, so check-attr reports nothing for the files inside it; only
# archive's tree traversal prunes the directory. That traversal is also what GitHub runs
# to build a tag's source archive, so deferring to it is what makes the release branch
# hold exactly what `composer require` downloads.

# The merge has staged main's tree but not committed it, so HEAD is still the previous
# release commit. write-tree turns the index into a tree object archive can read.
tree="$( git write-tree )"

keep="$( mktemp )"
tracked="$( mktemp )"
strip="$( mktemp )"
trap 'rm -f "${keep}" "${tracked}" "${strip}"' EXIT

# Directory entries carry a trailing slash and are not index paths, so they cannot match
# a git ls-files line and would otherwise be counted as strippable.
git archive --format=tar "${tree}" | tar -tf - | grep -v '/$' | LC_ALL=C sort > "${keep}"
git ls-files | LC_ALL=C sort > "${tracked}"
comm -23 "${tracked}" "${keep}" > "${strip}"

if [ ! -s "${strip}" ]; then
	echo 'Nothing to strip: the tree already matches its archive.'
	exit 0
fi

# xargs -0 rather than -d '\n', which is a GNU extension and fails when this is run
# locally on macOS.
tr '\n' '\0' < "${strip}" | xargs -0 git rm --cached --quiet --

printf 'Stripped %d development file(s) from the release branch commit:\n' "$( wc -l < "${strip}" | tr -d ' ' )"
sed 's/^/  /' "${strip}"
