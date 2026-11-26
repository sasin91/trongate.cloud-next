#!/bin/sh
# Bare-metal FreeBSD (14.3+/15.x) host bootstrap for trongate.cloud.
# Idempotent POSIX sh script: sets up ZFS datasets, RACCT/rctl, lo1, pf/jail/caddy
# rc.conf defaults, /etc/pf.conf (auto-detecting ext_if), runtime directories,
# tc-agent/tc-caddy accounts, doas.conf, /usr/local/libexec/tc-runtime, and
# /etc/tc-resources.json.
set -eu

TC_POOL="${TC_POOL:-zroot}"
TC_SKIP_PKG="${TC_SKIP_PKG:-0}"
TC_FORCE_CONFIG="${TC_FORCE_CONFIG:-0}"
TC_AGENT_PUBKEY="${TC_AGENT_PUBKEY:-}"
TC_PHP_RUNTIME="${TC_PHP_RUNTIME:-php84}"
TC_EXT_IF="${TC_EXT_IF:-}"

SCRIPT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
INFRA_DIR="$(CDPATH= cd -- "$SCRIPT_DIR/.." && pwd)"
CONFIG_DIR="$INFRA_DIR/config"
RUNTIME_SRC_DIR="$INFRA_DIR/runtime"

die() {
    printf 'ERROR: %s\n' "$*" >&2
    exit 1
}

info() {
    printf '==> %s\n' "$*"
}

warn() {
    printf 'WARNING: %s\n' "$*" >&2
}

# 1. Verify environment: FreeBSD, root, ZFS pool.
if [ "$(uname -s)" != "FreeBSD" ]; then
    die "This script must run on FreeBSD (detected $(uname -s))."
fi

if [ "$(id -u)" -ne 0 ]; then
    die "Run bootstrap.sh as root."
fi

FREEBSD_RELEASE="$(freebsd-version -u | sed 's/-p[0-9]*$//')"
BASE_RELEASE="${TC_BASE_RELEASE:-$FREEBSD_RELEASE}"
MAJOR_VER="$(printf '%s' "$FREEBSD_RELEASE" | cut -d. -f1)"
MINOR_VER="$(printf '%s' "$FREEBSD_RELEASE" | cut -d. -f2 | cut -d- -f1)"

if [ "$MAJOR_VER" -lt 14 ] || { [ "$MAJOR_VER" -eq 14 ] && [ "$MINOR_VER" -lt 3 ]; }; then
    warn "FreeBSD 14.3+ or 15.x is recommended (detected $FREEBSD_RELEASE)."
fi

if ! zpool list -H "$TC_POOL" >/dev/null 2>&1; then
    die "ZFS pool '$TC_POOL' not found. Set TC_POOL=<pool> to specify your ZFS pool."
fi

info "Bootstrapping FreeBSD $FREEBSD_RELEASE host on ZFS pool '$TC_POOL'"

# 2. Install required host packages.
if [ "$TC_SKIP_PKG" != "1" ]; then
    info "Installing host packages (doas, caddy, php84, fcgi-devkit, mariadb-client, valkey, git, curl)"
    ASSUME_ALWAYS_YES=yes pkg install -y \
        doas \
        caddy \
        php84 \
        php84-ctype \
        php84-curl \
        php84-filter \
        php84-mbstring \
        php84-pdo \
        php84-pdo_mysql \
        php84-posix \
        php84-tokenizer \
        fcgi-devkit \
        mariadb114-client \
        valkey \
        git-lite \
        curl \
        ca_root_nss
else
    info "Skipping pkg install (TC_SKIP_PKG=1)"
fi

# 3. Create ZFS dataset hierarchy.
ensure_zfs() {
    ds="$1"
    shift
    if ! zfs list -H "$ds" >/dev/null 2>&1; then
        info "Creating ZFS dataset $ds"
        zfs create -p "$@" "$ds"
    else
        while [ "$#" -gt 0 ]; do
            if [ "$1" = "-o" ] && [ "$#" -ge 2 ]; then
                zfs set "$2" "$ds"
                shift 2
            else
                shift
            fi
        done
    fi
}

