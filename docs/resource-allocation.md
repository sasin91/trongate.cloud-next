# Application resource allocation

The FreeBSD application host is authoritative. `/servers/details?server=<id>`
reads live `rctl(8)` usage, `rctl(8)` hard limits, ZFS dataset usage/quotas, and
ARC-aware host memory via `tc-runtime` (`{"action":"resources"}`), and offers
automatic or manual allocation per app (`{"action":"resources-update",...}`).
Every edit and deployment admission runs under `/var/db/tc/runtime/operation.lock`.
The control plane never calculates limits from stale monitoring samples.

## ARC-aware host budget and overcommit

FreeBSD caches ZFS blocks aggressively in the Adaptive Replacement Cache (ARC),
so raw "free" memory understates available capacity. `Resources::host()`
(`infra/runtime/resources.php`) computes host available memory as:

```text
available_mb = free + inactive + laundry + reclaimable_arc
reclaimable_arc = max(0, kstat.zfs.misc.arcstats.size - kstat.zfs.misc.arcstats.c_min)
```

System and platform-jail reserves are read from `/etc/tc-resources.json` (auto-
scaled by `infra/scripts/bootstrap.sh` on hosts under 32 GiB RAM):

| Reserve | 32 GiB+ default | Purpose |
| --- | ---: | --- |
| `system_memory_mb` | 2048 MiB | FreeBSD kernel, host Caddy, `tc_panel` |
| `database_memory_mb` | 2048 MiB | Shared `tc_mariadb` jail |
| `valkey_memory_mb` | 512 MiB | Shared `tc_valkey` session jail |
| `build_memory_mb` | 1536 MiB | Ephemeral FreeBSD build jail headroom |
| `memory_overcommit_ratio` | 3.0 | Effective budget multiplier for `php-fpm` `pm = ondemand` jails |

With a single steady-state running jail per app (`pm = ondemand`) and standby
blue/green spin-up only during deploys, a 32 GiB bare-metal FreeBSD host
supports 100–250+ low-traffic Trongate applications:

- Base app memory budget:
  `memory_budget_mb = max(0, physical_mb - system - database - valkey - build)`
- Effective overcommit budget:
  `effective_memory_budget_mb = floor(memory_budget_mb * memory_overcommit_ratio)`
- Manual apps reserve their configured `memory_mb` (plus standby overlap for the
  largest deployable slot). Automatic apps share the remaining effective budget
  evenly, clamped between `MIN_MEMORY = 64` MiB and `MAX_AUTO_MEMORY = 512` MiB
  (default `128` MiB).

## Enforced limits per jail

Each application jail is bounded by `rctl(8)` hard caps (persisted in
`/etc/jail.conf.d/tc_<app>_<slot>.conf` via `exec.poststart` and updated live
with `rctl -a`) plus a ZFS dataset quota on `zroot/tc/apps/<app>`:

| Limit | Default | Allowed range | Enforcement |
| --- | ---: | --- | --- |
| Memory (`memoryuse`) | 128 MiB | 64–32768 MiB | `rctl -a jail:<name>:memoryuse:deny=<N>M` |
| Swap (`swapuse`) | `memoryuse` | `memoryuse` | `rctl -a jail:<name>:swapuse:deny=<N>M` |
| CPU (`pcpu`) | 100% | 10–1600% | `rctl -a jail:<name>:pcpu:deny=<N>` |
| Processes (`maxproc`) | 64 | 64 | `rctl -a jail:<name>:maxproc:deny=64` |
| Open files (`openfiles`) | 1024 | 1024 | `rctl -a jail:<name>:openfiles:deny=1024` |
| Disk quota | 1024 MiB | 128–1048576 MiB | `zfs set quota=<N>M zroot/tc/apps/<app>` |

Platform jails (`tc_mariadb`, `tc_valkey`, `tc_panel`) and ephemeral build jails
(`tc_build_*`) are excluded from customer app allocation and carry their own
dedicated `rctl` rules configured by `infra/scripts/platform-jails.sh` and
`infra/runtime/builder.php`.

## Admission, live-use guards, and recovery

- **Live shrink guard**: Before reducing a running jail's `memoryuse` cap,
  `Resources` checks `rctl -u jail:<name>` and requires at least **25% + 16 MiB**
  of headroom above measured resident memory. The check repeats immediately
  before each reduction.
- **Host pressure guard**: When starting a standby candidate jail or applying
  resource edits, host ARC-aware `available_mb` must retain at least **256 MiB**
  (`AVAILABLE_HEADROOM_MB`) of real headroom.
- **Standby & retained slot consistency**: Updating an app's limits updates its
  live jail via `rctl -a`, rewrites `/etc/jail.conf.d/` so stopped rollback
  slots and host reboots inherit the new caps, and applies `zfs set quota` on
  `zroot/tc/apps/<app>`.
- **Partial-failure rollback (`resources-pending.json`)**: Reductions run before
  increases. Before the first `rctl`/`zfs` mutation, `Resources` writes original
  limits and policies to `/var/db/tc/runtime/resources-pending.json`. If a
  mutation fails or is interrupted, the next call restores prior limits before
  proceeding (refusing to shrink a jail whose live usage has grown past the safe
  threshold until usage drops or an operator reconciles). Successful policies
  persist in `/var/db/tc/runtime/resources.json`.

## Verification

`infra/runtime/tests/resources_and_metrics.phpt` covers ARC-aware host budgeting, 100/250-app 32 GiB
overcommit math, manual/automatic mixing, boundary rejection, `25% + 16 MiB`
shrink guards, `256 MiB` host headroom checks, `rctl`/`zfs` mutation sequences,
platform-jail exclusion, and `resources-pending.json` partial-failure recovery.
