#!/bin/sh
# Offsite ZFS snapshot replication via Syncoid.
# Reads /etc/tc-backup.env; exits cleanly when no offsite target is configured.
set -eu

CONFIG_FILE="${TC_BACKUP_ENV:-/etc/tc-backup.env}"
if [ ! -f "$CONFIG_FILE" ]; then
    echo "syncoid-backup: ${CONFIG_FILE} not configured; skipping offsite replication."
    exit 0
fi

set -a
# shellcheck disable=SC1090
. "$CONFIG_FILE"
set +a

TC_POOL="${TC_POOL:-zroot}"
SYNCOID_TARGET_HOST="${SYNCOID_TARGET_HOST:-}"
SYNCOID_TARGET_USER="${SYNCOID_TARGET_USER:-zfsbackup}"
SYNCOID_TARGET_PORT="${SYNCOID_TARGET_PORT:-22}"
SYNCOID_SSH_KEY="${SYNCOID_SSH_KEY:-/root/.ssh/tc-syncoid-ed25519}"
SYNCOID_TARGET_DATASET="${SYNCOID_TARGET_DATASET:-}"

if [ -z "$SYNCOID_TARGET_HOST" ] || [ -z "$SYNCOID_TARGET_DATASET" ]; then
    echo "syncoid-backup: SYNCOID_TARGET_HOST or SYNCOID_TARGET_DATASET is empty; skipping." >&2
    exit 1
fi

LOCK_FILE="/var/run/tc-syncoid.lock"
if [ -z "${TC_SYNCOID_LOCKED:-}" ]; then
    export TC_SYNCOID_LOCKED=1
    exec /usr/bin/lockf -t 0 "$LOCK_FILE" "$0" "$@"
fi

SSH_OPTS="-p ${SYNCOID_SSH_PORT} -o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=15"
if [ -f "$SYNCOID_SSH_KEY" ]; then
    SSH_OPTS="${SSH_OPTS} -i ${SYNCOID_SSH_KEY}"
fi

replicate() {
    src="$1"
    dst="${SYNCOID_TARGET_USER}@${SYNCOID_TARGET_HOST}:${SYNCOID_TARGET_DATASET}/$2"
    if ! zfs list -H "$src" >/dev/null 2>&1; then
        return 0
    fi
    /usr/local/bin/syncoid \
        --no-sync-snap \
        --recursive \
        --no-privilege-elevation \
        --sshoption="-p" --sshoption="${SYNCOID_TARGET_PORT}" \
        --sshoption="-o" --sshoption="BatchMode=yes" \
        --sshoption="-o" --sshoption="StrictHostKeyChecking=yes" \
        --sshoption="-o" --sshoption="ConnectTimeout=15" \
        ${SYNCOID_SSH_KEY:+--sshkey="$SYNCOID_SSH_KEY"} \
        "$src" "$dst"
}

replicate "${TC_POOL}/tc/services/mariadb" "mariadb"
replicate "${TC_POOL}/tc/services/panel" "panel"
replicate "${TC_POOL}/tc/apps" "apps"
