# Operation boundaries and recovery

`Control` validates operator intent and writes inventory/queue records.
`Store` owns MariaDB state and SQL queue transitions. `Worker` orchestrates actual
operations. `Hetzner` owns provider HTTP resources/actions. `Ssh` owns host identity,
credential materialization and fixed remote entry points. `HostDeployer` is the
only host-backend interface; `DeployRs` implements it. The small Python host helper
implements Podman/systemd/Caddy transactions and persistent host fencing. PHP
remains the control plane and worker; Python is used for Linux file locking and
argument-array process execution on target hosts.

The single installation administrator can operate all inventory. Web entry points
require a local password session and CSRF token; the CLI requires OS access to
the installation key and database. There is no unattended provider provisioning
or destructive mutation without a queued, explicitly confirmed operator request.

## Queue and fencing

Jobs use random stable operation IDs, atomic conditional claims, 90-second leases,
ten-second subprocess heartbeats, at most 30 claims, and per-command deadlines.
A resource lock belongs to an operation, not a worker. Expiring a worker lease
does not release that lock. A replacement worker can claim only the same operation.
All host/app changes for an enrolled server share its `server:ID` lock. Provider
requests are serialized by connection. The remote helper also uses `flock`, a
journal and a persistent host-deployment reservation.

`pending` means queued or waiting on a provider action; `running` has a worker
lease; `succeeded` is verified; `failed` is a terminal failure with safe recovery;
`uncertain` retains its lock. `tc reconcile` changes uncertain to pending and
repeats observation using the same operation ID. It never creates a fresh ID.

Every provider mutation gets a durable intent before HTTP. Creates carry
`tc-operation` labels. A timeout after submission searches those labels before
any decision. If nothing is visible, it stays uncertain and **does not send another
create**. This can require manual resolution when a request was never accepted;
avoiding a duplicate billable resource takes precedence over automatic progress.

## Application operations

An immutable release includes digest, optional commit, configuration version,
environment/config-file secret references, domain, health path, persistent mounts,
resource limits and optional migration argument array. A separate container and
unit are created for each operation. Filesystem journals precede migrations and
route switches; writes use fsync and atomic rename. Repeating a completed operation
returns its journal. An interrupted candidate/switch attempts to restore the
recorded previous route; a migration with an unknown exit status remains uncertain.

Migration commands run with `podman exec` argument arrays. They must explicitly
declare `schema_compatible:true`; rollback does not undo database or file changes.
Use expand/contract migrations compatible with both retained versions. Health
endpoints are application-defined; a 2xx/3xx response cannot prove business-level
correctness or schema compatibility. Old containers/configs are retained and
consume space; automatic pruning is intentionally absent.

The control plane may be offline while systemd keeps applications and Caddy
serving their accepted releases. Reconciliation is conservative: operator review
is required for unknown migrations, failed restoration, provider mutations whose
action IDs were lost, and incomplete host activations. Inspect journals under
`/var/lib/tc/runtime/operations` and provider action history before resolving such
events. Do not delete lock rows to make a dashboard turn green.

## Managed host operations

`php bin/prepare-host.php host.json` runs locally on the worker installation. Input:

```json
{"server":"web","hostname":"web","system":"x86_64-linux","hardware_file":"/secure/web-hardware.nix"}
```

The hardware file is **trusted local administrator code**, including boot/filesystem,
networking and dedicated authorized SSH public keys. It must be self-contained
(imports through pinned `modulesPath` are fine) and contain no secrets.
Do not reuse the control-plane hardware module for application targets. JSON
inventory settings are imported with `builtins.fromJSON`; web input cannot inject
Nix expressions. Configuration content determines the revision ID. The revision
directory preserves the source modules, lock, hardware input, generated flake,
inventory and built expected store path. Failed preparation can be resumed.

```sh
TC_STATE=/var/lib/tc-control php bin/prepare-host.php host.json
tc host-update update.json
# {"server":"web","revision":"SHA256 FROM PREPARATION"}
tc host-rollback rollback-host.json
# {"server":"web","generation":"/nix/store/RETAINED-nixos-system-..."}
```

The backend reserves the remote host, roots its previous generation, then invokes
the bundled pinned deploy-rs with strict SSH checking, activation rollback and
connectivity confirmation. Success additionally requires the expected observed
`/run/current-system`, sshd/proxy health and each active application's local health.
Rollback accepts only a retained product root, activates it, and observes it before
success. Desired revision, intended generation, previous generation, result and
observed generation remain separate from app releases.

Automatic GC is disabled on managed hosts; explicit roots protect prior
generations. Do not remove roots while their releases/operations are retained.
An SSH interruption does not prove deploy-rs stopped. A repeated activation with
an existing reservation stays uncertain instead of racing it. If the original
finishes, its terminal journal lets reconciliation observe success.

If the worker was lost before recording a terminal result, stop **all** installation
workers first, including detached/manual workers, and use:

```sh
tc host-reconcile recovery.json
# {"operation":"HOST OPERATION ID","confirm_workers_stopped":true}
```

The backend refuses recovery while a remote activation process exists. It compares
the current healthy generation with the reserved intended and previous generations:
intended becomes succeeded; verified previous becomes failed/restored; anything else
remains uncertain. Only a verified terminal result releases the database/host fence.
Restart workers after reconciliation. The explicit confirmation is an operator
assertion that no old worker can resume a delayed activation.

Failed reboots can require provider rescue/console access. Activation rollback
cannot undo application data, migrations, external APIs, firmware or disk layout
changes. This PoC performs live configuration activation, not unattended reboot
or OS conversion. A future backend must preserve those outcome distinctions.
