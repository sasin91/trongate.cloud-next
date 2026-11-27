#!/bin/sh
# Provisions the shared platform jails (tc_mariadb, tc_valkey, tc_panel),
# host reboot hook (tc_apps), and the host Caddy base config.
set -eu

TC_POOL="${TC_POOL:-zroot}"
BASE_RELEASE="${BASE_RELEASE:-$(freebsd-version -u | sed 's/-p[0-9]*$//')}"
PHP_RUNTIME="${PHP_RUNTIME:-php84}"
PANEL_DOMAIN="${PANEL_DOMAIN:-}"
PANEL_SRC_DIR="${PANEL_SRC_DIR:-}"
REGISTRY_DOMAIN="${REGISTRY_DOMAIN:-}"
REGISTRY_ARCHIVE_BASE_URL="${REGISTRY_ARCHIVE_BASE_URL:-}"
EMBEDDINGS_URL="${EMBEDDINGS_URL:-}"

MARIADB_MEMORY_MB="${MARIADB_MEMORY_MB:-4096}"
VALKEY_MEMORY_MB="${VALKEY_MEMORY_MB:-512}"
PANEL_MEMORY_MB="${PANEL_MEMORY_MB:-512}"
REGISTRY_MEMORY_MB="${REGISTRY_MEMORY_MB:-256}"

SCRIPT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
INFRA_DIR="$(CDPATH= cd -- "$SCRIPT_DIR/.." && pwd)"
CONFIG_DIR="$INFRA_DIR/config"

BASE_DIR="/$TC_POOL/tc/bases/$BASE_RELEASE"
SKEL_DS="$TC_POOL/tc/skeleton"
PHP_DIR="/$TC_POOL/tc/runtimes/$PHP_RUNTIME"

die() {
    printf 'ERROR: %s\n' "$*" >&2
    exit 1
}

if [ -z "$PANEL_DOMAIN" ]; then
    die "Set PANEL_DOMAIN to the public HTTPS panel hostname."
fi

case "$PANEL_DOMAIN" in
    https://*)
        PANEL_BASE_URL="$PANEL_DOMAIN"
        ;;
    http://*)
        die "PANEL_DOMAIN must use HTTPS."
        ;;
    *)
        PANEL_BASE_URL="https://$PANEL_DOMAIN"
        ;;
esac

info() {
    printf '==> %s\n' "$*"
}

warn() {
    printf 'WARNING: %s\n' "$*" >&2
}

if [ "$(uname -s)" != "FreeBSD" ]; then
    die "This script must run on FreeBSD."
fi
if [ "$(id -u)" -ne 0 ]; then
    die "Run platform-jails.sh as root."
fi
if ! zfs list -H -t snapshot "$TC_POOL/tc/bases/$BASE_RELEASE@base" >/dev/null 2>&1; then
    die "Base snapshot $TC_POOL/tc/bases/$BASE_RELEASE@base missing. Run infra/scripts/build-runtime.sh first."
fi
if ! zfs list -H -t snapshot "$SKEL_DS@v1" >/dev/null 2>&1; then
    die "Skeleton snapshot $SKEL_DS@v1 missing. Run infra/scripts/build-runtime.sh first."
fi
if ! zfs list -H -t snapshot "$TC_POOL/tc/runtimes/$PHP_RUNTIME@v1" >/dev/null 2>&1; then
    die "PHP runtime snapshot $TC_POOL/tc/runtimes/$PHP_RUNTIME@v1 missing. Run infra/scripts/build-runtime.sh first."
fi

# Read memory reserves from /etc/tc-resources.json when present and not explicitly overridden.
if [ -f /etc/tc-resources.json ] && command -v php >/dev/null 2>&1; then
    CFG_DB="$(php -r '$c = json_decode(file_get_contents("/etc/tc-resources.json"), true); echo (int) ($c["database_memory_mb"] ?? 0);')"
    CFG_VK="$(php -r '$c = json_decode(file_get_contents("/etc/tc-resources.json"), true); echo (int) ($c["valkey_memory_mb"] ?? 0);')"
    if [ "$CFG_DB" -gt 0 ] && [ "${MARIADB_MEMORY_MB}" = "4096" ]; then
        MARIADB_MEMORY_MB="$CFG_DB"
    fi
    if [ "$CFG_VK" -gt 0 ] && [ "${VALKEY_MEMORY_MB}" = "512" ]; then
        VALKEY_MEMORY_MB="$CFG_VK"
    fi
fi

# Grant a platform service UID write access to a root:wheel 0755 socket dir via ZFS NFSv4 ACL
# so app jails (www) cannot unlink sockets while FreeBSD connect(2) and service bind(2) both work.
grant_socket_dir_uid() {
    dir="$1"
    uid="$2"
    chown root:wheel "$dir"
    chmod 0755 "$dir"
    if ! setfacl -m "u:${uid}:rwxpDd::allow" "$dir" 2>/dev/null; then
        warn "NFSv4 ACL unsupported on $dir; setting owner to ${uid}:wheel (mode 0755)"
        chown "${uid}:wheel" "$dir"
        chmod 0755 "$dir"
    fi
}

