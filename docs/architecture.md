# Operation boundaries and recovery

## Trongate modules and routing

Core routes directly to `apps`, `servers`, `deployments`, and `jobs`. Each
public feature entry point calls `$this->trongate_security->make_sure_allowed()`
before handling its request, and validates `csrf_token` on mutations. There is
no console command dispatcher or custom authentication module. `templates`
composes the shared layout and module-owned views while preserving the calm
Mint/Trongate interface. App setup saves an encrypted session draft before
navigation and restores the current question when returning. Tokens and SSH
private keys are never stored in `localStorage`.

The `login`, `trongate_tokens`, form, validation, and supporting framework
modules come from the bundled Trongate installation. `login/config/login.php`
maps administrator level 1 to `trongate_administrators`. Native password
validation, rate limiting, and token issuance handle sign-in. Logout requires
POST and CSRF. Remember-me and password reset are disabled. Sessions rotate on
login and tokens retain a one-hour lifetime.

The native `Db` module uses the control plane's configured PDO connection; its
table-existence check also supports SQLite test fixtures. JSON encoding helpers
are private to their owning classes, returning strings with
`JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES`.

Internal controller methods use the underscore convention enforced by
`engine/Core.php` (or individual `block_url(...)` guards where required); never
block an entire controller in its constructor. Feature assets use normal
`_module` URLs and Core's asset checks. Shared styles live in `templates/css`.
Controllers access their model via `$this->model` and load other modules through
Trongate's module loader so database transactions share the caller's PDO
connection. Supporting service classes live under their owning modules in
`services/` and are explicitly loaded by their callers with `require_once`.

The canonical MariaDB schema is root `setup.sql`; SQLite tests use
`modules/setup/fixtures/setup.sqlite.sql`. The CLI-only `Setup_model` applies
these SQL files and imports legacy administrator hashes once per username. There
is no browser setup controller or schema creation during ordinary requests.

## Module ownership

- **Jobs** owns enqueueing, claims, leases, resource locks, progress, terminal
  results, and explicit reconciliation. Its model has no inventory or credential
  access. `Worker` (`modules/jobs/services/Worker.php`) dispatches `build`,
  `deploy`, `rollback`, and `destroy-app` via `Modules::run()` with a
  `Job_context` carrying only identity, payload, and the lease-renewal callback.
- **Records** owns all access to the shared `records` table through internal
  `_get`, `_find`, `_all`, `_insert`, `_put`, and `_delete` actions.
- **Apps** owns app/release persistence, `Trongate_config`, `Build` /
  `Build_commands`, build execution (`apps/_build`), and build-to-deployment
  handoff.
- **Servers** owns FreeBSD host enrollment, SSH host-key pinning, protocol v2
  readiness inspection (`Ssh::inspect()`), and live `rctl`/ZFS resource
  management views.
- **Deployments** owns application rollout (`deployments/_run`), rollback
  (`deployments/_run`), teardown (`deployments/_destroy`), DNS go-live
  verification, and deployment timeline/progress reporting.
- **Monitoring** owns ARC-aware FreeBSD host metrics collection
  (`modules/monitoring/collect.php` -> `monitoring/_collect`) and capacity
  pressure hints.
- **Setup** owns initialization (`modules/setup/init.php`) and migrations
  (`modules/setup/migrate.php`).

## Transport and FreeBSD host security boundary

The control plane runs inside the dedicated `tc_panel` jail (`10.99.0.2`) on
Host #1 and connects to Host #1 (`10.99.0.1:22`, `pf`-restricted to `tc_panel`)
and Host #2..N over the exact same pinned Ed25519 SSH path:

1. `Ssh` pins the server's Ed25519 host key in a per-call temporary
   `known_hosts` file with `StrictHostKeyChecking=yes` and `BatchMode=yes`.
2. Target hosts expose a non-root `tc-agent` SSH user whose `authorized_keys`
   entry forces `/usr/local/bin/doas /usr/local/libexec/tc-runtime` with
   `no-port-forwarding,no-X11-forwarding,no-agent-forwarding,no-pty`.
3. `/usr/local/etc/doas.conf` allows only
   `permit nopass tc-agent as root cmd /usr/local/libexec/tc-runtime args`.
4. PHP never runs arbitrary shell commands on target hosts. Every operation —
   `inspect-host`, `build`, `deploy`, `rollback`, `destroy-app`, `boot`, `status`,
   `inspect`, `metrics`, `resources`, `resources-update`, and `cleanup` — passes
   as structured protocol v2 JSON over stdin/stdout to `infra/runtime/runtime.php`.

## Queue and fencing

Jobs use random 32-hex operation IDs, atomic conditional claims, 90-second
leases, 10-second subprocess heartbeats, at most 30 claims, and per-command
deadlines. A resource lock belongs to an operation, not a worker; expiring a
worker lease does not release that lock. A replacement worker can claim only the
same operation.