info "Ensuring ZFS dataset hierarchy under $TC_POOL/tc"
ensure_zfs "$TC_POOL/tc" -o "mountpoint=/$TC_POOL/tc" -o atime=off -o compression=lz4
ensure_zfs "$TC_POOL/tc/bases" -o atime=off -o compression=lz4
ensure_zfs "$TC_POOL/tc/runtimes" -o atime=off -o compression=lz4
ensure_zfs "$TC_POOL/tc/skeleton" -o atime=off -o compression=lz4
ensure_zfs "$TC_POOL/tc/services" -o atime=off -o compression=lz4
ensure_zfs "$TC_POOL/tc/services/mariadb" -o recordsize=16K -o atime=off -o compression=lz4
ensure_zfs "$TC_POOL/tc/services/valkey" -o atime=off -o compression=lz4
ensure_zfs "$TC_POOL/tc/apps" -o atime=off -o compression=lz4
ensure_zfs "$TC_POOL/tc/jails" -o atime=off -o compression=lz4
ensure_zfs "$TC_POOL/tc/builds" -o atime=off -o compression=lz4

# 4. Enable RACCT/RCTL in /boot/loader.conf (sysrc rejects dotted loader tunable names).
info "Configuring /boot/loader.conf for rctl(8)"
touch /boot/loader.conf
if grep -q '^kern\.racct\.enable=' /boot/loader.conf; then
    sed -i '' 's/^kern\.racct\.enable=.*/kern.racct.enable="1"/' /boot/loader.conf
else
    printf 'kern.racct.enable="1"\n' >> /boot/loader.conf
fi

RACCT_NEEDS_REBOOT=0
if [ "$(sysctl -n kern.racct.enable 2>/dev/null || echo 0)" != "1" ]; then
    RACCT_NEEDS_REBOOT=1
    warn "kern.racct.enable is not active yet. Reboot this host after bootstrap so rctl(8) caps take effect."
fi

# 5. Configure /etc/rc.conf for lo1, pf, jail, caddy, and sshd.
info "Configuring /etc/rc.conf"
CURRENT_CLONED="$(sysrc -n cloned_interfaces 2>/dev/null || true)"
case " $CURRENT_CLONED " in
    *" lo1 "*) ;;
    *)
        sysrc cloned_interfaces+="lo1" >/dev/null
        ;;
esac

sysrc \
    ifconfig_lo1="inet 10.99.0.1/16" \
    pf_enable="YES" \
    pf_rules="/etc/pf.conf" \
    pflog_enable="YES" \
    jail_enable="YES" \
    jail_parallel_start="YES" \
    caddy_enable="YES" \
    caddy_config="/var/db/tc/runtime/Caddyfile" \
    sshd_enable="YES" >/dev/null

# Bring up lo1 immediately so platform-jails.sh can run before reboot.
if ! ifconfig lo1 >/dev/null 2>&1; then
    ifconfig lo1 create
fi
if ! ifconfig lo1 | grep -q 'inet 10\.99\.0\.1 '; then
    ifconfig lo1 inet 10.99.0.1/16 up
fi

# Install /etc/pf.conf from infra/config/pf.conf with auto-detected default-route interface.
if [ ! -f /etc/pf.conf ] || [ "$TC_FORCE_CONFIG" = "1" ]; then
    DETECTED_IF="${TC_EXT_IF:-$(route -n get default 2>/dev/null | awk '/interface:/ { print $2; exit }')}"
    DETECTED_IF="${DETECTED_IF:-vtnet0}"
    info "Installing /etc/pf.conf (ext_if=${DETECTED_IF})"
    sed "s|@EXT_IF@|${DETECTED_IF}|g" "$CONFIG_DIR/pf.conf" > /etc/pf.conf
    chown root:wheel /etc/pf.conf
    chmod 0644 /etc/pf.conf
    kldstat -q -m pf || kldload pf
    pfctl -nf /etc/pf.conf || die "/etc/pf.conf syntax check failed"
else
    info "Keeping existing /etc/pf.conf (set TC_FORCE_CONFIG=1 to overwrite)"
fi

# 6. Create tc-caddy group and tc-agent non-root user.
info "Ensuring tc-caddy group and tc-agent user"
if ! pw groupshow tc-caddy >/dev/null 2>&1; then
    pw groupadd tc-caddy
fi

