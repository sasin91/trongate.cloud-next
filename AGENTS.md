# Product direction

- The product has two headline features: code deployments (DeployHQ-style workflow)
  and FreeBSD application hosting on bare-metal servers.
- Bare-metal FreeBSD (14.3+/15.x) with ZFS, vanilla `jail(8)` (`/etc/jail.conf.d/`),
  `nullfs`, `rctl(8)`, `pf` (non-VNET on `lo1` `10.99.0.0/16`), host Caddy edge proxy,
  and shared MariaDB (`tc_mariadb`) and Valkey (`tc_valkey`) platform jails is the
  sole runtime platform. Do not add Proxmox, NixOS, Podman, Docker/OCI, FrankenPHP,
  Bastille/CBSD/AppJail/pot, cloud-provider adapters, pricing catalogs, Hetzner
  support, or Kubernetes/k3s infrastructure.
- Preserve the original vision, feature specifications, and calm Mint/Trongate look
  and feel from `~/Herd/trongate.cloud`. Consult its `docs/ui-design.md`,
  `docs/platform-architecture.md`, and existing modules before changing
  customer-facing behavior. Replace the infrastructure implementation; do not
  silently discard the original product features.
- Keep implementations explicit and minimal. Introduce abstractions only for a
  demonstrated need. `modules/jobs/worker.php` runs the worker directly;
  `modules/setup/init.php` and `modules/setup/migrate.php` handle installation;
  `modules/monitoring/collect.php` collects metrics; product workflows belong in
  the panel.
- Browser-facing actions must enforce authentication and CSRF for mutations.
  Internal controller actions use the underscore convention enforced by
  `engine/Core.php` (or individual `block_url(...)` guards where required); never
  block an entire controller in its constructor.

# Host runtime & security boundary

- Transport uses pinned Ed25519 SSH to a non-root `tc-agent` user with an
  `authorized_keys` forced-command `/usr/local/bin/doas /usr/local/libexec/tc-runtime`
  (protocol v2 JSON over stdin/stdout, `flock`, `fsync` journals).
- Zero arbitrary shell execution from PHP. All host operations — including host
  inspection (`Ssh::inspect()` sending `{"action":"inspect-host"}`), builds,
  deployments, rollbacks, destruction, resource updates, metrics, status checks,
  and cleanup — pass through the structured `tc-runtime` JSON protocol.
- The control plane runs in its own `tc_panel` jail on Host #1 talking to
  `10.99.0.1:22` (`pf`-restricted to `tc_panel`) and future Host #2..N via the
  exact same SSH path.
- Application lifecycle uses spin-up-standby blue/green slots (`blue` and `green`)
  with a single running jail steady-state per app (`php-fpm` `pm = ondemand`) and
  the stopped previous slot retained on ZFS for sub-second rollback.
- Jails mount a single read-only `nullfs` FreeBSD base (`zroot/tc/bases/<ver>`)
  and read-only PHP runtime (`zroot/tc/runtimes/php84`). Each jail sets
  `PHP_INI_SCAN_DIR=/usr/local/etc/php-base:/etc/tc/php.d` and enables only
  requested curated optional extensions in `/etc/tc/php.d/extensions.ini`.
- Git builds run inside ephemeral `rctl`-bounded FreeBSD build jails producing
  immutable ZFS release directories (`zroot/tc/apps/<app>/releases/<op>`) with
  a SHA-256 manifest digest.
- Resource isolation uses overcommit-aware `rctl` hard caps (`memoryuse`,
  `swapuse`, `pcpu`, `maxproc`, `openfiles`) applied via `exec.poststart`,
  per-app ZFS dataset quotas (`exec=off,setuid=off` on persistent `data`), and
  ARC-aware FreeBSD memory accounting (`free + inactive + laundry + reclaimable_arc`).
- Shared `tc_mariadb` and `tc_valkey` platform jails expose Unix sockets under
  `/var/run/tc/{mariadb,valkey}` (`root:wheel` `0755` directory ownership so app
  jails can `connect(2)` without unlinking sockets).
- Host Caddy serves static assets directly from ZFS ARC, proxies PHP via Unix
  FastCGI sockets (`/var/run/tc/sockets/<app>-<slot>/php-fpm.sock`), and handles
  automatic HTTPS directly for each explicit site block in the Caddyfile.
- All bare-metal FreeBSD host code, configs, and scripts live under root `infra/`
  (`infra/runtime/`, `infra/config/`, `infra/scripts/`), keeping `modules/` pure
  Trongate control-plane MVC code.

# Code conventions

- Follow Trongate module conventions. Keep application code in its owning module,
  use module models for persistence and traits for shared model logic.
- Use K&R PHP style: opening braces on the same line, four-space indentation,
  and readable multiline method and control-flow bodies.
- Preserve `engine/Core.php` routing conventions. Protect internal actions with
  the underscore prefix or `block_url(...)`, and use the native model database
  object instead of database access traits.
- Controllers use `$this->model`. Load other modules through Trongate module
  loading; do not construct module models in application code or views. Pass
  prepared data into views. The loader shares database transactions with child
  modules.
- `Records` owns the shared `records` table through internal controller actions
  and its model. Use `Modules::run()` for independent calls and the
  transaction-sharing module loader for atomic feature writes. Keep JSON encoding
  helpers local to their owning classes rather than registering global helpers.
- Keep HTTP calls and workflow orchestration in controllers or HTTP clients.
  Models own persistence; do not move transport or key generation into model
  classes.
- HTTP transport is shared through the standalone `engine/Http_client.php` class.
  It owns TLS, connection deadlines and raw responses; modules own authentication,
  request encoding and API-specific errors. The client does not retry mutations.
  Controllers access the shared HTTP client through lazy `$this->http`, provided
  by `Trongate::__get` like `$this->model`. Test doubles implement `Http_client`.
- `Jobs` owns queue persistence, worker lifecycle, progress and recovery only.
  Dispatch job actions through `Modules::run()` with a `Job_context` containing
  identity, payload and heartbeat; keep clients and database dependencies in the
  owning module. Feature workflows belong to `Apps`, `Servers`, or `Deployments`;
  do not add a generic command dispatcher.
