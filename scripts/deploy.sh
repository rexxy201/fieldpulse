#!/bin/bash
#
# Pull-and-deploy for cPanel Git Version Control.
#
# cPanel does not deploy on push — a GitHub push triggers nothing at all. This
# script is what turns "git push" into a live deploy: run it from cron on the
# cPanel account and it fetches the tracked branch, fast-forwards, and asks
# cPanel to run the tasks in .cpanel.yml.
#
# Install on the server (paths are for the mangonetcom account — adjust REPO
# if the clone lives elsewhere):
#
#   mkdir -p ~/bin ~/logs
#   cp scripts/deploy.sh ~/bin/deploy-fieldpulse.sh
#   chmod 700 ~/bin/deploy-fieldpulse.sh
#   ~/bin/deploy-fieldpulse.sh          # run once by hand before trusting cron
#
# Then add a cron job (cPanel → Cron Jobs), every 5 minutes:
#
#   */5 * * * * /home/mangonetcom/bin/deploy-fieldpulse.sh >> /home/mangonetcom/logs/deploy.log 2>&1
#
# It is deliberately silent when there is nothing new, so a log line always
# means an actual deploy (or an actual problem).

set -u

REPO="${REPO:-/home/mangonetcom/repositories/fieldpulse}"
BRANCH="${BRANCH:-main}"
GIT=/usr/bin/git
UAPI=/usr/local/cpanel/bin/uapi

log() { echo "$(date '+%Y-%m-%d %H:%M:%S') $*"; }

cd "$REPO" || { log "ERROR: repository not found at $REPO"; exit 1; }

if ! $GIT fetch origin "$BRANCH" --quiet; then
    # Almost always the SSH deploy key: wrong host alias in the clone URL, or
    # the key is not on this repo. Check with: ssh -T git@github-fieldpulse
    log "ERROR: fetch failed — check the deploy key and the remote URL"
    exit 1
fi

LOCAL=$($GIT rev-parse @)
REMOTE=$($GIT rev-parse "origin/$BRANCH")
[ "$LOCAL" = "$REMOTE" ] && exit 0

# --ff-only on purpose. If the branch has diverged — someone edited files
# directly on the server, or history was rewritten — this fails loudly rather
# than silently merging over the top of it. Investigate; do not "fix" it by
# relaxing this flag.
if ! $GIT merge --ff-only "origin/$BRANCH" --quiet; then
    log "ERROR: cannot fast-forward $BRANCH — the checkout has diverged, resolve by hand"
    exit 1
fi

if ! $UAPI VersionControlDeployment create repository_root="$REPO"; then
    log "ERROR: cPanel deployment failed — repo is now at $($GIT rev-parse --short @) but the live site is NOT updated"
    exit 1
fi

log "deployed $($GIT rev-parse --short @) ($($GIT log -1 --pretty=%s))"
