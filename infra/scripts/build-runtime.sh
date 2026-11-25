#!/bin/sh
# Builds the shared read-only FreeBSD base, jail skeleton, PHP 8.4 runtime, and
# ephemeral builder runtime datasets under $TC_POOL/tc.
set -eu

TC_POOL="${TC_POOL:-zroot}"
BASE_RELEASE="${BASE_RELEASE:-$(freebsd-version -u | sed 's/-p[0-9]*$//')}"
ARCH="${ARCH:-$(uname -m)}"
FREEBSD_MIRROR="${FREEBSD_MIRROR:-https://download.freebsd.org/releases}"
PHP_RUNTIME="${PHP_RUNTIME:-php84}"
TC_SKIP_UPDATE="${TC_SKIP_UPDATE:-0}"
TC_REBUILD="${TC_REBUILD:-0}"

SCRIPT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
INFRA_DIR="$(CDPATH= cd -- "$SCRIPT_DIR/.." && pwd)"
CONFIG_DIR="$INFRA_DIR/config"

BASE_DS="$TC_POOL/tc/bases/$BASE_RELEASE"
BASE_DIR="/$BASE_DS"
SKEL_DS="$TC_POOL/tc/skeleton"
SKEL_DIR="/$SKEL_DS"
PHP_DS="$TC_POOL/tc/runtimes/$PHP_RUNTIME"
PHP_DIR="/$PHP_DS"
BUILDER_DS="$TC_POOL/tc/runtimes/builder"
BUILDER_DIR="/$BUILDER_DS"

STAGE_DS=""
STAGE_DIR=""
TMP_DL=""

die() {
    printf 'ERROR: %s\n' "$*" >&2
    exit 1
}

info() {
    printf '==> %s\n' "$*"
}

cleanup() {
    set +e
    if [ -n "$STAGE_DIR" ]; then
        umount -f "$STAGE_DIR/usr/local" 2>/dev/null || true
        umount -f "$STAGE_DIR/.base" 2>/dev/null || true
        umount -f "$STAGE_DIR/dev" 2>/dev/null || true
    fi
    if [ -n "$STAGE_DS" ] && zfs list -H "$STAGE_DS" >/dev/null 2>&1; then
        zfs destroy -rf "$STAGE_DS" 2>/dev/null || true
    fi
    if [ -n "$TMP_DL" ] && [ -d "$TMP_DL" ]; then
        rm -rf "$TMP_DL" 2>/dev/null || true
    fi
}
trap cleanup EXIT INT TERM

if [ "$(uname -s)" != "FreeBSD" ]; then
    die "This script must run on FreeBSD."
fi
if [ "$(id -u)" -ne 0 ]; then
    die "Run build-runtime.sh as root."
fi
if ! zfs list -H "$TC_POOL/tc" >/dev/null 2>&1; then
    die "Dataset $TC_POOL/tc does not exist. Run infra/scripts/bootstrap.sh first."
fi

# ---------------------------------------------------------------------------
# 1. Shared Read-Only FreeBSD Base ($TC_POOL/tc/bases/$BASE_RELEASE@base)
# ---------------------------------------------------------------------------
if zfs list -H -t snapshot "$BASE_DS@base" >/dev/null 2>&1 && [ "$TC_REBUILD" != "1" ]; then
    info "Base snapshot $BASE_DS@base already exists (set TC_REBUILD=1 to rebuild)"
else
    info "Building FreeBSD base dataset $BASE_DS ($ARCH)"
    if zfs list -H "$BASE_DS" >/dev/null 2>&1; then
        zfs set readonly=off "$BASE_DS"
        zfs destroy -rf "$BASE_DS"
    fi
    zfs create -o atime=off -o compression=lz4 "$BASE_DS"

    TMP_DL="$(mktemp -d /tmp/tc-base.XXXXXX)"
    MANIFEST_URL="$FREEBSD_MIRROR/$ARCH/$BASE_RELEASE/MANIFEST"
    BASE_URL="$FREEBSD_MIRROR/$ARCH/$BASE_RELEASE/base.txz"

    info "Fetching base.txz and MANIFEST from $FREEBSD_MIRROR/$ARCH/$BASE_RELEASE"
    fetch -o "$TMP_DL/MANIFEST" "$MANIFEST_URL"
    fetch -o "$TMP_DL/base.txz" "$BASE_URL"

    EXPECTED_SHA="$(awk '$1 == "base.txz" { print $2 }' "$TMP_DL/MANIFEST")"
    ACTUAL_SHA="$(sha256 -q "$TMP_DL/base.txz")"
    if [ -z "$EXPECTED_SHA" ] || [ "$EXPECTED_SHA" != "$ACTUAL_SHA" ]; then
        die "base.txz checksum mismatch (expected $EXPECTED_SHA, got $ACTUAL_SHA)"
    fi

    info "Extracting base.txz into $BASE_DIR"
    tar -xf "$TMP_DL/base.txz" -C "$BASE_DIR" --unlink
    rm -rf "$TMP_DL"
    TMP_DL=""

    if [ "$TC_SKIP_UPDATE" != "1" ]; then
        case "$BASE_RELEASE" in
            *-RELEASE)
                info "Applying security patches via freebsd-update"
                PAGER=cat freebsd-update -b "$BASE_DIR" --not-running-from-cron fetch install || true
                ;;
            *)
                info "Skipping freebsd-update for non-RELEASE branch ($BASE_RELEASE)"
                ;;
        esac
    fi

    zfs set readonly=on "$BASE_DS"
    zfs snapshot "$BASE_DS@base"
    info "Snapshotted $BASE_DS@base"
