# Module boundaries

This codebase follows Trongate module conventions with explicit, minimal
implementations and strict ownership boundaries.

## Core modules

- **Records** (`modules/records/`) owns the shared JSON `records` table. Its
  internal `_get`, `_find`, `_all`, `_insert`, `_put`, and `_delete` actions
  delegate persistence to `Records_model`. Use `Modules::run()` for independent
  calls, or Trongate's native module loader when writes must share a database
  transaction with job or credential mutations.
- **Jobs** (`modules/jobs/`) owns queue persistence, atomic claims, leases,
  resource locks, step logging, reconciliation, and the worker loop
  (`modules/jobs/worker.php`). `Worker` dispatches `build` -> `apps/_build`,
  `deploy` / `rollback` -> `deployments/_run`, and `destroy-app` ->
  `deployments/_destroy` via `Modules::run()` with a `Job_context` (never
  passing DB connections, HTTP clients, or SSH clients across the boundary).
- **Apps** (`modules/apps/`) owns app and release persistence, the launch
  wizard, `Build`, `Build_commands`, `Trongate_config`, build execution
  (`_build`), and build-to-deployment handoff.
- **Servers** (`modules/servers/`) owns FreeBSD host enrollment, Ed25519 host
  key scanning/pinning, structured protocol v2 host readiness inspection
  (`Ssh::inspect()` -> `{"action":"inspect-host"}`), and live `rctl`/ZFS
  resource controls.
- **Deployments** (`modules/deployments/`) owns application rollout (`_run`),
  rollback (`_run`), app teardown (`_destroy`, `_queue_destroy`), DNS go-live
  checks (`_go_live`), and deployment timeline/progress views.
- **Monitoring** (`modules/monitoring/`) owns periodic ARC-aware FreeBSD host
  metric collection (`modules/monitoring/collect.php` -> `monitoring/_collect`)
  and capacity pressure summaries (`Monitoring_model::_summary()`).
- **Setup** (`modules/setup/`) owns CLI-only installation bootstrap
  (`modules/setup/init.php`) and idempotent schema migrations
  (`modules/setup/migrate.php`).
- **Templates** (`modules/templates/`) owns the shared Mint/Trongate layout
  shell (`views/panel.php`) and MX/CSRF helpers.
- **Validation** (`modules/validation/`) owns `Validate` input and release
  schema checks (`Validate::release()`, `Validate::server()`,
  `Validate::php_version()`, `Validate::release_digest()`).

## Host infrastructure layer (`infra/`)

All bare-metal FreeBSD host code, configs, and provisioning scripts live under
root `infra/` (separate from the `modules/` Trongate control plane):

- **`infra/scripts/`**: `bootstrap.sh`, `build-runtime.sh`, `platform-jails.sh`.
- **`infra/config/`**: Extracted host configs and FreeBSD rc.d scripts
  (`pf.conf`, `doas.conf`, `php-base.ini`, `jail-rc.conf`, `mariadb.cnf.in`,
  `valkey.conf.in`, `caddyfile.base`, `tc_apps.rc`, `tc_panel_fpm.rc`,
  `tc_worker.rc`, `tc_monitor.rc`).
- **`infra/runtime/`**: Framework-free PHP 8.4 host runtime installed to
  `/usr/local/libexec/tc/` and invoked exclusively via forced-command SSH +
  `doas /usr/local/libexec/tc-runtime`:
  - `infra/runtime/runtime.php` (`Runtime`, protocol v2 JSON dispatcher)
  - `infra/runtime/builder.php` (`Builder`, ephemeral FreeBSD build jails)
  - `infra/runtime/resources.php` (`Resources`, ARC-aware `rctl` + ZFS budgeting)
  - `infra/runtime/metrics.php` (`Metrics`, FreeBSD `sysctl`/`zfs`/`rctl` sampler)

## Shared infrastructure & conventions

- **HTTP transport** is shared through standalone `engine/Http_client.php`
  (accessed lazily via `$this->http`). It owns TLS, timeouts, and raw
  responses; modules own authentication, encoding, and error mapping.
- **Subprocess execution** lives in `engine/Process.php`.
- **Startup** uses `engine/ignition.php` for web requests and
  `engine/cli_bootstrap.php` for direct CLI entry points.
- **Direct CLI entry points**: Services invoke `modules/jobs/worker.php`,
  `modules/setup/init.php`, `modules/setup/migrate.php`, and
  `modules/monitoring/collect.php` directly with PHP; there is no generic
  command dispatcher.
- **JSON encoding**: Keep private `_encode_json()` helpers inside their owning
  classes rather than registering global helpers.
- **Access control**: Browser-facing actions enforce authentication and CSRF on
  mutations. Internal actions use the underscore prefix enforced by
  `engine/Core.php` (or individual `block_url(...)` guards where required);
  never block an entire controller in its constructor.