if ! pw usershow tc-agent >/dev/null 2>&1; then
    # -h - sets the password hash to '*' (disables password login while allowing SSH public keys).
    # Do NOT run `pw lock` on FreeBSD: '*LOCKED*' causes sshd to reject public-key logins too.
    pw useradd tc-agent \
        -c "trongate.cloud forced-command runtime agent" \
        -d /home/tc-agent \
        -m \
        -s /bin/sh \
        -h -
fi

# 7. Create directories with tight permissions.
info "Creating runtime, socket, and jail directories"
install -d -o root -g wheel -m 0755 /var/db/tc
install -d -o root -g wheel -m 0700 /var/db/tc/runtime
install -d -o root -g wheel -m 0700 /var/db/tc/runtime/apps
install -d -o root -g wheel -m 0700 /var/db/tc/runtime/jobs
install -d -o root -g wheel -m 0700 /var/db/tc/runtime/operations
install -d -o root -g wheel -m 0700 /var/db/tc/runtime/routes

# Socket parent directories: root:wheel 0755 so app jails can connect(2) without unlinking.
install -d -o root -g wheel -m 0755 /var/run/tc
install -d -o root -g wheel -m 0755 /var/run/tc/mariadb
install -d -o root -g wheel -m 0755 /var/run/tc/valkey
install -d -o root -g wheel -m 0755 /var/run/tc/sockets

install -d -o root -g wheel -m 0755 /etc/jail.conf.d
install -d -o root -g wheel -m 0755 /usr/local/libexec/tc
if [ ! -f /etc/jail.conf ]; then
    printf '.include "/etc/jail.conf.d/*.conf";\n' > /etc/jail.conf
    chmod 0644 /etc/jail.conf
fi

# 8. Install host runtime PHP files into /usr/local/libexec/tc and forced-command wrapper.
info "Installing runtime helpers into /usr/local/libexec/tc"
install_runtime_file() {
    src="$1"
    dst="$2"
    mode="$3"
    if [ -f "$src" ]; then
        install -o root -g wheel -m "$mode" "$src" "$dst"
    else
        warn "Source file not found: $src (install it manually into $dst)"
    fi
}

install_runtime_file "$RUNTIME_SRC_DIR/runtime.php" "/usr/local/libexec/tc/runtime.php" 0644
install_runtime_file "$RUNTIME_SRC_DIR/builder.php" "/usr/local/libexec/tc/builder.php" 0644
install_runtime_file "$RUNTIME_SRC_DIR/resources.php" "/usr/local/libexec/tc/resources.php" 0644
install_runtime_file "$RUNTIME_SRC_DIR/metrics.php" "/usr/local/libexec/tc/metrics.php" 0644
install_runtime_file "$RUNTIME_SRC_DIR/app_database.php" "/usr/local/libexec/tc/app_database.php" 0644

cat <<'EOF' > /usr/local/libexec/tc-runtime
#!/bin/sh
set -eu
export TC_RUNTIME_ROOT="${TC_RUNTIME_ROOT:-/var/db/tc/runtime}"
exec /usr/local/bin/php /usr/local/libexec/tc/runtime.php "$@"
EOF
chown root:wheel /usr/local/libexec/tc-runtime
chmod 0755 /usr/local/libexec/tc-runtime

# 9. Install /usr/local/etc/doas.conf from infra/config/doas.conf.
info "Installing /usr/local/etc/doas.conf"
install -o root -g wheel -m 0600 "$CONFIG_DIR/doas.conf" /usr/local/etc/doas.conf

if command -v doas >/dev/null 2>&1; then
    doas -C /usr/local/etc/doas.conf || die "doas.conf syntax check failed"
fi

