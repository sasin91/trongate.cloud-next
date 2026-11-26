#!/bin/sh
# Configures Sanoid local ZFS snapshot schedules and Syncoid offsite replication
# scaffolding on a bare-metal FreeBSD trongate.cloud host.
set -eu

TC_POOL="${TC_POOL:-zroot}"
TC_SKIP_PKG="${TC_SKIP_PKG:-0}"

SCRIPT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
INFRA_DIR="$(CDPATH= cd -- "$SCRIPT_DIR/.." && pwd)"
CONFIG_DIR="$INFRA_DIR/config"

die() {
    printf 'ERROR: %s\n' "$*" >&2
    exit 1
}

info() {
    printf '==> %s\n' "$*"
}

if [ "$(uname -s)" != "FreeBSD" ]; then
    die "Run backups.sh on FreeBSD."
fi
if [ "$(id -u)" -ne 0 ]; then
    die "Run backups.sh as root."
fi
if ! zfs list -H "$TC_POOL/tc" >/dev/null 2>&1; then
    die "Dataset $TC_POOL/tc not found. Run bootstrap.sh first."
fi

if [ "$TC_SKIP_PKG" != "1" ]; then
    info "Installing sanoid (includes syncoid)"
    ASSUME_ALWAYS_YES=yes pkg install -y sanoid
fi

info "Installing /usr/local/etc/sanoid/sanoid.conf (TC_POOL=${TC_POOL})"
install -d -o root -g wheel -m 0755 /usr/local/etc/sanoid /etc/cron.d /usr/local/libexec/tc
sed "s|@TC_POOL@|${TC_POOL}|g" "$CONFIG_DIR/sanoid.conf.in" > /usr/local/etc/sanoid/sanoid.conf
chown root:wheel /usr/local/etc/sanoid/sanoid.conf
chmod 0644 /usr/local/etc/sanoid/sanoid.conf

# Ensure optional custom services dataset exists before first Sanoid pass.
if ! zfs list -H "$TC_POOL/tc/services/custom" >/dev/null 2>&1; then
    zfs create -p -o atime=off -o compression=lz4 "$TC_POOL/tc/services/custom"
fi

info "Installing /etc/cron.d/tc-sanoid (runs sanoid --cron every 15 minutes)"
cat <<'EOF' > /etc/cron.d/tc-sanoid
SHELL=/bin/sh
PATH=/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/sbin:/usr/local/bin
*/15 * * * * root /usr/local/bin/sanoid --cron >/dev/null 2>&1
EOF
chown root:wheel /etc/cron.d/tc-sanoid
chmod 0644 /etc/cron.d/tc-sanoid

info "Installing Syncoid offsite replication helper and sample config"
install -o root -g wheel -m 0755 "$SCRIPT_DIR/syncoid-backup.sh" /usr/local/libexec/tc/syncoid-backup.sh

if [ ! -f /etc/tc-backup.env.sample ]; then
    (
        umask 077
        cat <<EOF > /etc/tc-backup.env.sample
# Copy to /etc/tc-backup.env (mode 0600) to enable offsite Syncoid ZFS replication.
# Then schedule /usr/local/libexec/tc/syncoid-backup.sh via /etc/cron.d/tc-syncoid.
TC_POOL="${TC_POOL}"
SYNCOID_TARGET_HOST="backup.example.ts.net"
SYNCOID_TARGET_USER="zfsbackup"
SYNCOID_TARGET_PORT="22"
SYNCOID_SSH_KEY="/root/.ssh/tc-syncoid-ed25519"
SYNCOID_TARGET_DATASET="backup/tc-host1"
EOF
    )
    chown root:wheel /etc/tc-backup.env.sample
    chmod 0600 /etc/tc-backup.env.sample
fi

info "Taking initial Sanoid snapshots"
/usr/local/bin/sanoid --take-snapshots --verbose
/usr/local/bin/sanoid --prune-snapshots

info "Active ZFS snapshots under ${TC_POOL}/tc and zroot/ROOT/default:"
zfs list -t snapshot -o name,used,refer,creation -r "$TC_POOL/tc/services/mariadb" "$TC_POOL/tc/services/panel" "$TC_POOL/tc/apps" "$TC_POOL/tc/services/custom" "zroot/ROOT/default"
