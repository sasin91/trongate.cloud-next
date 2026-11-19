# Installation, updates and recovery

The supported installation target is an existing NixOS VM. See the root README
for the flake module and bootstrap. Use the same product source/lock on hosted and
customer installations. Nix pins both the Nix tooling (through nixpkgs) and deploy-rs.
The panel uses PHP-FPM and Caddy; application images retain FrankenPHP classic mode.
Do not enable both the control-plane Caddy and managed application proxy on the
same host: both own ports 80/443 and the local Caddy admin endpoint in this PoC.

The panel/worker OS account is `tc`. MariaDB uses local Unix-socket authentication
for this account; no password needs to appear in Nix. The panel's key and mutable
state live in `/var/lib/tc-control`, mode 0700. The administrator bootstrap input
should be a temporary 0600 file on `/run`, piped to `tc init`, then removed.

With a remote database, set `databaseDsn`, `localDatabase=false`, and an
`environmentFile` **string path**, such as `/run/secrets/tc-environment`. Its contents
are installed separately with owner/root-readable permissions, never copied into
the Nix store. Supply `TC_DB_USER` and `TC_DB_PASSWORD` there. Configure MariaDB
TLS/network restrictions for the deployment environment. Database encryption in
transit is an installation responsibility; the default Unix socket is local.

For separate workers set `worker=false` on the panel, and `panel=false` and
`localDatabase=false` on the worker VM. Both need the same database and installation
key. Transfer prepared revision directories to workers before enqueueing host
updates. Keep untrusted builders separate from both; builders must not receive
the installation key or provider/fleet credentials.

`TC_BASE_URL` controls the advertised origin and secure session cookie behavior.
Use HTTPS in a real installation. Session files are local to the panel; this PoC
supports one panel instance. Provider tokens and SSH/application credentials are
encrypted with the same installation-owned libsodium key. SSH known-host identities
live in server inventory, independent of system-global known_hosts.

## Prepared Linux target

Existing Linux targets may deploy applications without NixOS host management.
Prepare them explicitly before enrollment:

1. Install Podman, systemd, Caddy, Python 3, curl, util-linux (`flock`) and `which`.
   Use a host with cgroup memory/CPU limits enabled.
2. Install `runtime/tc-runtime.py` as root-owned `/usr/local/libexec/tc-runtime.py`
   and a root-owned executable `/usr/local/bin/tc-runtime` wrapper:

```sh
#!/bin/sh
exec /usr/bin/python3 /usr/local/libexec/tc-runtime.py "$@"
```

3. Install `runtime/tc-systemd-generator.sh` as root-owned executable
   `/etc/systemd/system-generators/tc-runtime`. It generates boot-persistent
   application units from `/var/lib/tc/runtime/units` and `enabled` markers;
   application deployments do not write into NixOS's immutable `/etc` unit tree.
   Create `/var/lib/tc/runtime` mode 0700, owned by root, and initialize its
   `Caddyfile` with `{ admin 127.0.0.1:2019 }` using multiline Caddyfile syntax:

```text
{
  admin 127.0.0.1:2019
}
```

4. Install a `tc-proxy.service` with
   `ExecStart=/usr/bin/caddy run --config /var/lib/tc/runtime/Caddyfile --adapter caddyfile`,
   `Restart=always` and a persistent `XDG_DATA_HOME=/var/lib/tc-caddy` environment.
   Enable/start it. It runs as root in this PoC, as do deployment operations.
5. Configure a dedicated SSH key for root, forbid password authentication, and
   keep console access. Ordinary unprivileged SSH accounts are inspected but
   explicitly marked not ready; privilege escalation is not silently attempted.
6. Open application ports 80/443 and the configured SSH port. Candidate ports
   bind only to 127.0.0.1. Do not expose Caddy's admin port.

These are preparation actions, not actions performed by SSH enrollment. Do not
install a second proxy over an existing production service using those ports.

## Updates without the panel

```sh
cd /opt/trongate-cloud-next
git pull --ff-only
sudo nixos-rebuild switch --flake /etc/nixos#YOUR_MACHINE
sudo -u tc env TC_STATE=/var/lib/tc-control tc migrate
sudo systemctl restart tc-worker
sudo journalctl -u tc-worker -u phpfpm-tc --since '10 minutes ago'
```

Review flake locks and changes before activating them. The SQL migration is
idempotent; future schema changes must add explicit migrations. To roll back the
control-plane installation, use `nixos-rebuild switch --rollback` from its console.
Application schema downgrades are not automatic.

## Backup and restore

Stop the worker while taking a consistent initial backup. Back up:

- A transactional MariaDB dump (inventory, encrypted credentials, operation locks,
  jobs, steps and local admin hashes).
- `/var/lib/tc-control/key` and all revision directories. Losing the key loses
  access to encrypted credentials, even if the database remains intact.
- Runtime environment files, dedicated SSH key recovery copies, registry config
  and this installation's pinned Nix source/configuration.
- Each target's `/var/lib/tc/runtime` (including persistent units/enabled markers) and
  `/var/lib/tc-caddy`, plus application databases and persistent mounts.
- Builder registry credentials if builds must resume after recovery.

Example on the control VM (outputs contain secrets and must be encrypted off-host):

```sh
sudo systemctl stop tc-worker
sudo sh -c 'umask 077; mariadb-dump --single-transaction tc > /root/tc.sql'
sudo tar -C / -czf /root/tc-state.tar.gz var/lib/tc-control etc/nixos
sudo systemctl start tc-worker
```

Restore the database and the **matching key** with the original permissions;
restore pinned source/revisions and runtime environment, then initialize services.
Do not run a fresh `tc init` with a different key over restored ciphertext.
Inspect uncertain jobs before enabling workers. Existing targets continue serving
while the control plane is restored. Keep provider console access for a host that
cannot be reached using its restored SSH key.

Do not copy a running application database's raw files as a backup. Coordinate its
own consistent backup with persistent file snapshots. Code rollback cannot recover
data corruption or an incompatible migration.

## Upgrading to native Trongate login

Back up the database, then run `tc migrate` using the new package before activating
the updated panel. Existing administrator names and password hashes are imported
into Trongate's login tables. Sign in again at `/login/login/1`; existing custom
sessions are not reused. Re-running the migration preserves native accounts.
`tc init` remains the explicit local password setup/reset command.

The web server connection flow uses trust on first use: it accepts and pins the
first observed SSH host key, then checks runtime readiness over that pinned
connection. Later SSH connections reject changed host keys. No manual fingerprint
entry is required in the web wizard; the CLI scan/confirm commands remain available
for independently verified enrollment.
