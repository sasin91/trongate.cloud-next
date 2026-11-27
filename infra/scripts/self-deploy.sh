#!/bin/sh
# Forced-command self-deploy entrypoint for trongate.cloud CI/CD.
# Invoked as: /usr/local/bin/doas /usr/local/libexec/tc-self-deploy (zero args)
# Reads a verified git archive .tar.gz from stdin, snapshots tc_panel on ZFS,
# updates host runtime + tc_panel app, runs migrations, restarts services,
# and verifies local FastCGI health.
set -eu

if [ "$#" -ne 0 ]; then
    printf '{"status":"failed","error":"arguments not permitted"}\n'
    exit 1
fi

LOCK_FILE="/var/db/tc/runtime/self-deploy.lock"
if [ -z "${TC_SELF_DEPLOY_LOCKED:-}" ]; then
    export TC_SELF_DEPLOY_LOCKED=1
    exec /usr/bin/lockf -t 30 "$LOCK_FILE" "$0"
fi

TC_POOL="$(/usr/local/bin/php -r '$c = @json_decode(@file_get_contents("/etc/tc-resources.json"), true); echo is_array($c) && !empty($c["zfs_pool"]) ? $c["zfs_pool"] : "zroot";')"
PANEL_JAIL_DS="${TC_POOL}/tc/jails/tc_panel"
PANEL_APP_DIR="/${PANEL_JAIL_DS}/app"
PANEL_JAIL_DIR="/${PANEL_JAIL_DS}"

STAGE="$(mktemp -d /tmp/tc-self-deploy.XXXXXX)"
cleanup() {
    rm -rf "$STAGE"
}
trap cleanup EXIT INT TERM

# 1. Extract verified archive from stdin (cap at 50 MiB).
ARCHIVE="$STAGE/release.tar.gz"
head -c 52428800 > "$ARCHIVE"
mkdir -p "$STAGE/src"
tar -xzf "$ARCHIVE" -C "$STAGE/src"

SRC="$STAGE/src"
for required in \
    config/database.php \
    engine/cli_bootstrap.php \
    public/index.php \
    infra/runtime/runtime.php \
    modules/jobs/worker.php \
    modules/monitoring/collect.php \
    modules/setup/Setup.php \
    modules/setup/Setup_model.php \
    modules/setup/init.php \
    modules/setup/migrate.php \
    setup.sql; do
    if [ ! -f "$SRC/$required" ]; then
        printf '{"status":"failed","error":"missing required file: %s"}\n' "$required"
        exit 1
    fi
done

