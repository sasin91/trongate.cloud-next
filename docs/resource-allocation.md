# Application resource allocation

The application host is authoritative. `/servers/details?server=web` reads live
container usage and limits, and offers automatic or manual allocation per app.
Every edit and deployment admission runs under the runtime's host operation lock.
The control plane does not calculate limits from stale monitoring samples.

## Budget

Subtract system, database and local-build reserves from physical capacity. Reserve
two copies of each manual app's allocation, then divide the remainder by twice
the number of automatic apps. Memory rounds down to whole MiB; CPU shares round
down to hundredths. Minimum per version: 256 MiB and 0.05 CPU shares. An allocation
that cannot satisfy those minimums is rejected rather than overcommitted.

Defaults in `nix/runtime.nix`:

| Reserve | Memory | CPU budgeting units |
| --- | ---: | ---: |
| OS and proxy | 512 MiB | 0.25 |
| Application databases | 512 MiB | 0.25 |
| Local builder, if enabled | 1536 MiB | 1 |

System/database memory reserves are configurable Nix options. The database reserve
is a capacity allowance, not automatic database provisioning or a database cgroup
limit. Operators must size it for the databases actually installed. Disabling local
builds frees its reserve; merely selecting a dedicated builder for one app does not.

Memory is a per-container hard limit. CPU values determine proportional weights
(`cpu-shares = allocation * 1024`), not guaranteed or capped core counts. Containers
can use spare CPU. Existing containers receive a quota equal to the physical host
CPU count when updated: the installed Podman silently ignores attempts to reset a
quota using zero or minus one. This permits full-host bursting; new containers are
created without a quota. CPU shares are not reserved physical cores.

The grid separates measured usage, currently enforced limits and the proposed
automatic allocation. Existing 256-MiB containers are not silently changed by
opening the page; Apply or the next deployment adopts the current policy.

## Deployment and recovery

Active, staged and otherwise still-running managed releases count toward admission.
An app cannot start a third simultaneous version. Retained stopped rollback
containers also receive new limits so rollback cannot revive obsolete allocations.
Podman reports a stopped container's last runtime spec until it starts again;
updates are persisted for the next start. The real-container regression test
checks those settings after stop/update/start, including kernel-enforced limits.
An interrupted creation is counted even if its release metadata was not written
yet. A removed historical container does not make resource inspection fail.
Pending apps with no running container receive their share when deployment starts.
Failed first deployments leave no durable app reservation once their containers
are stopped; the next allocation recalculates the remaining apps' shares.

Before any edits, all memory reductions are checked against current cgroup usage.
A reduction must leave 25% plus 32 MiB above measured usage. The check repeats
immediately before each reduction. This is a point-in-time guard, not a guarantee
against subsequent application memory spikes. Such spikes remain subject to the
app's memory limit. Host available memory must also cover the running containers'
remaining allowance, any starting candidate, and 128 MiB headroom.

Reductions run before increases. A durable `resources-pending.json` records original
limits and policies before the first Podman mutation. Failed changes restore those
limits. After interruption, the next mutation restores them before proceeding.
Recovery refuses to shrink a container if its usage has since grown too high;
the journal remains and further allocations block until usage falls/operator
reconciliation. Successful policy writes are persisted in `resources.json` on the
host. The grid reports interrupted updates and never invents successful limits.

The database and build reserves are not doubled: only application versions overlap.
Disk space is not divided using this formula. Builds retain their existing disk
preflight and cache cleanup; persistent storage quotas are a separate feature.

## Verification

`tests/resources_test.php` covers two-slot arithmetic, manual/automatic mixing,
boundary rejection, live-use guards, retained rollback containers, partial failures
and interrupted-update recovery. `php tests/resource_podman.php LOCAL_IMAGE_ID` explicitly
tests disposable real containers, including the effective kernel memory and CPU
limits. It never uses the live runtime directory. The NixOS VM test also exercises
resources through the runtime alongside deploy/rollback.
