# Self-Hosting, Updates, and Recovery

Trongate Cloud runs on bare-metal FreeBSD 14.3+ or 15.x with ZFS. The control
plane runs inside its own dedicated `tc_panel` jail (`10.99.0.2` on `lo1`) on
Host #1 and manages Host #1 (`10.99.0.1:22`, `pf`-restricted to `tc_panel`) and
any additional FreeBSD hosts (Host #2..N) over the exact same pinned Ed25519
SSH forced-command path.

See [infra/README.md](../infra/README.md) for the full bare-metal bring-up
runbook and smoke-test checklist.

## Initial host and control-plane setup

1. Provision Host #1 as `root`:

```sh
git clone git@github.com:sasin91/trongate.cloud-next.git /opt/trongate-cloud-next
cd /opt/trongate-cloud-next
sh infra/scripts/bootstrap.sh
sh infra/scripts/build-runtime.sh
sh infra/scripts/platform-jails.sh
```

2. Generate a dedicated Ed25519 SSH key pair for `tc_panel` -> `tc-agent` and
   install its public key in `/home/tc-agent/.ssh/authorized_keys` using the
   forced-command prefix from `/home/tc-agent/.ssh/authorized_keys.sample`:

```text
command="/usr/local/bin/doas /usr/local/libexec/tc-runtime",no-port-forwarding,no-X11-forwarding,no-agent-forwarding,no-pty ssh-ed25519 AAAA... tc-panel
```

3. Environment variables configured inside `tc_panel` by `platform-jails.sh`:

```sh
TC_STATE=/var/db/tc/panel
TC_KEY_FILE=/var/db/tc/panel/key
TC_BASE_URL=https://control.example.com
TC_DSN=mysql:unix_socket=/var/run/tc/mariadb/mysqld.sock;dbname=tc_panel;charset=utf8mb4
TC_DB_USER=tc_panel
# TC_DB_PASSWORD is stored in /var/db/tc/panel/db.env (0600, owned by www).
```

4. Initialize the encryption key, MariaDB schema, and local administrator:

```sh
# Create /tmp/tc-admin.json inside tc_panel with mode 0600:
# {"name":"admin","password":"a unique password of at least 14 characters"}
jexec tc_panel sh -c 'cat /tmp/tc-admin.json | env TC_STATE=/var/db/tc/panel php /app/modules/setup/init.php'
jexec tc_panel rm -f /tmp/tc-admin.json
jexec tc_panel service tc_worker restart
```

5. Back up `/var/db/tc/panel/key` immediately. Losing this key loses access to
   all encrypted SSH keys, application environment secrets, and config-file
   secrets stored in MariaDB.

6. Enroll Host #1 in the panel as `10.99.0.1` (port `22`, user `tc-agent`). The
   enrollment flow pins the server's Ed25519 host key on first connect and runs
   `{"action":"inspect-host"}` over the pinned connection. Subsequent
   connections reject changed host keys.

## Adding Host #2..N

Run `infra/scripts/bootstrap.sh`, `infra/scripts/build-runtime.sh`, and
`infra/scripts/platform-jails.sh` (without enabling a second `tc_panel`) on each
additional bare-metal FreeBSD host. Install the panel's `tc-agent` public key
with the forced-command restriction, allow SSH from Host #1 on `pf`, and enroll
the new host in **Servers** over its public/management IP.

## Updating the control plane and host runtime

```sh
cd /opt/trongate-cloud-next
git pull --ff-only
sh infra/scripts/bootstrap.sh        # updates /usr/local/libexec/tc-runtime and runtime PHP helpers
jexec tc_panel env TC_STATE=/var/db/tc/panel php /app/modules/setup/migrate.php
jexec tc_panel service tc_worker restart
jexec tc_panel service tc_monitor restart
jexec tc_panel service tc_panel_fpm restart
```

`modules/setup/migrate.php` is idempotent. Always back up the control-plane
database before upgrading.

## Backup and restore

Stop `tc_worker` briefly (or use ZFS snapshots combined with a single-transaction
MariaDB dump) to take a consistent backup:

- **Control-plane database**: `mariadb-dump --single-transaction tc_panel`
  (inventory, encrypted credentials, jobs, steps, locks, and admin hashes).
- **Control-plane state & key**: `/var/db/tc/panel/key` and `/var/db/tc/panel/`.
- **Host runtime metadata & TLS state**: `/var/db/tc/runtime` (state, jobs,
  `ips.json`, `resources.json`, `Caddyfile`), `/etc/jail.conf.d/`,
  `/etc/tc-resources.json`, and Caddy's ACME data directory.
- **Application databases & ZFS datasets**: Consistent `mariadb-dump` per app
  plus ZFS snapshots (`zfs snapshot -r zroot/tc/apps@backup-...`). Do not back
  up live MariaDB datafiles without quiescing or taking a logical dump.

Example control-plane backup on Host #1 (encrypt off-host):

```sh
jexec tc_panel service tc_worker stop
umask 077
mariadb --socket=/var/run/tc/mariadb/mysqld.sock -e "SELECT 1" >/dev/null
mariadb-dump --socket=/var/run/tc/mariadb/mysqld.sock --single-transaction tc_panel > /root/tc_panel.sql
zfs snapshot -r zroot/tc/apps@backup-$(date +%Y%m%d%H%M)
tar -C / -czf /root/tc-host-state.tar.gz var/db/tc/runtime etc/jail.conf.d etc/tc-resources.json
jexec tc_panel service tc_worker start
```

When restoring:
1. Restore the database and the **exact matching** `/var/db/tc/panel/key` with
   `0600` permissions. Never run a fresh `init.php` with a new key over restored
   ciphertext.
2. Inspect any `uncertain` jobs before restarting `tc_worker`.
3. Existing application jails and host Caddy continue serving traffic while
   `tc_panel` is offline or being restored.
