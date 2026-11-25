# Trongate Cloud

Code deployments (DeployHQ-style workflow) and bare-metal FreeBSD application
hosting. This continues the original Trongate Cloud product in
`~/Herd/trongate.cloud`: its feature specifications, guided workflows, and calm,
structured Mint/Trongate interface remain the reference. Bare-metal FreeBSD
(14.3+/15.x) with ZFS, vanilla `jail(8)`, `nullfs`, `rctl(8)`, `pf`, host Caddy,
and shared MariaDB/Valkey platform jails is the sole runtime platform.

The current implementation includes ephemeral FreeBSD build jails, immutable ZFS
releases, spin-up-standby blue/green deployments, sub-second rollback, soft and
purged app teardown, ARC-aware capacity monitoring, and live `rctl`/ZFS resource
controls.

Application licensing follows the original proprietary application; upstream
Trongate MIT notices are preserved in `engine/LICENSE.txt`.

## Architecture overview

- **Platform**: Bare-metal FreeBSD 14.3+/15.x, ZFS, vanilla `jail(8)` under
  `/etc/jail.conf.d/`, read-only `nullfs` base and PHP 8.4 runtime mounts,
  `rctl(8)` hard resource caps, and non-VNET `pf` isolation on `lo1`
  (`10.99.0.0/16`).
- **Control plane**: Runs in its own `tc_panel` jail (`10.99.0.2`) on Host #1,
  talking to Host #1 via `10.99.0.1:22` (`pf`-restricted to `tc_panel`) and to
  Host #2..N over the exact same pinned Ed25519 SSH transport.
- **Security boundary**: Non-root `tc-agent` SSH user with an `authorized_keys`
  forced-command `/usr/local/bin/doas /usr/local/libexec/tc-runtime` speaking
  protocol v2 JSON over stdin/stdout with `flock` locking and `fsync` journals.
  Zero arbitrary shell execution from PHP — even `Ssh::inspect()` sends
  `{"action":"inspect-host"}`.
- **Blue/Green lifecycle**: Each app has `blue` and `green` jail slots with a
  single running jail steady-state (`php-fpm` `pm = ondemand`). Deploys spin up
  the standby slot, verify PHP-FPM health locally, switch host Caddy atomically,
  and stop the previous slot while retaining it on ZFS for sub-second rollback.
- **Shared platform jails**: Dedicated `tc_mariadb` (`recordsize=16K`, per-app
  DB/user with `MAX_USER_CONNECTIONS=20` and `max_statement_time=30`) and
  `tc_valkey` (sessions only, per-app ACL `~tc:<app>:*`) expose Unix sockets
  under `/var/run/tc/{mariadb,valkey}` (`root:wheel 0755`).
- **Host Caddy edge & TLS**: Serves static assets directly from ZFS ARC,
  proxies PHP via Unix FastCGI sockets
  (`/var/run/tc/sockets/<app>-<slot>/php-fpm.sock`), and handles automatic HTTPS
  for each explicit site block in `/var/db/tc/runtime/Caddyfile`.

## Provision a bare-metal FreeBSD host

Run the host provisioning scripts under [`infra/`](infra/README.md) as
`root` on a FreeBSD 14.3+ or 15.x machine with ZFS (`zroot` by default):

```sh
git clone git@github.com:sasin91/trongate.cloud-next.git /opt/trongate-cloud-next
cd /opt/trongate-cloud-next
sh infra/scripts/bootstrap.sh
sh infra/scripts/build-runtime.sh
sh infra/scripts/platform-jails.sh
```

Initialize the control-plane encryption key, schema, and administrator account
inside the `tc_panel` jail (see [infra/README.md](infra/README.md) and
[docs/self-hosting.md](docs/self-hosting.md)):

```sh
# Create a temporary 0600 admin JSON file:
# {"name":"admin","password":"a unique password of at least 14 characters"}
jexec tc_panel sh -c 'cat /tmp/tc-admin.json | env TC_STATE=/var/db/tc/panel php /app/modules/setup/init.php'
jexec tc_panel rm -f /tmp/tc-admin.json
jexec tc_panel service tc_worker restart
```

Each module owns its command-line entry point:

```sh
php modules/setup/init.php
php modules/setup/migrate.php
php modules/jobs/worker.php --once
php modules/monitoring/collect.php
```

Run manual CLI commands with the same database and `TC_STATE` environment as
the panel and worker. Product operations run through the authenticated panel.

## Deploy code and manage apps

1. Open **Servers** and enroll Host #1 (`10.99.0.1`, user `tc-agent`) or any
   additional bootstrapped FreeBSD host using its pinned Ed25519 key. Readiness
   inspection calls `tc-runtime` with `{"action":"inspect-host"}` and verifies
   ZFS, `jail(8)`, `rctl(8)`, `pf`, Caddy, MariaDB, Valkey, PHP 8.4, base and
   runtime datasets, builder snapshot, and protocol v2.
2. Open **Apps → Create app**, choose a public GitHub repository and branch or
   commit, select optional curated PHP extensions, review build commands,
   environment variables, persistent mounts, and domain.
3. Follow deployment progress, connect DNS, and click **Check DNS and go live**.
   Retained previous jail slots support sub-second rollback without rebuilding.

Config overlays (`/app/config/config.php` patching only `BASE_URL` and `ENV` in
place so custom user defines stay intact, and `/app/config/database.php`
generated from the provisioned MariaDB grant) are materialized `0440` (`root:www`)
and mounted read-only via `nullfs`. Persistent named mounts live under
`zroot/tc/apps/<app>/data/<mount>` with `exec=off,setuid=off`.

Candidate local health (`cgi-fcgi` over the slot's PHP-FPM Unix socket) must
pass before Caddy routes change. A failed post-switch check restores and checks
the prior route. Public HTTPS checks are enabled by default; set
`"public_check": false` only when DNS/TLS is unavailable in a disposable
environment.

## Verification

```sh
make check                         # PHP lint + all colocated .phpt tests (auto-skips MariaDB without TC_DSN)
php tests/run-tests.php            # direct .phpt runner (Windows/Linux/FreeBSD, no make or composer needed)
TC_DSN='mysql:host=127.0.0.1;dbname=tc' TC_DB_USER=tc make test-mariadb
```

Tests are plain PHP-internals-style `.phpt` files colocated with their owning
module (`engine/tests/`, `modules/*/tests/`, `infra/runtime/tests/`) plus
cross-module integration tests under `tests/`. They run on Windows, Linux, and
FreeBSD using overridable system-command seams in `Runtime`, `Builder`,
`Resources`, and `Metrics`.

Further reading:
- [FreeBSD host bring-up](infra/README.md)
- [Architecture and recovery](docs/architecture.md)
- [Builds](docs/builds.md)
- [Builds and capacity monitoring](docs/builds-and-monitoring.md)
- [Resource allocation](docs/resource-allocation.md)
- [Self-hosting and backup](docs/self-hosting.md)
- [Module boundaries](docs/extensions.md)
- [Verification evidence and limits](docs/verification.md)
- [Historical source inspection](docs/inspection.md)