fi

# ---------------------------------------------------------------------------
# 2. Minimal Jail Skeleton ($TC_POOL/tc/skeleton@v1)
# ---------------------------------------------------------------------------
if zfs list -H -t snapshot "$SKEL_DS@v1" >/dev/null 2>&1 && [ "$TC_REBUILD" != "1" ]; then
    info "Skeleton snapshot $SKEL_DS@v1 already exists (set TC_REBUILD=1 to rebuild)"
else
    info "Building jail skeleton dataset $SKEL_DS"
    if zfs list -H -t snapshot "$SKEL_DS@v1" >/dev/null 2>&1; then
        zfs destroy "$SKEL_DS@v1"
    fi
    if ! zfs list -H "$SKEL_DS" >/dev/null 2>&1; then
        zfs create -o atime=off -o compression=lz4 "$SKEL_DS"
    fi

    find "$SKEL_DIR" -mindepth 1 -maxdepth 1 -exec rm -rf {} +

    mkdir -p \
        "$SKEL_DIR/.base" \
        "$SKEL_DIR/app" \
        "$SKEL_DIR/dev" \
        "$SKEL_DIR/etc/tc/php.d" \
        "$SKEL_DIR/etc/ssl" \
        "$SKEL_DIR/mnt" \
        "$SKEL_DIR/proc" \
        "$SKEL_DIR/root" \
        "$SKEL_DIR/tmp" \
        "$SKEL_DIR/usr/local" \
        "$SKEL_DIR/var/db" \
        "$SKEL_DIR/var/log" \
        "$SKEL_DIR/var/run/php-fpm" \
        "$SKEL_DIR/var/run/tc/mariadb" \
        "$SKEL_DIR/var/run/tc/valkey" \
        "$SKEL_DIR/var/tmp"

    chmod 1777 "$SKEL_DIR/tmp" "$SKEL_DIR/var/tmp"
    chmod 0755 "$SKEL_DIR/var/run/php-fpm" "$SKEL_DIR/var/run/tc" "$SKEL_DIR/var/run/tc/mariadb" "$SKEL_DIR/var/run/tc/valkey"

    ln -s /.base/bin "$SKEL_DIR/bin"
    ln -s /.base/lib "$SKEL_DIR/lib"
    ln -s /.base/libexec "$SKEL_DIR/libexec"
    ln -s /.base/sbin "$SKEL_DIR/sbin"
    ln -s /.base/usr/bin "$SKEL_DIR/usr/bin"
    ln -s /.base/usr/include "$SKEL_DIR/usr/include"
    ln -s /.base/usr/lib "$SKEL_DIR/usr/lib"
    ln -s /.base/usr/libdata "$SKEL_DIR/usr/libdata"
    ln -s /.base/usr/libexec "$SKEL_DIR/usr/libexec"
    ln -s /.base/usr/sbin "$SKEL_DIR/usr/sbin"
    ln -s /.base/usr/share "$SKEL_DIR/usr/share"

    cp -a "$BASE_DIR/etc/." "$SKEL_DIR/etc/"
    if [ -f /etc/resolv.conf ]; then
        cp /etc/resolv.conf "$SKEL_DIR/etc/resolv.conf"
    fi
    if [ -f /etc/localtime ]; then
        cp /etc/localtime "$SKEL_DIR/etc/localtime"
    fi

    install -o root -g wheel -m 0644 "$CONFIG_DIR/jail-rc.conf" "$SKEL_DIR/etc/rc.conf"

    zfs snapshot "$SKEL_DS@v1"
    info "Snapshotted $SKEL_DS@v1"
