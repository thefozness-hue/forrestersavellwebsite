#!/usr/bin/env bash
#
# deploy.sh — publish the static site to hostm with one command.
#
# Syncs the contents of ./site (the web root) up to your hostm public_html
# using lftp, uploading only the files that changed. It NEVER deletes
# anything on the server, so server-only items (the preview subdomain
# folder, cgi-bin, .well-known, etc.) are always left untouched.
#
#   ./deploy.sh            # sync changed files to the live site
#   ./deploy.sh --dry-run  # show what WOULD upload, change nothing
#
# One-time setup:
#   1. Install lftp:                       brew install lftp
#   2. cp deploy.env.example deploy.env    then edit deploy.env with your
#      hostm login. deploy.env is gitignored — your password is never committed.
#
set -euo pipefail
cd "$(dirname "$0")"

DRY_RUN=0
for arg in "$@"; do
  case "$arg" in
    --dry-run) DRY_RUN=1 ;;
    -h|--help) sed -n '2,20p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "Unknown option: $arg (try --dry-run or --help)"; exit 2 ;;
  esac
done

if [ ! -f deploy.env ]; then
  echo "✗ deploy.env not found."
  echo "  Run:  cp deploy.env.example deploy.env   then edit it with your hostm login."
  exit 1
fi
# shellcheck disable=SC1091
source ./deploy.env

: "${DEPLOY_PROTOCOL:?set DEPLOY_PROTOCOL in deploy.env (sftp or ftps)}"
: "${DEPLOY_HOST:?set DEPLOY_HOST in deploy.env}"
: "${DEPLOY_USER:?set DEPLOY_USER in deploy.env}"
: "${DEPLOY_PASS:?set DEPLOY_PASS in deploy.env}"
REMOTE_DIR="${DEPLOY_REMOTE_DIR:-public_html}"
LOCAL_DIR="${DEPLOY_LOCAL_DIR:-site}"
PORT="${DEPLOY_PORT:-}"

if ! command -v lftp >/dev/null 2>&1; then
  echo "✗ lftp is not installed.  Install it with:  brew install lftp"
  exit 1
fi
if [ ! -d "$LOCAL_DIR" ]; then
  echo "✗ Local web root '$LOCAL_DIR' not found — run this from the repo root."
  exit 1
fi

case "$DEPLOY_PROTOCOL" in
  sftp) SCHEME="sftp"
        PROTO_SETTINGS="set sftp:auto-confirm yes;" ;;
  ftps) SCHEME="ftp"
        PROTO_SETTINGS="set ftp:ssl-force true; set ftp:ssl-protect-data true; set ssl:verify-certificate no;" ;;
  *)    echo "✗ DEPLOY_PROTOCOL must be 'sftp' or 'ftps' (got '$DEPLOY_PROTOCOL')"; exit 1 ;;
esac

MIRROR="mirror --reverse --continue --no-perms --verbose --parallel=4 --exclude-glob .DS_Store"
[ "$DRY_RUN" = "1" ] && MIRROR="$MIRROR --dry-run"

PORT_ARG=""
[ -n "$PORT" ] && PORT_ARG="-p $PORT"

echo "→ Deploying '$LOCAL_DIR/'  ➜  $DEPLOY_HOST:$REMOTE_DIR   (${DEPLOY_PROTOCOL})"
[ "$DRY_RUN" = "1" ] && echo "  (dry run — nothing will actually be uploaded)"

# Password is passed via the environment (LFTP_PASSWORD), never on the
# command line, so special characters in it are safe.
export LFTP_PASSWORD="$DEPLOY_PASS"

lftp <<LFTP
set cmd:fail-exit yes;
$PROTO_SETTINGS
open $PORT_ARG -u "$DEPLOY_USER" --env-password $SCHEME://$DEPLOY_HOST
$MIRROR "$LOCAL_DIR/" "$REMOTE_DIR/"
bye
LFTP

echo "✓ Done."
