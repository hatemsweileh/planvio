#!/usr/bin/env bash
#
# Configure the GitHub repository to match what is checked into .github/.
#
#     bash .github/setup-repo.sh                       # the repo this checkout points at
#     bash .github/setup-repo.sh hatemsweileh/planvio  # or name one
#
# Requires the GitHub CLI, signed in:  gh auth login
#
# Everything here is settings, not content — the things that live in GitHub's database
# rather than in the repository, and so cannot be committed. Keeping them in a script means
# they are at least written down, reviewable, and reproducible on a fork.
#
# Safe to re-run: every step either sets a value to what it should be or is skipped. Nothing
# here deletes anything, changes repository visibility, or pushes code.

set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")"

if ! command -v gh >/dev/null 2>&1; then
    echo "The GitHub CLI is not installed. See https://cli.github.com" >&2
    exit 1
fi

if ! gh auth status >/dev/null 2>&1; then
    echo "Not signed in. Run:  gh auth login" >&2
    exit 1
fi

REPO="${1:-}"
if [ -z "$REPO" ]; then
    REPO=$(gh repo view --json nameWithOwner -q .nameWithOwner 2>/dev/null) || {
        echo "Could not work out which repository. Pass it: bash .github/setup-repo.sh owner/name" >&2
        exit 1
    }
fi

echo "Configuring ${REPO}"
echo

step() { printf '\n-- %s\n' "$1"; }
ok()   { printf '   %s\n' "$1"; }
warn() { printf '   ! %s\n' "$1"; }

# ---------------------------------------------------------------------------
step 'Description, homepage and topics'

gh repo edit "$REPO" \
    --description "Plan the work. Let AI run it. An open-source, self-hosted project management platform for real businesses — installed from your browser, running on ordinary shared hosting. English and Arabic with full RTL." \
    --homepage "https://github.com/${REPO}#readme" \
    >/dev/null && ok 'set' || warn 'could not set description'

# Topics drive GitHub search and the "explore" surfaces; they are the cheapest
# discoverability there is.
gh repo edit "$REPO" \
    --add-topic project-management \
    --add-topic laravel \
    --add-topic livewire \
    --add-topic filament \
    --add-topic php \
    --add-topic self-hosted \
    --add-topic ai-agents \
    --add-topic shared-hosting \
    --add-topic rtl \
    --add-topic arabic \
    --add-topic agpl \
    >/dev/null && ok 'topics added' || warn 'could not add topics'

# ---------------------------------------------------------------------------
step 'Features'

# Discussions and the wiki are the two the repository cannot switch on for itself.
gh repo edit "$REPO" \
    --enable-issues \
    --enable-discussions \
    --enable-wiki \
    --enable-projects=false \
    >/dev/null && ok 'issues, discussions and wiki on' || warn 'could not set features'

# ---------------------------------------------------------------------------
step 'Merge behaviour'

# Squash-only, because the history that matters is on main and a pull request's
# working commits are not it. Deleting the branch on merge keeps the branch list
# meaning "in progress" rather than "everything that ever happened".
gh repo edit "$REPO" \
    --enable-squash-merge \
    --enable-merge-commit=false \
    --enable-rebase-merge=false \
    --enable-auto-merge \
    --delete-branch-on-merge \
    >/dev/null && ok 'squash only, branch deleted on merge' || warn 'could not set merge behaviour'

# ---------------------------------------------------------------------------
step 'Security'

gh api -X PUT "repos/${REPO}/vulnerability-alerts" --silent 2>/dev/null \
    && ok 'Dependabot alerts on' || warn 'could not enable Dependabot alerts'

gh api -X PUT "repos/${REPO}/automated-security-fixes" --silent 2>/dev/null \
    && ok 'Dependabot security updates on' || warn 'could not enable security updates'

# Secret scanning and push protection are free on public repositories. The call fails
# harmlessly on a private one without GitHub Advanced Security.
gh api -X PATCH "repos/${REPO}" \
    -f 'security_and_analysis[secret_scanning][status]=enabled' \
    -f 'security_and_analysis[secret_scanning_push_protection][status]=enabled' \
    --silent 2>/dev/null \
    && ok 'secret scanning and push protection on' \
    || warn 'secret scanning not available (private repo without Advanced Security?)'

# ---------------------------------------------------------------------------
step 'Labels'

bash ./labels.sh "$REPO" | tail -1

# ---------------------------------------------------------------------------
step 'Branch protection on main'

# Only the three non-matrix checks are required by name. The test job's checks are
# named per matrix leg — "Tests · PHP 8.3 · sqlite" and so on — so requiring them by
# string would silently stop matching the moment the matrix changed, which is the
# worst kind of protection: present, and not doing anything.
if gh api -X PUT "repos/${REPO}/branches/main/protection" \
    --input - <<'JSON' --silent 2>/dev/null
{
  "required_status_checks": {
    "strict": true,
    "contexts": ["Code style", "Frontend build", "Translation integrity"]
  },
  "enforce_admins": false,
  "required_pull_request_reviews": null,
  "restrictions": null,
  "allow_force_pushes": false,
  "allow_deletions": false,
  "required_conversation_resolution": true
}
JSON
then
    ok 'main protected; force pushes and deletions blocked'
    ok 'required: Code style, Frontend build, Translation integrity'
else
    warn 'could not set branch protection'
    warn 'needs the repository to be public, or a paid plan if private'
fi

# ---------------------------------------------------------------------------
echo
echo "Done. Two things this cannot do, because neither has an API:"
echo
echo "  1. GitHub Sponsors — Settings > Sponsors. Needs identity and payout"
echo "     verification tied to you. FUNDING.yml is already correct and the"
echo "     button appears once the account is approved."
echo
echo "  2. The wiki's first page. GitHub does not create the wiki repository"
echo "     until one page exists, so create any page in the browser, then:"
echo
echo "       git clone https://github.com/${REPO}.wiki.git ../planvio-wiki"
echo "       cp wiki/*.md ../planvio-wiki/"
echo "       cd ../planvio-wiki && git add -A && git commit -m 'Add wiki' && git push"
echo