gen_secret() {
    openssl rand -hex 24
}

PORTS_REPO="$(pkg -vv | awk '/^[[:space:]]*FreeBSD-ports[[:space:]]*:/ { print "FreeBSD-ports"; exit } /^[[:space:]]*FreeBSD[[:space:]]*:/ { print "FreeBSD"; exit }')"
PORTS_REPO="${PORTS_REPO:-FreeBSD}"

# ---------------------------------------------------------------------------
# 1. Shared MariaDB Platform Jail (tc_mariadb)
# ---------------------------------------------------------------------------
info "Provisioning tc_mariadb platform jail (${MARIADB_MEMORY_MB} MiB limit)"
MARIADB_JAIL_DS="$TC_POOL/tc/jails/tc_mariadb"
MARIADB_JAIL_DIR="/$MARIADB_JAIL_DS"
MARIADB_DATA_DIR="/$TC_POOL/tc/services/mariadb"

if ! zfs list -H "$MARIADB_JAIL_DS" >/dev/null 2>&1; then
    zfs clone "$SKEL_DS@v1" "$MARIADB_JAIL_DS"
fi
mkdir -p "$MARIADB_JAIL_DIR/var/db/mysql" "$MARIADB_JAIL_DIR/var/run/tc/mariadb"

cat <<EOF > /etc/fstab.tc_mariadb
$BASE_DIR        $MARIADB_JAIL_DIR/.base           nullfs ro 0 0
$MARIADB_DATA_DIR $MARIADB_JAIL_DIR/var/db/mysql   nullfs rw 0 0
/var/run/tc/mariadb $MARIADB_JAIL_DIR/var/run/tc/mariadb nullfs rw 0 0
EOF

if [ "$MARIADB_MEMORY_MB" -lt 768 ]; then
    MARIADB_MEMORY_MB=768
fi
# FreeBSD rctl swapuse accounts for all anonymous memory (RAM + swap); keep >= memoryuse.
MARIADB_SWAP_MB="$MARIADB_MEMORY_MB"
INNODB_POOL_MB="$((MARIADB_MEMORY_MB * 40 / 100))"
if [ "$INNODB_POOL_MB" -lt 128 ]; then
    INNODB_POOL_MB=128
fi

cat <<EOF > /etc/jail.conf.d/tc_mariadb.conf
tc_mariadb {
    path = "$MARIADB_JAIL_DIR";
    host.hostname = "tc-mariadb.internal";
    ip4 = disable;
    ip6 = disable;
    mount.devfs;
    devfs_ruleset = 4;
    mount.fstab = "/etc/fstab.tc_mariadb";
    exec.clean;
    exec.start = "/sbin/ldconfig /lib /usr/lib /usr/local/lib && /bin/sh /etc/rc";
    exec.stop = "/bin/sh /etc/rc.shutdown";
    exec.poststart = "if [ \$(sysctl -n kern.racct.enable 2>/dev/null || echo 0) = 1 ]; then rctl -a jail:tc_mariadb:memoryuse:deny=${MARIADB_MEMORY_MB}M; rctl -a jail:tc_mariadb:swapuse:deny=${MARIADB_SWAP_MB}M; rctl -a jail:tc_mariadb:pcpu:deny=200; rctl -a jail:tc_mariadb:maxproc:deny=512; rctl -a jail:tc_mariadb:openfiles:deny=8192; fi";
    exec.poststop = "if [ \$(sysctl -n kern.racct.enable 2>/dev/null || echo 0) = 1 ]; then rctl -r jail:tc_mariadb 2>/dev/null || true; fi";
}
EOF

if [ ! -x "$MARIADB_JAIL_DIR/usr/local/libexec/mariadbd" ]; then
    info "Installing mariadb114-server into tc_mariadb ($PORTS_REPO)"
    mount -t nullfs -o ro "$BASE_DIR" "$MARIADB_JAIL_DIR/.base"
    mount -t devfs devfs "$MARIADB_JAIL_DIR/dev"
    ASSUME_ALWAYS_YES=yes pkg -r "$MARIADB_JAIL_DIR" update -r "$PORTS_REPO"
    ASSUME_ALWAYS_YES=yes pkg -r "$MARIADB_JAIL_DIR" install -r "$PORTS_REPO" -y mariadb114-server mariadb114-client
    umount "$MARIADB_JAIL_DIR/dev"
    umount "$MARIADB_JAIL_DIR/.base"
fi

