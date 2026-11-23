# Operation boundaries and recovery

## Trongate modules and routing

Core routes directly to `apps`, `servers`, `deployments`, and
`jobs`. Each public feature entry point calls
`$this->trongate_security->make_sure_allowed()` before handling its own request.
There is no console command dispatcher or custom authentication module.
`templates` composes the shared layout and module-owned views. Apps, servers, deployments and jobs have dedicated pages. Server creation keeps
its configuration, review and operation ID in the session. App setup saves an
encrypted session draft before navigation and restores the current question
when returning. Tokens and SSH private keys are never stored in localStorage.

The `login`, `trongate_tokens`, form, validation and supporting framework modules
come from the existing Trongate installation. `login/config/login.php` maps the
administrator level to `trongate_administrators`. Native password validation,
rate limiting and token issuance handle sign-in; the application security scenario
requires level 1 and validates `csrf_token` for mutations. Login uses the native
validation CSRF check. Logout requires POST and CSRF. Remember-me and password
reset are disabled for this installation. Sessions rotate on login and tokens
retain the previous one-hour lifetime.

`php /run/current-system/sw/share/tc/modules/setup/migrate.php` creates the native login tables and copies legacy administrator
password hashes once per username. Repeated migrations do not overwrite native
accounts; `php /run/current-system/sw/share/tc/modules/setup/init.php` remains the explicit local administrator password setup path.
Old custom sessions no longer authorize requests. Migration is additive, so the
legacy tables remain available for rollback. Back up the database before upgrading.

The native `Db` module uses the control plane's configured PDO connection; its
table-existence check also supports SQLite test fixtures. Other framework code
is kept in the bundled engine/modules. JSON encoding helpers are private to their
owning classes. They return strings and preserve throwing on invalid JSON and
unescaped slashes; Trongate's `json()` helper is for displaying data.

Internal methods use the underscore convention enforced by Core. Feature assets
use normal `_module` URLs and Core's asset checks. Shared styles live in
`templates/css`. Launch and Proxmox persistence lives in `Apps_model` and `Proxmox_model`,
accessed through Trongate's `$this->model`. Records owns shared JSON record storage;
credential encryption and queue access use traits. `Jobs_model` owns atomic queue transitions;
module loading shares the calling model's PDO connection so launch writes and
job creation commit or roll back together. CLI and workers load the same models.
Supporting classes live under their owning modules in `services/`: app builds,
provider HTTP, server SSH, deployment backends, security and jobs. Shared
web/CLI initialization lives in `bootstrap.php`. Service files
are loaded explicitly and are not module controllers.

The canonical MariaDB schema is root `setup.sql`; SQLite tests use
`modules/setup/fixtures/setup.sqlite.sql`. The CLI-only `Setup_model` applies these
SQL files and asks `Login_model` to import legacy administrator hashes. There is
no browser setup controller or schema creation during ordinary requests.

## Module ownership

Jobs owns enqueueing, claims, leases, resource locks, progress, terminal results
and explicit reconciliation. Its model has no inventory or credential access.
Worker handles that lifecycle and calls Modules::run() with a Job_context containing only the job ID, kind, payload and lease-renewal callback. Feature actions use their own model connections. The worker commits its claim before dispatch; feature-local transactions remain within their owning modules.
The Jobs page is history and recovery, not a generic command console.

Apps owns app/release persistence, build planning and execution, and the
build-to-deployment handoff. Servers owns SSH scanning, confirmation and inspection.
Deployments owns application rollout, rollback, NixOS preparation, activation and
host recovery. Proxmox owns VM provisioning and automatic enrollment.
Controllers orchestrate work and call their own models for persistence.

The root bootstrap.php supplies application startup and class loading.
engine/Process.php runs bounded subprocesses. Records owns all access to the
records table through internal controller actions and its model. Independent
calls can use Modules::run(); calls that participate in a transaction use the
native module loader to share the caller's connection. Insert rejects duplicate
keys; put replaces an existing record. Product decisions remain in feature modules.
Encrypted_credentials belongs to
trongate_security/traits. Job_queue is a thin native-module forwarding trait.
Setup owns initialization and migrations; Monitoring owns collection.
modules/setup/init.php initializes the installation, modules/setup/migrate.php applies migrations, and modules/monitoring/collect.php collects metrics. modules/jobs/worker.php starts the worker directly, with optional --once.

Existing database tables, job kinds, resource locks and remote operation IDs
are preserved by the operations-to-Jobs rename; no data migration is required.

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
`uncertain` retains its lock. `the Jobs reconciliation action` changes uncertain to pending and
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

The host-update operation prepares and builds the NixOS configuration inside the deployments module before activation. No separate preparation script is required. Installation administrators supply a nixos object in the server inventory:

```json
{"nixos":{"hostname":"web","system":"x86_64-linux","hardware_file":"/secure/web-hardware.nix"}}
```

The hardware file is **trusted local administrator code**, including boot/filesystem,
networking and dedicated authorized SSH public keys. It must be self-contained
(imports through pinned `modulesPath` are fine) and contain no secrets.
For an existing host, include its existing `system.stateVersion`; the generated
module supplies only a default and must not silently advance stateful service defaults.
Do not reuse the control-plane hardware module for application targets. JSON
inventory settings are imported with `builtins.fromJSON`; web input cannot inject
Nix expressions. Configuration content determines the revision ID. The revision
directory preserves the source modules, lock, hardware input, generated flake,
inventory and built expected store path. The worker saves the prepared revision before activation and reuses it on retries. Existing frozen revisions may still be supplied explicitly. Hardware paths and Nix source come from installation-owned inventory, not request fields.

```sh
# POST to /deployments/host with a valid session and CSRF token; action=host-update:
# {"server":"web"}
# POST to /deployments/host; action=host-rollback:
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
# Use Recover host update on the Jobs page, or POST /deployments/host with action=host-reconcile:
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