# 2. Syntax-check critical PHP files before touching live state.
for f in \
    "$SRC"/config/database.php \
    "$SRC"/engine/cli_bootstrap.php \
    "$SRC"/engine/Core.php \
    "$SRC"/modules/db/Db.php \
    "$SRC"/modules/jobs/worker.php \
    "$SRC"/modules/monitoring/collect.php \
    "$SRC"/modules/setup/*.php \
    "$SRC"/public/index.php \
    "$SRC"/infra/runtime/*.php; do
    [ -f "$f" ] || continue
    /usr/local/bin/php -l "$f" >/dev/null
done

# 3. Take pre-deploy ZFS rollback snapshot on tc_panel (keep last 5).
SNAP_NAME="pre-deploy-$(date -u +%Y%m%d%H%M%S)"
if zfs list -H "$PANEL_JAIL_DS" >/dev/null 2>&1; then
    zfs snapshot "${PANEL_JAIL_DS}@${SNAP_NAME}"
    zfs list -H -t snapshot -o name -S creation "$PANEL_JAIL_DS" \
        | grep '@pre-deploy-' \
        | awk 'NR > 5' \
        | while IFS= read -r old_snap; do
            zfs destroy "$old_snap" 2>/dev/null || true
        done || true
fi

# 4. Install updated host runtime helpers and infra copies.
for f in "$SRC"/infra/runtime/*.php; do
    [ -f "$f" ] || continue
    install -o root -g wheel -m 0644 "$f" "/usr/local/libexec/tc/$(basename "$f")"
done
if [ -d "$SRC/infra" ]; then
    mkdir -p /root/tc-infra
    tar -cf - -C "$SRC/infra" . | tar -xf - -C /root/tc-infra
    chmod +x /root/tc-infra/scripts/*.sh 2>/dev/null || true
fi

# Update tc_panel rc.d scripts if present.
for rc in tc_panel_fpm tc_worker tc_monitor; do
    if [ -f "$SRC/infra/config/${rc}.rc" ] && [ -d "$PANEL_JAIL_DIR/etc/rc.d" ]; then
        install -o root -g wheel -m 0755 "$SRC/infra/config/${rc}.rc" "$PANEL_JAIL_DIR/etc/rc.d/${rc}"
    fi
done

# 5. Stage panel app tree atomically inside tc_panel.
NEW_APP="$PANEL_JAIL_DIR/app.new.$$"
OLD_APP="$PANEL_JAIL_DIR/app.old.$$"
mkdir -p "$NEW_APP"
tar -cf - -C "$SRC" --exclude='.git' --exclude='var' --exclude='tests' --exclude='.github' . \
    | tar -xf - -C "$NEW_APP"
chmod -R a+rX "$NEW_APP"

if [ -d "$PANEL_APP_DIR" ]; then
    mv "$PANEL_APP_DIR" "$OLD_APP"
fi
mv "$NEW_APP" "$PANEL_APP_DIR"
rm -rf "$OLD_APP"

PANEL_SRC_DIR="/root/tc-panel-src"
NEW_PANEL_SRC="/root/tc-panel-src.new.$$"
OLD_PANEL_SRC="/root/tc-panel-src.old.$$"
mkdir -p "$NEW_PANEL_SRC"
tar -cf - -C "$PANEL_APP_DIR" . | tar -xf - -C "$NEW_PANEL_SRC"
if [ -d "$PANEL_SRC_DIR" ]; then
    mv "$PANEL_SRC_DIR" "$OLD_PANEL_SRC"
fi
mv "$NEW_PANEL_SRC" "$PANEL_SRC_DIR"
if [ -d "$OLD_PANEL_SRC" ]; then
    rm -rf "$OLD_PANEL_SRC"
fi

# 6. Apply database migrations as www inside tc_panel.
jexec -U www tc_panel /bin/sh -c '
    set -eu
    set -a; . /var/db/tc/panel/db.env; set +a
    export PHP_INI_SCAN_DIR=/usr/local/etc/php-base:/etc/tc/php.d
    export TC_STATE=/var/db/tc/panel
    /usr/local/bin/php /app/modules/setup/migrate.php >/dev/null
'

# 7. Restart panel services cleanly.
jexec tc_panel service tc_panel_fpm restart >/dev/null
jexec tc_panel service tc_worker restart >/dev/null
jexec tc_panel service tc_monitor restart >/dev/null

# 8. Verify local FastCGI responds cleanly on tc_panel socket.
HEALTH_OK=0
i=0
while [ "$i" -lt 10 ]; do
    OUT="$(env SCRIPT_FILENAME=/app/public/index.php SCRIPT_NAME=/index.php REQUEST_METHOD=GET REQUEST_URI=/tg-admin QUERY_STRING= HTTP_HOST=trongate.cloud DOCUMENT_ROOT=/app/public SERVER_PROTOCOL=HTTP/1.1 GATEWAY_INTERFACE=CGI/1.1 /usr/local/bin/cgi-fcgi -bind -connect /var/run/tc/sockets/tc_panel/php-fpm.sock 2>/dev/null || true)"
    if printf '%s' "$OUT" | grep -q 'Sign in'; then
        HEALTH_OK=1
        break
    fi
    sleep 1
    i=$((i + 1))
done

if [ "$HEALTH_OK" -ne 1 ]; then
    printf '{"status":"failed","error":"tc_panel health check failed after deploy","snapshot":"%s@%s"}\n' "$PANEL_JAIL_DS" "$SNAP_NAME"
    exit 1
fi

printf '{"status":"succeeded","snapshot":"%s@%s"}\n' "$PANEL_JAIL_DS" "$SNAP_NAME"