fi

stage_begin() {
    name="$1"
    target_dir="$2"
    STAGE_DS="$TC_POOL/tc/builds/_stage_${name}_$$"
    STAGE_DIR="/$STAGE_DS"
    zfs clone "$SKEL_DS@v1" "$STAGE_DS"
    mount -t nullfs -o ro "$BASE_DIR" "$STAGE_DIR/.base"
    mount -t nullfs "$target_dir" "$STAGE_DIR/usr/local"
    mount -t devfs devfs "$STAGE_DIR/dev"
    if [ -f /etc/resolv.conf ]; then
        cp /etc/resolv.conf "$STAGE_DIR/etc/resolv.conf"
    fi
}

PORTS_REPO="$(pkg -vv | awk '/^[[:space:]]*FreeBSD-ports[[:space:]]*:/ { print "FreeBSD-ports"; exit } /^[[:space:]]*FreeBSD[[:space:]]*:/ { print "FreeBSD"; exit }')"
PORTS_REPO="${PORTS_REPO:-FreeBSD}"

stage_end() {
    if [ -n "$STAGE_DIR" ]; then
        umount "$STAGE_DIR/dev"
        umount "$STAGE_DIR/usr/local"
        umount "$STAGE_DIR/.base"
    fi
    if [ -n "$STAGE_DS" ]; then
        zfs destroy -rf "$STAGE_DS"
    fi
    STAGE_DS=""
    STAGE_DIR=""
}

# ---------------------------------------------------------------------------
# 3. Shared Read-Only PHP 8.4 Runtime ($TC_POOL/tc/runtimes/php84@v1)
# ---------------------------------------------------------------------------
if zfs list -H -t snapshot "$PHP_DS@v1" >/dev/null 2>&1 && [ "$TC_REBUILD" != "1" ]; then
    info "PHP runtime snapshot $PHP_DS@v1 already exists (set TC_REBUILD=1 to rebuild)"