MYSQL_UID="$(awk -F: '$1 == "mysql" { print $3 }' "$MARIADB_JAIL_DIR/etc/passwd")"
MYSQL_UID="${MYSQL_UID:-88}"
grant_socket_dir_uid /var/run/tc/mariadb "$MYSQL_UID"
chown -R "${MYSQL_UID}:${MYSQL_UID}" "$MARIADB_DATA_DIR"

mkdir -p "$MARIADB_JAIL_DIR/usr/local/etc/mysql/conf.d"
sed "s|@INNODB_POOL_MB@|${INNODB_POOL_MB}|g" "$CONFIG_DIR/mariadb.cnf.in" \
    > "$MARIADB_JAIL_DIR/usr/local/etc/mysql/conf.d/tc-server.cnf"

sysrc -f "$MARIADB_JAIL_DIR/etc/rc.conf" \
    mysql_enable="YES" \
    mysql_dbdir="/var/db/mysql" \
    mysql_args="--socket=/var/run/tc/mariadb/mysqld.sock" >/dev/null

if ! jls -j tc_mariadb >/dev/null 2>&1; then
    service jail onestart tc_mariadb
else
    jexec tc_mariadb service mysql-server restart
fi

i=0
while [ ! -S /var/run/tc/mariadb/mysqld.sock ] && [ "$i" -lt 30 ]; do
    sleep 1
    i=$((i + 1))
done
[ -S /var/run/tc/mariadb/mysqld.sock ] || die "MariaDB socket did not appear at /var/run/tc/mariadb/mysqld.sock"

if [ ! -f /var/db/tc/runtime/panel-db.env ]; then
    PANEL_DB_PASS="$(gen_secret)"
    jexec tc_mariadb mariadb --socket=/var/run/tc/mariadb/mysqld.sock -u root <<SQL
