# Builds, Release Cleanup, and Capacity Monitoring

## Local FreeBSD build jails and release retention

Builds run on the target FreeBSD application host inside ephemeral `rctl(8)`-
bounded build jails cloned from `zroot/tc/runtimes/builder@v1` (see
[docs/builds.md](builds.md)). No external image registry or container daemon is
required, and the running application jail is undisturbed during builds.

Each build produces an immutable ZFS release directory under
`zroot/tc/apps/<app>/releases/<op>` addressed by its SHA-256 manifest digest
(`sha256:<64hex>`).

Periodic release cleanup (`{"action":"cleanup"}` via `tc-runtime`) removes
retired releases older than 24 hours while always retaining:
- the currently active slot release,
- the previous rollback slot release,
- any staged deployment awaiting go-live,
- persistent application data datasets (`zroot/tc/apps/<app>/data`).

Cleanup pauses safely if an interrupted resource update
(`resources-pending.json`) needs recovery first.

## ARC-aware FreeBSD monitoring

Capacity monitoring follows the original Trongate Cloud overview: observed CPU
and memory usage, capacity, and pressure hints, extended with ZFS pool space,
ZFS ARC size/reclaimable bytes, 1-minute load average, active app count, and
enforced `rctl` app limits.

Metrics are collected over the existing pinned Ed25519 SSH forced-command
channel (`{"action":"metrics"}` -> `infra/runtime/metrics.php`), with no public
metrics port exposed:

- **CPU & load**: 1-second `kern.cp_time` delta and `vm.loadavg` via `sysctl(8)`.
- **ARC-aware memory**: Reads `hw.physmem`, `vm.stats.vm.*`, and
  `kstat.zfs.misc.arcstats.{size,c_min}` so reclaimable ZFS ARC cache is not
  misreported as unavailable RAM. Reports `memory_total`, `memory_used`,
  `arc_size`, and `arc_reclaimable`.
- **ZFS pool space**: Reads `zfs list -Hp -o used,avail <pool>` for
  `disk_total`, `disk_used`, and `disk_free`.
- **Per-jail usage**: Sums live `rctl -u` and `rctl jail:<name>` readings across
  running customer jails via `jls -N --libxo json` (excluding platform and build
  jails).

`php modules/monitoring/collect.php` runs periodically via the `tc_monitor`
service inside `tc_panel`. Each server retains up to 288 samples (~24 hours) in
`records` (`kind = 'server-metrics'`). Missing or stale readings (>15 minutes
old) are displayed as unavailable rather than zero.

Pressure hints:
- Three consecutive high samples spanning at least 500 seconds trigger CPU,
  memory, or ZFS pool disk hints at 85%.
- Less than 5 GiB free on the ZFS pool produces an immediate build-space hint.
- Monitoring never autoscales or mutates infrastructure (`autoscaling: false`).

## Go live

A first deployment builds, provisions MariaDB/Valkey, starts the standby jail,
and passes local `cgi-fcgi` checks before entering `waiting` (`awaiting-go-live`).
The deployment timeline presents the target server's `A`/`AAAA` DNS records and
a **Check DNS and go live** action.

- If DNS has not propagated yet, `Deployments_model::_go_live()` rejects the
  attempt without losing the staged deployment.
- Once DNS matches, the job transitions back to `pending`, Caddy switches the
  explicit site block for automatic HTTPS, and `tc-runtime` verifies public
  HTTPS (up to 30 attempts).
- A replacement deployment automatically restores the previous jail slot and
  Caddy route if public verification fails.
