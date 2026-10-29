#!/usr/bin/env bash
#
# Claude Code hook: run the full test suite (lint + boundary check + PHPUnit)
# after code changes and feed failures back to Claude (exit code 2).
#
#   post-edit  PostToolUse on Edit|Write: runs when a code/config file changed.
#   stop       Stop: runs when the turn ends with uncommitted code changes, so
#              edits made through the shell are covered too.

set -u
mode="${1:-post-edit}"
input="$(cat)"
root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$root" || exit 0

code_files='\.(php|js|css|json|xml|dist)$'

if [ "$mode" = "post-edit" ]; then
	file="$(jq -r '.tool_input.file_path // .tool_response.filePath // empty' <<<"$input")"
	case "$file" in
		"$root"/vendor/* | "$root"/node_modules/* | "$root"/.claude/*) exit 0 ;;
		"$root"/*) ;;
		*) exit 0 ;;
	esac
	grep -qE "$code_files" <<<"$file" || exit 0
else
	# Avoid loops: if this Stop was already blocked once, let Claude stop.
	[ "$(jq -r '.stop_hook_active // false' <<<"$input")" = "true" ] && exit 0
	git status --porcelain --untracked-files=all | awk '{print $NF}' | grep -vE '^(vendor|node_modules)/' | grep -qE "$code_files" || exit 0
fi

if ! docker info >/dev/null 2>&1; then
	echo "Tests not run: Docker is not running. Start Docker Desktop and 'npx wp-env start', then run 'npm test'." >&2
	exit 2
fi

if output="$(npm test --silent 2>&1)"; then
	exit 0
fi

{
	echo "npm test failed after your change. Fix the failures below before continuing (rules: CLAUDE.md)."
	echo
	# Strip ANSI colours and wp-env progress lines; keep the tail where failures are.
	sed -E 's/\x1b\[[0-9;]*m//g' <<<"$output" | grep -vE '^[ℹ✔] (Starting|Ran) ' | tail -80
} >&2
exit 2
