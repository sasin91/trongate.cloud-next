> Historical record: this describes the initial repository inspection from
> 2026-11-17 before the infrastructure was replaced with bare-metal FreeBSD,
> ZFS, vanilla `jail(8)`, `rctl(8)`, `pf`, host Caddy, and shared MariaDB/Valkey
> platform jails. See [README.md](../README.md),
> [architecture.md](architecture.md), and [infra/README.md](../infra/README.md)
> for the current architecture.

# Existing project inspection

Inspected 2026-11-17, before implementation:

| Repository | Inspected commit | Evidence |
|---|---|---|
| `sasin91/trongate.cloud` | `715699cf6c314cdf26e994413316992e381eae7f` | Cloned to a separate local checkout; inspected engine, encryption, deployment worker/task, build generator, UI design docs, tests, and `docs/agents.md` |
| `sasin91/trongate-cloud-infra` | `666b88d2deab3d322309e4a00bead723eb537f6b` | Existing clean checkout; inspected license and platform design notes |

## Reused vs replaced

- **Preserved from `~/Herd/trongate.cloud`**: Trongate dispatcher/base
  controller, framework-independent `Encryptor`, `Build_commands` and its ten
  behavior tests, guided app-creation wizard, calm Mint/Trongate UI design, and
  core product scope (DeployHQ-style code deployments and managed PHP hosting).
- **Replaced infrastructure**: The original Kubernetes/container/FrankenPHP
  layer and intermediate Linux PoC experiments were replaced completely with
  bare-metal FreeBSD (14.3+/15.x), ZFS, vanilla `jail(8)` under
  `/etc/jail.conf.d/`, read-only `nullfs` base and PHP 8.4 runtime mounts,
  `rctl(8)` hard caps, non-VNET `pf` isolation on `lo1` (`10.99.0.0/16`),
  shared `tc_mariadb` and `tc_valkey` platform jails, host Caddy with
  automatic HTTPS, and pinned Ed25519 SSH forced-command `doas`
  `tc-runtime` protocol v2.
- **Queue & recovery model**: The small PDO worker retains the straightforward
  task-processing approach while adding sticky `server:<id>` resource locks,
  worker lease fencing, and host-side `flock` + `fsync` journals.

## License review

`composer.json` marks the application proprietary; `engine/LICENSE.txt` carries
the upstream Trongate MIT notice. This repository preserves source provenance
and the upstream Trongate MIT notice.