CREATE DATABASE IF NOT EXISTS \`tc_panel\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'tc_panel'@'localhost' IDENTIFIED BY '${PANEL_DB_PASS}';
ALTER USER 'tc_panel'@'localhost' IDENTIFIED BY '${PANEL_DB_PASS}' WITH MAX_USER_CONNECTIONS 20;
GRANT ALL PRIVILEGES ON \`tc_panel\`.* TO 'tc_panel'@'localhost';
FLUSH PRIVILEGES;
SQL
    (
        umask 077
        cat <<EOF > /var/db/tc/runtime/panel-db.env
TC_DSN="mysql:unix_socket=/var/run/tc/mariadb/mysqld.sock;dbname=tc_panel;charset=utf8mb4"
TC_DB_USER="tc_panel"
TC_DB_PASSWORD="${PANEL_DB_PASS}"
EOF
    )
    chown root:wheel /var/db/tc/runtime/panel-db.env
    chmod 0600 /var/db/tc/runtime/panel-db.env
    info "Created tc_panel database and saved credentials to /var/db/tc/runtime/panel-db.env"
fi

# ---------------------------------------------------------------------------
# 2. Shared Valkey Platform Jail (tc_valkey — sessions only)
# ---------------------------------------------------------------------------
info "Provisioning tc_valkey platform jail (${VALKEY_MEMORY_MB} MiB limit)"
VALKEY_JAIL_DS="$TC_POOL/tc/jails/tc_valkey"
VALKEY_JAIL_DIR="/$VALKEY_JAIL_DS"
VALKEY_DATA_DIR="/$TC_POOL/tc/services/valkey"

if ! zfs list -H "$VALKEY_JAIL_DS" >/dev/null 2>&1; then
    zfs clone "$SKEL_DS@v1" "$VALKEY_JAIL_DS"
fi
mkdir -p "$VALKEY_JAIL_DIR/var/db/valkey" "$VALKEY_JAIL_DIR/var/run/tc/valkey" "$VALKEY_JAIL_DIR/var/run/valkey"

cat <<EOF > /etc/fstab.tc_valkey
$BASE_DIR        $VALKEY_JAIL_DIR/.base            nullfs ro 0 0
$VALKEY_DATA_DIR $VALKEY_JAIL_DIR/var/db/valkey    nullfs rw 0 0
/var/run/tc/valkey $VALKEY_JAIL_DIR/var/run/tc/valkey nullfs rw 0 0
EOF

VALKEY_SWAP_MB="$VALKEY_MEMORY_MB"
VALKEY_MAXMEM_MB="$((VALKEY_MEMORY_MB * 80 / 100))"
if [ "$VALKEY_MAXMEM_MB" -lt 64 ]; then
    VALKEY_MAXMEM_MB=64
fi

cat <<EOF > /etc/jail.conf.d/tc_valkey.conf
tc_valkey {
    path = "$VALKEY_JAIL_DIR";
    host.hostname = "tc-valkey.internal";
    ip4 = disable;
    ip6 = disable;
    mount.devfs;
    devfs_ruleset = 4;
    mount.fstab = "/etc/fstab.tc_valkey";
    exec.clean;
    exec.start = "/sbin/ldconfig /lib /usr/lib /usr/local/lib && /bin/sh /etc/rc";
    exec.stop = "/bin/sh /etc/rc.shutdown";
    exec.poststart = "if [ \$(sysctl -n kern.racct.enable 2>/dev/null || echo 0) = 1 ]; then rctl -a jail:tc_valkey:memoryuse:deny=${VALKEY_MEMORY_MB}M; rctl -a jail:tc_valkey:swapuse:deny=${VALKEY_SWAP_MB}M; rctl -a jail:tc_valkey:pcpu:deny=100; rctl -a jail:tc_valkey:maxproc:deny=64; rctl -a jail:tc_valkey:openfiles:deny=4096; fi";
    exec.poststop = "if [ \$(sysctl -n kern.racct.enable 2>/dev/null || echo 0) = 1 ]; then rctl -r jail:tc_valkey 2>/dev/null || true; fi";
}
EOF

if [ ! -x "$VALKEY_JAIL_DIR/usr/local/bin/valkey-server" ]; then
    info "Installing valkey into tc_valkey ($PORTS_REPO)"
    mount -t nullfs -o ro "$BASE_DIR" "$VALKEY_JAIL_DIR/.base"
    mount -t devfs devfs "$VALKEY_JAIL_DIR/dev"
    ASSUME_ALWAYS_YES=yes pkg -r "$VALKEY_JAIL_DIR" update -r "$PORTS_REPO"
    ASSUME_ALWAYS_YES=yes pkg -r "$VALKEY_JAIL_DIR" install -r "$PORTS_REPO" -y valkey
    umount "$VALKEY_JAIL_DIR/dev"
    umount "$VALKEY_JAIL_DIR/.base"
fi

VALKEY_UID="$(awk -F: '$1 == "valkey" { print $3 }' "$VALKEY_JAIL_DIR/etc/passwd")"
VALKEY_UID="${VALKEY_UID:-935}"
grant_socket_dir_uid /var/run/tc/valkey "$VALKEY_UID"
chown -R "${VALKEY_UID}:${VALKEY_UID}" "$VALKEY_DATA_DIR" "$VALKEY_JAIL_DIR/var/run/valkey"

if [ ! -f /var/db/tc/runtime/valkey-admin.pass ]; then
    VALKEY_ADMIN_PASS="$(gen_secret)"
    (
        umask 077
        printf '%s\n' "$VALKEY_ADMIN_PASS" > /var/db/tc/runtime/valkey-admin.pass
    )
    chown root:wheel /var/db/tc/runtime/valkey-admin.pass
    chmod 0600 /var/db/tc/runtime/valkey-admin.pass
else
    VALKEY_ADMIN_PASS="$(cat /var/db/tc/runtime/valkey-admin.pass)"
fi

if [ ! -f "$VALKEY_DATA_DIR/users.acl" ]; then
    (
        umask 077
        cat <<EOF > "$VALKEY_DATA_DIR/users.acl"
user default off
user tc_admin on >${VALKEY_ADMIN_PASS} ~* &* +@all
EOF
    )
    chown "${VALKEY_UID}:${VALKEY_UID}" "$VALKEY_DATA_DIR/users.acl"
    chmod 0600 "$VALKEY_DATA_DIR/users.acl"
fi

sed "s|@VALKEY_MAXMEM_MB@|${VALKEY_MAXMEM_MB}|g" "$CONFIG_DIR/valkey.conf.in" \
    > "$VALKEY_JAIL_DIR/usr/local/etc/valkey.conf"

sysrc -f "$VALKEY_JAIL_DIR/etc/rc.conf" valkey_enable="YES" >/dev/null

if ! jls -j tc_valkey >/dev/null 2>&1; then
    service jail onestart tc_valkey
else
    jexec tc_valkey service valkey restart
fi

i=0
while [ ! -S /var/run/tc/valkey/valkey.sock ] && [ "$i" -lt 20 ]; do
    sleep 1
    i=$((i + 1))
done
[ -S /var/run/tc/valkey/valkey.sock ] || die "Valkey socket did not appear at /var/run/tc/valkey/valkey.sock"

# ---------------------------------------------------------------------------
# 3. Control-Plane Panel Jail (tc_panel on lo1 10.99.0.2)
# ---------------------------------------------------------------------------
info "Provisioning tc_panel jail (10.99.0.2, ${PANEL_MEMORY_MB} MiB limit)"
PANEL_JAIL_DS="$TC_POOL/tc/jails/tc_panel"
PANEL_JAIL_DIR="/$PANEL_JAIL_DS"
PANEL_STATE_DS="$TC_POOL/tc/services/panel"
PANEL_STATE_DIR="/$PANEL_STATE_DS"
PANEL_SOCK_DIR="/var/run/tc/sockets/tc_panel"

if ! zfs list -H "$PANEL_JAIL_DS" >/dev/null 2>&1; then
    zfs clone "$SKEL_DS@v1" "$PANEL_JAIL_DS"
fi
if ! zfs list -H "$PANEL_STATE_DS" >/dev/null 2>&1; then
    zfs create -o atime=off -o compression=lz4 "$PANEL_STATE_DS"
fi

CADDY_GID="$(pw groupshow tc-caddy | cut -d: -f3)"
install -d -o www -g "$CADDY_GID" -m 0750 "$PANEL_SOCK_DIR"
mkdir -p "$PANEL_JAIL_DIR/var/db/tc/panel" "$PANEL_JAIL_DIR/app" "$PANEL_JAIL_DIR/etc/rc.d" "$PANEL_JAIL_DIR/etc/tc"
chmod 0755 "$PANEL_JAIL_DIR/var/db" "$PANEL_JAIL_DIR/var/db/tc" "$PANEL_JAIL_DIR/app" "$PANEL_JAIL_DIR/etc/rc.d" "$PANEL_JAIL_DIR/etc/tc"
chown 80:80 "$PANEL_STATE_DIR"
chmod 0700 "$PANEL_STATE_DIR"

if [ ! -e "$PANEL_STATE_DIR/key" ]; then
    (umask 077; openssl rand -base64 32 > "$PANEL_STATE_DIR/key")
    chown 80:80 "$PANEL_STATE_DIR/key"
    chmod 0600 "$PANEL_STATE_DIR/key"
elif [ ! -s "$PANEL_STATE_DIR/key" ]; then
    die "Panel key file exists but is empty; refusing to replace it"
fi

if ! grep -q '^tc-caddy:' "$PANEL_JAIL_DIR/etc/group"; then
    printf 'tc-caddy:*:%s:\n' "$CADDY_GID" >> "$PANEL_JAIL_DIR/etc/group"
fi

if [ -n "$PANEL_SRC_DIR" ] && [ -d "$PANEL_SRC_DIR" ]; then
    info "Syncing panel source from $PANEL_SRC_DIR into $PANEL_JAIL_DIR/app"
    tar -cf - -C "$PANEL_SRC_DIR" --exclude='.git' --exclude='var' --exclude='tests' . | tar -xf - -C "$PANEL_JAIL_DIR/app"
fi

# Copy panel DB credentials into tc_panel state directory (mode 0600, owned by www).
cp /var/db/tc/runtime/panel-db.env "$PANEL_STATE_DIR/db.env"
chown 80:80 "$PANEL_STATE_DIR/db.env"
chmod 0600 "$PANEL_STATE_DIR/db.env"

cat <<EOF > "$PANEL_JAIL_DIR/etc/tc/php-fpm.conf"
[global]
pid = /var/run/php-fpm/php-fpm.pid
error_log = /var/log/php-fpm.log
daemonize = yes

[tc_panel]
user = www
group = www
listen = /var/run/php-fpm/php-fpm.sock
listen.owner = www
listen.group = tc-caddy
listen.mode = 0660
pm = ondemand
pm.max_children = 10
pm.process_idle_timeout = 30s
pm.max_requests = 500
clear_env = yes
env[PATH] = /usr/local/bin:/usr/bin:/bin
env[PHP_INI_SCAN_DIR] = /usr/local/etc/php-base:/etc/tc/php.d
env[TC_STATE] = /var/db/tc/panel
env[TC_ENV] = prod
EOF

printf 'env[TC_BASE_URL] = "%s"\n' "$PANEL_BASE_URL" >> "$PANEL_JAIL_DIR/etc/tc/php-fpm.conf"

while IFS='=' read -r k v; do
    [ -n "$k" ] || continue
    v="${v#\"}"
    v="${v%\"}"
    printf 'env[%s] = "%s"\n' "$k" "$v" >> "$PANEL_JAIL_DIR/etc/tc/php-fpm.conf"
done < /var/db/tc/runtime/panel-db.env

if [ -f "$PANEL_STATE_DIR/github.env" ]; then
    while IFS='=' read -r k v; do
        case "$k" in
            ''|'#'*) continue ;;
        esac
        v="${v#\"}"
        v="${v%\"}"
        printf 'env[%s] = "%s"\n' "$k" "$v" >> "$PANEL_JAIL_DIR/etc/tc/php-fpm.conf"
    done < "$PANEL_STATE_DIR/github.env"
fi
chmod 0600 "$PANEL_JAIL_DIR/etc/tc/php-fpm.conf"

install -o root -g wheel -m 0755 "$CONFIG_DIR/tc_panel_fpm.rc" "$PANEL_JAIL_DIR/etc/rc.d/tc_panel_fpm"
install -o root -g wheel -m 0755 "$CONFIG_DIR/tc_worker.rc" "$PANEL_JAIL_DIR/etc/rc.d/tc_worker"
install -o root -g wheel -m 0755 "$CONFIG_DIR/tc_monitor.rc" "$PANEL_JAIL_DIR/etc/rc.d/tc_monitor"

sysrc -f "$PANEL_JAIL_DIR/etc/rc.conf" \
    tc_panel_fpm_enable="YES" \
    tc_worker_enable="YES" \
    tc_monitor_enable="YES" >/dev/null

cat <<EOF > /etc/fstab.tc_panel
$BASE_DIR           $PANEL_JAIL_DIR/.base             nullfs ro 0 0
$PHP_DIR            $PANEL_JAIL_DIR/usr/local         nullfs ro 0 0
$PANEL_STATE_DIR    $PANEL_JAIL_DIR/var/db/tc/panel   nullfs rw 0 0
/var/run/tc/mariadb $PANEL_JAIL_DIR/var/run/tc/mariadb nullfs rw 0 0
/var/run/tc/valkey  $PANEL_JAIL_DIR/var/run/tc/valkey  nullfs rw 0 0
$PANEL_SOCK_DIR     $PANEL_JAIL_DIR/var/run/php-fpm   nullfs rw 0 0
EOF

PANEL_SWAP_MB="$PANEL_MEMORY_MB"
cat <<EOF > /etc/jail.conf.d/tc_panel.conf
tc_panel {
    path = "$PANEL_JAIL_DIR";
    host.hostname = "tc-panel.internal";
    interface = "lo1";
    ip4.addr = "10.99.0.2/32";
    mount.devfs;
    devfs_ruleset = 4;
    mount.fstab = "/etc/fstab.tc_panel";
    exec.clean;
    exec.start = "/sbin/ldconfig /lib /usr/lib /usr/local/lib && /bin/sh /etc/rc";
    exec.stop = "/bin/sh /etc/rc.shutdown";
    exec.poststart = "if [ \$(sysctl -n kern.racct.enable 2>/dev/null || echo 0) = 1 ]; then rctl -a jail:tc_panel:memoryuse:deny=${PANEL_MEMORY_MB}M; rctl -a jail:tc_panel:swapuse:deny=${PANEL_SWAP_MB}M; rctl -a jail:tc_panel:pcpu:deny=100; rctl -a jail:tc_panel:maxproc:deny=128; rctl -a jail:tc_panel:openfiles:deny=2048; fi; pfctl -t tc_panel -T add 10.99.0.2 2>/dev/null || true";
    exec.poststop = "if [ \$(sysctl -n kern.racct.enable 2>/dev/null || echo 0) = 1 ]; then rctl -r jail:tc_panel 2>/dev/null || true; fi";
}
EOF

if ! jls -j tc_panel >/dev/null 2>&1; then
    service jail onestart tc_panel
else
    jexec tc_panel service tc_panel_fpm restart
    jexec tc_panel service tc_worker restart
    jexec tc_panel service tc_monitor restart
fi

# ---------------------------------------------------------------------------
# 3b. ZFS Module Registry & Marketplace Jail (tc_registry on lo1 10.99.0.3)
# ---------------------------------------------------------------------------
JAIL_LIST="tc_mariadb tc_valkey tc_panel"
if [ -n "$REGISTRY_DOMAIN" ]; then
    info "Provisioning tc_registry jail (10.99.0.3, ${REGISTRY_MEMORY_MB} MiB limit) for ${REGISTRY_DOMAIN}"
    REG_JAIL_DS="$TC_POOL/tc/jails/tc_registry"
    REG_JAIL_DIR="/$REG_JAIL_DS"
    REG_SOCK_DIR="/var/run/tc/sockets/tc_registry"
    REG_RUNTIME_DIR="/$TC_POOL/tc/runtimes/builder"
    if [ ! -d "$REG_RUNTIME_DIR" ]; then
        REG_RUNTIME_DIR="$PHP_DIR"
    fi

    if ! zfs list -H "$REG_JAIL_DS" >/dev/null 2>&1; then
        zfs clone "$SKEL_DS@v1" "$REG_JAIL_DS"
    fi

    install -d -o www -g "$CADDY_GID" -m 0750 "$REG_SOCK_DIR"
    mkdir -p \
        "$REG_JAIL_DIR/app" \
        "$REG_JAIL_DIR/etc/rc.d" \
        "$REG_JAIL_DIR/etc/tc" \
        "$REG_JAIL_DIR/var/db/tc/panel" \
        "$REG_JAIL_DIR/var/db/tc/registry/repos" \
        "$REG_JAIL_DIR/var/db/tc/registry/modules" \
        "$REG_JAIL_DIR/var/db/tc/registry/archives"
    chmod 0755 "$REG_JAIL_DIR/app" "$REG_JAIL_DIR/etc/rc.d" "$REG_JAIL_DIR/etc/tc" "$REG_JAIL_DIR/var/db/tc" "$REG_JAIL_DIR/var/db/tc/registry"

    if ! grep -q '^tc-caddy:' "$REG_JAIL_DIR/etc/group"; then
        printf 'tc-caddy:*:%s:\n' "$CADDY_GID" >> "$REG_JAIL_DIR/etc/group"
    fi

    if [ -n "$PANEL_SRC_DIR" ] && [ -d "$PANEL_SRC_DIR" ]; then
        info "Syncing registry marketplace source from $PANEL_SRC_DIR into $REG_JAIL_DIR/app"
        tar -cf - -C "$PANEL_SRC_DIR" --exclude='.git' --exclude='var' --exclude='tests' . | tar -xf - -C "$REG_JAIL_DIR/app"
    fi

    case "$REGISTRY_DOMAIN" in
        http://*|https://*)
            REGISTRY_BASE_URL="$REGISTRY_DOMAIN"
            ;;
        *)
            REGISTRY_BASE_URL="https://$REGISTRY_DOMAIN"
            ;;
    esac

    cat <<EOF > "$REG_JAIL_DIR/etc/tc/php-fpm.conf"
[global]
pid = /var/run/php-fpm/php-fpm.pid
error_log = /var/log/php-fpm.log
daemonize = yes

[tc_registry]
user = www
group = www
listen = /var/run/php-fpm/php-fpm.sock
listen.owner = www
listen.group = tc-caddy
listen.mode = 0660
pm = ondemand
pm.max_children = 12
pm.process_idle_timeout = 30s
pm.max_requests = 500
clear_env = yes
env[PATH] = /usr/local/bin:/usr/bin:/bin
env[PHP_INI_SCAN_DIR] = /usr/local/etc/php-base:/etc/tc/php.d
env[TC_STATE] = /var/db/tc/panel
env[TC_REGISTRY_MODE] = "1"
env[TC_REGISTRY_ROOT] = "/var/db/tc/registry"
env[TC_BASE_URL] = "${REGISTRY_BASE_URL}"
env[TC_REGISTRY_URL] = "${REGISTRY_BASE_URL}"
EOF

    if [ -n "$PANEL_DOMAIN" ]; then
        printf 'env[TC_PANEL_URL] = "%s"\n' "$PANEL_BASE_URL" >> "$REG_JAIL_DIR/etc/tc/php-fpm.conf"
    fi
    if [ -n "$REGISTRY_ARCHIVE_BASE_URL" ]; then
        printf 'env[TC_REGISTRY_ARCHIVE_BASE_URL] = "%s"\n' "$REGISTRY_ARCHIVE_BASE_URL" >> "$REG_JAIL_DIR/etc/tc/php-fpm.conf"
    fi
    if [ -n "$EMBEDDINGS_URL" ]; then
        printf 'env[EMBEDDINGS_URL] = "%s"\n' "$EMBEDDINGS_URL" >> "$REG_JAIL_DIR/etc/tc/php-fpm.conf"
    fi

    while IFS='=' read -r k v; do
        [ -n "$k" ] || continue
        v="${v#\"}"
        v="${v%\"}"
        printf 'env[%s] = "%s"\n' "$k" "$v" >> "$REG_JAIL_DIR/etc/tc/php-fpm.conf"
    done < /var/db/tc/runtime/panel-db.env
    chmod 0600 "$REG_JAIL_DIR/etc/tc/php-fpm.conf"

    install -o root -g wheel -m 0755 "$CONFIG_DIR/tc_registry_fpm.rc" "$REG_JAIL_DIR/etc/rc.d/tc_registry_fpm"
    sysrc -f "$REG_JAIL_DIR/etc/rc.conf" tc_registry_fpm_enable="YES" >/dev/null

    cat <<EOF > /etc/fstab.tc_registry
$BASE_DIR                   $REG_JAIL_DIR/.base                     nullfs ro 0 0
$REG_RUNTIME_DIR            $REG_JAIL_DIR/usr/local                 nullfs ro 0 0
$PANEL_STATE_DIR            $REG_JAIL_DIR/var/db/tc/panel           nullfs ro 0 0
/$TC_POOL/tc/registry/repos $REG_JAIL_DIR/var/db/tc/registry/repos  nullfs rw 0 0
/$TC_POOL/tc/registry/modules $REG_JAIL_DIR/var/db/tc/registry/modules nullfs ro 0 0
/$TC_POOL/tc/registry/archives $REG_JAIL_DIR/var/db/tc/registry/archives nullfs ro 0 0
/var/run/tc/mariadb         $REG_JAIL_DIR/var/run/tc/mariadb        nullfs rw 0 0
/var/run/tc/valkey          $REG_JAIL_DIR/var/run/tc/valkey         nullfs rw 0 0
$REG_SOCK_DIR               $REG_JAIL_DIR/var/run/php-fpm           nullfs rw 0 0
EOF

    REG_SWAP_MB="$REGISTRY_MEMORY_MB"
    cat <<EOF > /etc/jail.conf.d/tc_registry.conf
tc_registry {
    path = "$REG_JAIL_DIR";
    host.hostname = "tc-registry.internal";
    interface = "lo1";
    ip4.addr = "10.99.0.3/32";
    mount.devfs;
    devfs_ruleset = 4;
    mount.fstab = "/etc/fstab.tc_registry";
    allow.raw_sockets = 0;
    allow.mount = 0;
    allow.chflags = 0;
    enforce_statfs = 2;
    exec.clean;
    exec.start = "/sbin/ldconfig /lib /usr/lib /usr/local/lib && /bin/sh /etc/rc";
    exec.stop = "/bin/sh /etc/rc.shutdown";
    exec.poststart = "if [ \$(sysctl -n kern.racct.enable 2>/dev/null || echo 0) = 1 ]; then rctl -a jail:tc_registry:memoryuse:deny=${REGISTRY_MEMORY_MB}M; rctl -a jail:tc_registry:swapuse:deny=${REG_SWAP_MB}M; rctl -a jail:tc_registry:pcpu:deny=100; rctl -a jail:tc_registry:maxproc:deny=128; rctl -a jail:tc_registry:openfiles:deny=2048; fi; pfctl -t tc_registry -T add 10.99.0.3 2>/dev/null || true";
    exec.poststop = "if [ \$(sysctl -n kern.racct.enable 2>/dev/null || echo 0) = 1 ]; then rctl -r jail:tc_registry 2>/dev/null || true; fi";
}
EOF

    if ! jls -j tc_registry >/dev/null 2>&1; then
        service jail onestart tc_registry
    else
        jexec tc_registry service tc_registry_fpm restart
    fi

    info "Adding registry marketplace route for $REGISTRY_DOMAIN (immutable archives served from ZFS ARC)"
    cat <<EOF > /var/db/tc/runtime/routes/01-tc_registry.caddy
${REGISTRY_DOMAIN} {
    encode zstd gzip

    handle_path /archives/* {
        root * /${TC_POOL}/tc/registry/archives
        header Cache-Control "public, max-age=31536000, immutable"
        header X-Content-Type-Options "nosniff"
        file_server
    }

    handle {
        root * ${REG_JAIL_DIR}/app/public
        php_fastcgi unix//var/run/tc/sockets/tc_registry/php-fpm.sock {
            root /app/public
        }
        file_server
    }
}
EOF
    chmod 0644 /var/db/tc/runtime/routes/01-tc_registry.caddy
    JAIL_LIST="tc_mariadb tc_valkey tc_panel tc_registry"
fi

# ---------------------------------------------------------------------------
# 4. Host Boot Hook for Active App Jails (tc_apps)
# ---------------------------------------------------------------------------
info "Installing tc_apps host boot service"
install -o root -g wheel -m 0755 "$CONFIG_DIR/tc_apps.rc" /usr/local/etc/rc.d/tc_apps
sysrc tc_apps_enable="YES" >/dev/null

# ---------------------------------------------------------------------------
# 5. Host Caddy Base Config (/var/db/tc/runtime/Caddyfile)
# ---------------------------------------------------------------------------
info "Installing host Caddy base config (/var/db/tc/runtime/Caddyfile)"
install -o root -g wheel -m 0644 "$CONFIG_DIR/caddyfile.base" /var/db/tc/runtime/Caddyfile

if [ -n "$PANEL_DOMAIN" ]; then
    info "Adding panel route for $PANEL_DOMAIN"
    cat <<EOF > /var/db/tc/runtime/routes/00-tc_panel.caddy
${PANEL_DOMAIN} {
    root * ${PANEL_JAIL_DIR}/app/public
    encode zstd gzip
    php_fastcgi unix//var/run/tc/sockets/tc_panel/php-fpm.sock {
        root /app/public
    }
    file_server
}
EOF
    chmod 0644 /var/db/tc/runtime/routes/00-tc_panel.caddy
fi

# Register platform jails in /etc/rc.conf jail_list in boot dependency order.
# Active app jails are started after platform jails + pf by /usr/local/etc/rc.d/tc_apps.
sysrc jail_list="${JAIL_LIST}" >/dev/null

if command -v caddy >/dev/null 2>&1; then
    caddy validate --config /var/db/tc/runtime/Caddyfile --adapter caddyfile
    service caddy restart || service caddy start
fi

info "Platform jails and edge services are running:"
jls -v