# 10. Write /etc/tc-resources.json (auto-scaled for host RAM, sensible 32 GB+ defaults).
if [ ! -f /etc/tc-resources.json ] || [ "$TC_FORCE_CONFIG" = "1" ]; then
    PHYSMEM_BYTES="$(sysctl -n hw.physmem)"
    PHYSMEM_MB="$((PHYSMEM_BYTES / 1048576))"

    if [ "$PHYSMEM_MB" -ge 28672 ]; then
        DEFAULT_SYS_MB=2048
        DEFAULT_DB_MB=4096
        DEFAULT_VALKEY_MB=512
        DEFAULT_BUILD_MB=2048
    elif [ "$PHYSMEM_MB" -ge 14336 ]; then
        DEFAULT_SYS_MB=1024
        DEFAULT_DB_MB=2048
        DEFAULT_VALKEY_MB=256
        DEFAULT_BUILD_MB=1024
    else
        DEFAULT_SYS_MB=512
        DEFAULT_DB_MB=512
        DEFAULT_VALKEY_MB=128
        DEFAULT_BUILD_MB=512
    fi

    SYSTEM_MEMORY_MB="${TC_SYSTEM_MEMORY_MB:-$DEFAULT_SYS_MB}"
    DATABASE_MEMORY_MB="${TC_DATABASE_MEMORY_MB:-$DEFAULT_DB_MB}"
    VALKEY_MEMORY_MB="${TC_VALKEY_MEMORY_MB:-$DEFAULT_VALKEY_MB}"
    BUILD_MEMORY_MB="${TC_BUILD_MEMORY_MB:-$DEFAULT_BUILD_MB}"
    MEMORY_OVERCOMMIT_RATIO="${TC_MEMORY_OVERCOMMIT_RATIO:-2.0}"

    info "Writing /etc/tc-resources.json (detected ${PHYSMEM_MB} MiB RAM)"
    cat <<EOF > /etc/tc-resources.json
{
    "system_memory_mb": ${SYSTEM_MEMORY_MB},
    "database_memory_mb": ${DATABASE_MEMORY_MB},
    "valkey_memory_mb": ${VALKEY_MEMORY_MB},
    "build_memory_mb": ${BUILD_MEMORY_MB},
    "memory_overcommit_ratio": ${MEMORY_OVERCOMMIT_RATIO},
    "zfs_pool": "${TC_POOL}",
    "base_release": "${BASE_RELEASE}",
    "php_runtime": "${TC_PHP_RUNTIME}"
}
EOF
    chown root:wheel /etc/tc-resources.json
    chmod 0644 /etc/tc-resources.json
else
    info "Keeping existing /etc/tc-resources.json (set TC_FORCE_CONFIG=1 to overwrite)"
fi

# 11. Install forced-command authorized_keys sample (and live key if TC_AGENT_PUBKEY is set).
info "Configuring tc-agent SSH forced-command template"
install -d -o tc-agent -g tc-agent -m 0700 /home/tc-agent/.ssh

FORCED_OPTS='command="/usr/local/bin/doas /usr/local/libexec/tc-runtime",no-port-forwarding,no-X11-forwarding,no-agent-forwarding,no-pty'
cat <<EOF > /home/tc-agent/.ssh/authorized_keys.sample
# Replace the placeholder Ed25519 public key below with your control-plane key
# and install this file as /home/tc-agent/.ssh/authorized_keys (mode 0600).
${FORCED_OPTS} ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIREPLACE_WITH_CONTROL_PLANE_PUBLIC_KEY tc-panel
EOF
chown tc-agent:tc-agent /home/tc-agent/.ssh/authorized_keys.sample
chmod 0600 /home/tc-agent/.ssh/authorized_keys.sample

if [ -n "$TC_AGENT_PUBKEY" ]; then
    printf '%s %s\n' "$FORCED_OPTS" "$TC_AGENT_PUBKEY" > /home/tc-agent/.ssh/authorized_keys
    chown tc-agent:tc-agent /home/tc-agent/.ssh/authorized_keys
    chmod 0600 /home/tc-agent/.ssh/authorized_keys
    info "Installed /home/tc-agent/.ssh/authorized_keys from TC_AGENT_PUBKEY"
fi

# 12. Summary.
info "Host bootstrap complete."
printf '\nNext steps:\n'
if [ "$RACCT_NEEDS_REBOOT" -eq 1 ]; then
    printf '  1. Reboot to activate kern.racct.enable=1 (required for rctl(8)).\n'
    printf '  2. Review /etc/pf.conf and start PF: service pf start\n'
    printf '  3. Run sh %s/build-runtime.sh\n' "$SCRIPT_DIR"
    printf '  4. Run sh %s/platform-jails.sh\n' "$SCRIPT_DIR"
else
    printf '  1. Review /etc/pf.conf and start PF: service pf start\n'
    printf '  2. Run sh %s/build-runtime.sh\n' "$SCRIPT_DIR"
    printf '  3. Run sh %s/platform-jails.sh\n' "$SCRIPT_DIR"
fi
