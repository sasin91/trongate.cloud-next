# FreeBSD Bare-Metal Host Infrastructure

This directory contains everything deployed onto bare-metal FreeBSD **14.3+ / 15.x** hosts running `trongate.cloud` with ZFS, vanilla `jail(8)`, `nullfs`, `rctl(8)`, `pf`, host Caddy (standard automatic HTTPS), shared MariaDB (`tc_mariadb`), and shared Valkey (`tc_valkey`).

## Layout

| Path | Purpose |
| --- | --- |
| `runtime/runtime.php` | Privileged host runtime (protocol v2), installed to `/usr/local/libexec/tc/runtime.php` and invoked via forced-command `/usr/local/libexec/tc-runtime`. |
| `runtime/builder.php` | Ephemeral `rctl` + `pf`-bounded FreeBSD build jail runner (`10.99.254.1/32` in `<tc_jails>`). |
| `runtime/resources.php` | ARC-aware FreeBSD memory budgeting, live `rctl` and ZFS quota enforcement, and crash recovery. |
| `runtime/metrics.php` | Read-only host and jail telemetry (`sysctl`, ZFS ARC, `jls -N`, `rctl`). |
| `scripts/bootstrap.sh` | Verifies FreeBSD + ZFS pool, creates `zroot/tc/*` datasets, enables `kern.racct.enable`, configures `lo1`, auto-detects `ext_if` for `/etc/pf.conf`, creates `tc-agent`/`tc-caddy`, installs `doas.conf` and `/usr/local/libexec/tc-runtime`, and writes `/etc/tc-resources.json`. |
| `scripts/build-runtime.sh` | Builds and snapshots `bases/<release>@base`, `skeleton@v1`, `runtimes/php84@v1` (core vs curated optional extensions), and `runtimes/builder@v1` (cloned from `php84@v1` + `git-lite`, `node22`, `npm`). |
| `scripts/platform-jails.sh` | Provisions `tc_mariadb`, `tc_valkey`, `tc_panel` (`10.99.0.2`, PHP-FPM + `tc_worker` + `tc_monitor`), host boot hook `tc_apps`, and `/var/db/tc/runtime/Caddyfile`. |
| `config/` | Extracted host configs and FreeBSD rc.d scripts (`pf.conf`, `doas.conf`, `php-base.ini`, `jail-rc.conf`, `mariadb.cnf.in`, `valkey.conf.in`, `caddyfile.base`, `tc_*.rc`). |

---

## Step-by-Step Host Bring-Up

Run all steps as `root` on a fresh FreeBSD 14.3+ or 15.x bare-metal server with ZFS (`zroot` by default; override with `TC_POOL=<pool>`).

### 1. Bootstrap the host

```sh
cd /root/trongate.cloud-next
sh infra/scripts/bootstrap.sh
```

If `kern.racct.enable` was not previously enabled, **reboot now** so `rctl(8)` resource accounting and hard caps become active:

```sh
reboot
```

After reboot, confirm RACCT is active and start PF:

```sh
sysctl kern.racct.enable
# Expected: kern.racct.enable: 1

pfctl -nf /etc/pf.conf
service pf start
```

### 2. Build shared read-only base, skeleton, PHP 8.4, and builder runtimes

```sh
sh infra/scripts/build-runtime.sh
```

### 3. Provision platform jails, boot hook, and Caddy

For Host #1 (control plane + app hosting), pass `PANEL_DOMAIN` and `PANEL_SRC_DIR`:

```sh
PANEL_DOMAIN="panel.example.com" \
PANEL_SRC_DIR="/root/trongate.cloud-next" \
sh infra/scripts/platform-jails.sh
```

For dedicated application hosts (Host #2..N), omit `PANEL_DOMAIN` (or disable `tc_panel` in `jail_list`).

### 4. Configure forced-command SSH access for `tc-agent`

Install the control plane's Ed25519 public key into `/home/tc-agent/.ssh/authorized_keys` (mode `0600`, owned by `tc-agent:tc-agent`) using `/home/tc-agent/.ssh/authorized_keys.sample`:

```sh
command="/usr/local/bin/doas /usr/local/libexec/tc-runtime",no-port-forwarding,no-X11-forwarding,no-agent-forwarding,no-pty ssh-ed25519 AAAA... tc-panel
```

### 5. Initialize the control-plane database & administrator

```sh
jexec -U www tc_panel /bin/sh -c '
  set -a; . /var/db/tc/panel/db.env; set +a
  export PHP_INI_SCAN_DIR=/usr/local/etc/php-base:/etc/tc/php.d
  export TC_STATE=/var/db/tc/panel
  /usr/local/bin/php /app/modules/setup/migrate.php
'
```

Then initialize the first administrator using a temporary `0600` credential file piped to `/app/modules/setup/init.php`, sign in at `https://panel.example.com`, and enroll Host #1 at `10.99.0.1:22` (user `tc-agent`).

---

## Post-Install Smoke-Test Checklist

### 1. Forced-command `tc-runtime` protocol v2 check

```sh
printf '{"action":"inspect-host"}\n' | jexec tc_panel ssh -i /var/db/tc/panel/host-key tc-agent@10.99.0.1
# Expected: JSON with "status":"succeeded", "protocol":2, "os":"freebsd", "application_ready":true

printf '{"action":"inspect-panel-worker"}\n' | jexec tc_panel ssh -i /var/db/tc/panel/host-key tc-agent@10.99.0.1
# Expected: read-only tc_panel jail, tc_worker service, PHP worker process, and deployed worker fingerprint status

jexec tc_panel ssh -i /var/db/tc/panel/host-key tc-agent@10.99.0.1 "id" </dev/null
# Expected: JSON error from tc-runtime (never shell output)
```

### 2. PF jail-to-jail deny, host isolation, and SSRF guard

```sh
pfctl -t tc_jails -T show
jexec tc_app1_blue nc -z -w 2 10.99.0.1 22 && echo "FAIL" || echo "PASS: host SSH blocked"
jexec tc_app1_blue nc -z -w 2 127.0.0.1 2019 && echo "FAIL" || echo "PASS: Caddy admin blocked"
jexec tc_app1_blue nc -z -w 2 169.254.169.254 80 && echo "FAIL" || echo "PASS: metadata IP blocked"
```

### 3. `rctl(8)` & ZFS quota enforcement

```sh
rctl jail:tc_mariadb
rctl jail:tc_valkey
rctl jail:tc_panel
rctl jail:tc_app1_blue
rctl -u jail:tc_app1_blue
zfs list -o name,used,avail,quota,recordsize,exec,setuid,readonly -r zroot/tc
```

### 4. Host reboot durability check

`tc_apps` runs `{"action":"boot"}` after `jail` and `pf` on boot, starting only each app's active slot (never standby slots) and restoring `<tc_jails>` `pf` entries.