All app deploy, rollback, and destroy operations for a FreeBSD host share its
`server:<id>` lock. On the host itself, `tc-runtime` acquires a non-blocking
`flock` on `/var/db/tc/runtime/operation.lock` (and `build.lock` for builds)
and writes durable `fsync` + atomic-rename journals under
`/var/db/tc/runtime/jobs/<op>.json`.

Job states:
- `pending`: queued and ready for claim.
- `running`: claimed under an active worker lease.
- `waiting`: candidate jail passed local health checks and is awaiting DNS and
  go-live confirmation.
- `succeeded`: verified terminal state.
- `failed`: terminal failure with prior slot/route safely restored.
- `uncertain`: retains its lock until an operator inspects host state and
  reconciles from the Jobs page using the exact same operation ID.

## Blue/Green FreeBSD jail lifecycle

Each application alternates between `blue` and `green` jail slots (`tc_<app>_blue`
and `tc_<app>_green`), keeping **one running jail steady-state** per app with
`php-fpm` configured in `pm = ondemand` mode:

1. **Allocate resources (`allocating-resources`)**: `Resources::apply()`
   validates ARC-aware memory headroom, overcommit budget, and `rctl` caps.
2. **Prepare database (`preparing-database`)**: Idempotently creates database
   and user `tc_<app_sql>` inside `tc_mariadb` (`MAX_USER_CONNECTIONS=20`,
   `max_statement_time=30`) when `database: true`.
3. **Prepare sessions (`preparing-sessions`)**: Idempotently configures Valkey
   ACL user `tc_<app>` scoped to `~tc:<app>:*` inside `tc_valkey`.
4. **Stage release (`staging-release`)**: Ensures ZFS datasets
   (`zroot/tc/apps/<app>` with quota, `zroot/tc/apps/<app>/data` with
   `exec=off,setuid=off`), clones `zroot/tc/skeleton@v1` (`quota=128M`) into the
   inactive slot (`zroot/tc/jails/<app>-<slot>`), writes `0440` read-only config
   overlays (`/app/config/config.php` via `Trongate_config` and
   `/app/config/database.php` when not overridden), writes
   `/etc/tc/php.d/extensions.ini` and `php-fpm.conf`, writes the `nullfs` fstab
   mounting RO FreeBSD base, RO PHP 8.4 runtime, RO release dir, RW data mounts,
   and RW socket parent directories (`/var/run/tc/{mariadb,valkey}` owned
   `root:wheel 0755`), and writes `/etc/jail.conf.d/tc_<app>_<slot>.conf` with
   `exec.poststart` `rctl` rules.
5. **Start standby (`starting-standby`)**: Registers the slot IP (`10.99.x.y` on
   `lo1`) in PF `<tc_jails>`, starts the standby jail with `jail -c`, and
   enforces `rctl` limits.
6. **Optional migration**: If `migration` is non-empty and
   `schema_compatible: true`, executes the argument array via `jexec` inside the
   standby jail. Rollback never undoes database or file changes; use
   expand/contract migrations.
7. **Local health check (`checking-local`)**: Probes the standby slot's Unix
   FastCGI socket (`/var/run/tc/sockets/<app>-<slot>/php-fpm.sock`) with
   `cgi-fcgi`. For a first deployment with `defer_public: true`, the operation
   pauses in `awaiting-go-live` until the user verifies DNS and triggers go-live.
8. **Atomic Caddy cutover (`switching`)**: Writes the app's explicit Caddy site
   block serving static assets directly from ZFS ARC (`root * .../releases/<op>/public`
   + `file_server`) and proxying PHP over the standby FastCGI socket, validates
   and reloads Caddy (automatic HTTPS), and re-verifies local health.
9. **Public HTTPS check (`checking-public`)**: Verifies HTTPS through the public
   domain (`curl`, 30 attempts). On failure for a replacement deploy, Caddy is
   restored to the previous slot.
10. **Retire previous (`retiring-previous`)**: Drains in-flight FastCGI
    connections and stops the previous slot jail while retaining its ZFS dataset
    and `/etc/jail.conf.d/` files for sub-second rollback (`rollback` simply
    restarts the retained slot, health-checks it, flips Caddy, and stops the
    replaced slot).

## Teardown and recovery

`destroy-app` removes the app's Caddy route and allowlist entry, stops and
destroys both `blue`/`green` jail datasets and `/etc/jail.conf.d/` configs,
removes `pf` and `rctl` entries, and deletes the Valkey session ACL user. By
default (`purge_data: false`), the MariaDB database and persistent ZFS dataset
(`zroot/tc/apps/<app>`) are retained for safe recovery; pass `purge_data: true`
to drop the database/user and destroy the ZFS dataset permanently.

The control plane may be offline while FreeBSD jails and host Caddy continue
serving accepted releases and persist across host reboots via `/etc/jail.conf.d/`
and `exec.poststart` `rctl` rules. Reconciliation is conservative: operator
review is required for uncertain migrations or failed route restoration. Inspect
journals under `/var/db/tc/runtime/jobs/` before reconciling. Do not delete lock
rows to make a dashboard turn green.
