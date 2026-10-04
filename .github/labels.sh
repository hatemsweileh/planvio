#!/usr/bin/env bash
#
# Create (or update) the labels in labels.yml on this repository.
#
#     bash .github/labels.sh                       # the repo the current directory belongs to
#     bash .github/labels.sh hatemsweileh/planvio  # or name one explicitly
#
# Requires the GitHub CLI, signed in:  gh auth login
#
# Safe to re-run. `gh label create --force` updates a label that already exists rather than
# failing, so this converges on the file rather than duplicating anything.
#
# Deliberately a script you can read rather than a third-party syncing Action. Planvio's
# premise is that you host it yourself and can see what runs; handing a token with write
# access to somebody else's Action to save twenty lines of bash is a poor trade.

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")"

if ! command -v gh >/dev/null 2>&1; then
    echo "The GitHub CLI is not installed. See https://cli.github.com" >&2
    exit 1
fi

REPO_ARGS=()
if [ "${1:-}" != "" ]; then
    REPO_ARGS=(--repo "$1")
fi

created=0

# The file is a flat list of `- name:` blocks; three greps are enough and mean this needs
# no YAML parser. Values are quoted in the file only where they must be, so quotes are
# stripped here rather than assumed either way.
strip() { sed -e 's/^[^:]*:[[:space:]]*//' -e 's/^"//' -e 's/"$//'; }

name=""
colour=""
description=""

flush() {
    [ -z "$name" ] && return 0
    printf '  %-22s #%s\n' "$name" "$colour"
    gh label create "$name" \
        --color "$colour" \
        --description "$description" \
        --force \
        "${REPO_ARGS[@]}" >/dev/null
    created=$((created + 1))
    name=""; colour=""; description=""
}

while IFS= read -r line || [ -n "$line" ]; do
    case "$line" in
        '- name:'*)   flush; name=$(printf '%s' "$line" | strip) ;;
        *'colour:'*)  colour=$(printf '%s' "$line" | strip) ;;
        *'description:'*) description=$(printf '%s' "$line" | strip) ;;
    esac
done < labels.yml

flush

echo
echo "${created} labels created or updated."