else
    info "Building shared PHP 8.4 runtime dataset $PHP_DS"
    if zfs list -H "$BUILDER_DS" >/dev/null 2>&1; then
        zfs set readonly=off "$BUILDER_DS"
        zfs destroy -rf "$BUILDER_DS"
    fi
    if zfs list -H "$PHP_DS" >/dev/null 2>&1; then
        zfs set readonly=off "$PHP_DS"
        zfs destroy -rf "$PHP_DS"
    fi
    zfs create -o atime=off -o compression=lz4 "$PHP_DS"

    stage_begin "php84" "$PHP_DIR"

    CORE_PKGS="
        ca_root_nss
        php84
        php84-pdo
        php84-pdo_mysql
        php84-mysqli
        php84-pecl-redis
        php84-gd
        php84-intl
        php84-zip
        php84-bcmath
        php84-exif
        php84-mbstring
        php84-curl
        php84-sodium
        php84-fileinfo
        php84-ctype
        php84-dom
        php84-xml
        php84-simplexml
        php84-xmlreader
        php84-xmlwriter
        php84-filter
        php84-session
        php84-opcache
        php84-tokenizer
        php84-phar
        php84-posix
        php84-zlib
        php84-iconv
        php84-pdo_sqlite
        php84-sqlite3
        php84-composer
    "

    OPTIONAL_PKGS="
        php84-pecl-imagick
        php84-soap
        php84-gmp
        php84-pdo_pgsql
        php84-pgsql
        php84-ldap
        php84-sockets
        php84-xsl
        php84-calendar
        php84-ftp
        php84-pcntl
        php84-shmop
        php84-sysvsem
    "

    info "Installing core PHP 8.4 packages into $PHP_DS via pkg -r ($PORTS_REPO)"
    ASSUME_ALWAYS_YES=yes pkg -r "$STAGE_DIR" update -r "$PORTS_REPO"
    # shellcheck disable=SC2086
    ASSUME_ALWAYS_YES=yes pkg -r "$STAGE_DIR" install -r "$PORTS_REPO" -y $CORE_PKGS

    info "Installing curated optional PHP 8.4 extensions into $PHP_DS via pkg -r ($PORTS_REPO)"
    # shellcheck disable=SC2086
    ASSUME_ALWAYS_YES=yes pkg -r "$STAGE_DIR" install -r "$PORTS_REPO" -y $OPTIONAL_PKGS

    if ASSUME_ALWAYS_YES=yes pkg -r "$STAGE_DIR" search -r "$PORTS_REPO" -e php84-pecl-imap >/dev/null 2>&1; then
        ASSUME_ALWAYS_YES=yes pkg -r "$STAGE_DIR" install -r "$PORTS_REPO" -y php84-pecl-imap || true
    fi

    info "Splitting core vs optional extension ini files into /usr/local/etc/php-base"
    mkdir -p "$PHP_DIR/etc/php-base"
    rm -f "$PHP_DIR/etc/php-base"/*.ini

    is_optional_ini() {
        base="$(basename -- "$1")"
        case "$base" in
            *imagick*.ini|*soap*.ini|*gmp*.ini|*pdo_pgsql*.ini|*pgsql*.ini|\
            *ldap*.ini|*sockets*.ini|*xsl*.ini|*calendar*.ini|*imap*.ini|\
            *pcntl*.ini|*shmop*.ini|*sysvsem*.ini|*ftp*.ini)
                return 0
                ;;
            *)
                return 1
                ;;
        esac
    }

    for ini in "$PHP_DIR/etc/php"/*.ini; do
        [ -f "$ini" ] || continue
        if is_optional_ini "$ini"; then
            continue
        fi
        cp "$ini" "$PHP_DIR/etc/php-base/"
    done

    install -o root -g wheel -m 0644 "$CONFIG_DIR/php-base.ini" "$PHP_DIR/etc/php-base/99-trongate-base.ini"

    info "Verifying PHP_INI_SCAN_DIR=/usr/local/etc/php-base module set"
    chroot "$STAGE_DIR" /sbin/ldconfig /lib /usr/lib /usr/local/lib
    LOADED_MODS="$(chroot "$STAGE_DIR" /usr/bin/env PHP_INI_SCAN_DIR=/usr/local/etc/php-base /usr/local/bin/php -m)"
    for core_mod in pdo_mysql mysqli redis gd intl mbstring curl sodium opcache; do
        printf '%s\n' "$LOADED_MODS" | grep -iqE "^(zend )?${core_mod}$" || die "Core PHP module missing: $core_mod"
    done
    for opt_mod in imagick soap gmp pdo_pgsql pgsql ldap sockets xsl calendar ftp pcntl shmop sysvsem; do
        if printf '%s\n' "$LOADED_MODS" | grep -iq "^${opt_mod}$"; then
            die "Optional PHP module unexpectedly enabled in php-base: $opt_mod"
        fi
    done

    stage_end

    zfs set readonly=on "$PHP_DS"
    zfs snapshot "$PHP_DS@v1"
    info "Snapshotted $PHP_DS@v1"
fi

# ---------------------------------------------------------------------------
# 4. Ephemeral Build-Jail Runtime ($TC_POOL/tc/runtimes/builder@v1)
# ---------------------------------------------------------------------------
if zfs list -H -t snapshot "$BUILDER_DS@v1" >/dev/null 2>&1 && [ "$TC_REBUILD" != "1" ]; then
    info "Builder runtime snapshot $BUILDER_DS@v1 already exists (set TC_REBUILD=1 to rebuild)"
else
    info "Building ephemeral build-jail runtime dataset $BUILDER_DS from $PHP_DS@v1"
    if zfs list -H "$BUILDER_DS" >/dev/null 2>&1; then
        zfs set readonly=off "$BUILDER_DS"
        zfs destroy -rf "$BUILDER_DS"
    fi
    zfs clone -o atime=off -o compression=lz4 -o readonly=off "$PHP_DS@v1" "$BUILDER_DS"

    stage_begin "builder" "$BUILDER_DIR"

    BUILDER_PKGS="
        git-lite
        node22
        npm-node22
    "

    info "Installing builder overlay packages (git-lite, node22, npm-node22)"
    ASSUME_ALWAYS_YES=yes pkg -r "$STAGE_DIR" update -r "$PORTS_REPO"
    # shellcheck disable=SC2086
    ASSUME_ALWAYS_YES=yes pkg -r "$STAGE_DIR" install -r "$PORTS_REPO" -y $BUILDER_PKGS

    stage_end

    zfs set readonly=on "$BUILDER_DS"
    zfs snapshot "$BUILDER_DS@v1"
    info "Snapshotted $BUILDER_DS@v1"
fi

info "All shared runtime datasets are built and snapshotted:"
zfs list -t snapshot -r "$TC_POOL/tc/bases" "$TC_POOL/tc/skeleton" "$TC_POOL/tc/runtimes"
