# Verification Evidence and Limits

## Automated test layers

Tests use plain PHP-internals-style `.phpt` files colocated with their owning
components (`engine/tests/`, `modules/*/tests/`, `infra/runtime/tests/`, and
root `tests/`), driven by `Makefile` and `tests/run-tests.php`:

```sh
make check
php tests/run-tests.php
TC_DSN='mysql:host=127.0.0.1;dbname=tc' TC_DB_USER=tc make test-mariadb
```

What is covered by automated `.phpt` tests:
- **Syntax checks (`make lint`)**: `php -l` across `engine/`,
  `infra/runtime/`, `modules/`, `public/`, and `tests/`.
- **Engine & module boundaries** (`engine/tests/http_client.phpt`,
  `engine/tests/modules_and_db.phpt`): Shared `Http_client` validation, GitHub
  integration, native PDO binding, SQLite lock release, nested module
  transactions, and `Records` CRUD.
- **Owning module suites** (`modules/apps/tests/`, `modules/jobs/tests/`,
  `modules/servers/tests/`, `modules/setup/tests/`,
  `modules/security/tests/`, `modules/validation/tests/`):
  `Build_commands`, `Build::plan()`, `Trongate_config::patch_config_php()`,
  queue claims/leases/fencing, `Job_context` + `Worker` dispatch, `Ssh::inspect`
  protocol v2, CLI entry points, libsodium encryption & redaction, and
  `Validate::release()`.
- **FreeBSD host runtime, builder, resources & metrics**
  (`infra/runtime/tests/*.phpt`): Protocol v2 `inspect-host`, blue/green slot
  staging, `nullfs` fstab & `/etc/jail.conf.d/`, `rctl` `exec.poststart`,
  `tc_mariadb` & `tc_valkey` provisioning, Caddyfile updates, in-place
  `config/config.php` patching, `boot` active-jail startup, rollback, soft vs
  `purge_data` `destroy-app`, cleanup, `pf` + `rctl`-bounded build jails,
  ARC-aware 32 GiB overcommit math, shrink guards, recovery, and `Metrics`.
- **Cross-module integration** (`tests/flows.phpt`, `tests/web.phpt`,
  `tests/mariadb.phpt`): End-to-end Git launch & monitoring flows, real HTTP
  server login/CSRF/MX/asset/colocated-`.phpt`-blocking checks, and real MariaDB
  4-process serialization (`--SKIPIF--` when `TC_DSN` is not `mysql:`).
- **POSIX shell checks**: `sh -n` syntax validation and pure LF line-ending
  verification on `infra/scripts/bootstrap.sh`, `infra/scripts/build-runtime.sh`,
  and `infra/scripts/platform-jails.sh`.

## Limits of local fixture tests

Mocked `Runtime`, `Builder`, `Resources`, and `Metrics` tests run on Windows,
Linux, and FreeBSD by overriding `run()` and system-reader helpers. They verify
control flow, JSON protocol contracts, generated configs, and recovery logic,
but do **not** execute real FreeBSD kernel subsystems.

Full kernel-level verification requires a real bare-metal FreeBSD 14.3+/15.x
host with ZFS and `kern.racct.enable="1"` following the post-install smoke-test
checklist in [infra/README.md](../infra/README.md):
- Forced-command SSH + `doas` `tc-runtime` protocol v2 boundary
- Real `jail(8)`, `nullfs`, `rctl(8)` hard caps, and ZFS quotas/`exec=off,setuid=off`
- PF jail-to-jail and RFC1918/link-local SSRF isolation
- Host Caddy static serving from ZFS ARC, FastCGI proxy, and automatic HTTPS
- Valkey session persistence across Blue/Green deploys and sub-second rollback
