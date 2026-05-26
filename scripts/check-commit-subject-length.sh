#!/usr/bin/env bash
#
# Reject commit messages whose subject (first line) exceeds 72 chars.
#
# Aligns the local commit-msg gate with the 72-char convention
# documented in STATE.md's Conventions section, which itself follows
# git's own subject-line guidance.
#
# Invocation: pre-commit's commit-msg stage passes the path of the
# commit message file as the first argument.

set -euo pipefail

msg_file="${1:?commit message file path required}"
subject="$(head -n 1 "$msg_file")"
len="${#subject}"
max=72

if [ "$len" -gt "$max" ]; then
    echo "Error: commit subject is ${len} chars (max ${max})." >&2
    echo "Subject: ${subject}" >&2
    exit 1
fi
