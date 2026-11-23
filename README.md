# Trongate Cloud

Code deployments and NixOS server provisioning on Proxmox. This continues the
original Trongate Cloud product in ~/Herd/trongate.cloud: its feature specifications,
guided workflows, and calm, structured interface remain the reference. Proxmox and
NixOS replace the old Kubernetes infrastructure; other cloud providers are out of scope.

The current implementation includes application builds, releases, deployment
progress, rollback, server resources, and Proxmox VM creation with automatic SSH
enrollment. It is still being developed; this is not a claim of full feature parity
with the original product.

Application licensing follows the original proprietary
application; upstream Trongate MIT notices are preserved in `engine/LICENSE.txt`.

## Install on an existing NixOS control-plane VM

Clone this repository. No request to a hosted
Trongate control plane is required. A single control-plane VM runs MariaDB,
PHP-FPM, Caddy and the worker. Application targets and untrusted builders are
separate machines in this PoC.

```sh
git clone git@github.com:sasin91/trongate.cloud-next.git /opt/trongate-cloud-next
cd /opt/trongate-cloud-next
nix flake check --no-build
nix build .#default .#runtime
```

Add a local path input to your existing `/etc/nixos/flake.nix`, preserving its
hardware module, administrator keys, bootloader and filesystem configuration:

```nix
inputs.tc.url = "path:/opt/trongate-cloud-next";
# Add tc to the outputs argument, then to your machine's modules:
tc.nixosModules.control-plane
{
  services.trongate-cloud = {
    enable = true;
    domain = "control.example.com";
  };
}
```

```sh
sudo nixos-rebuild switch --flake /etc/nixos#YOUR_MACHINE
# Create /run/tc-admin.json with mode 0600 using an editor:
# {"name":"admin","password":"a unique password of at least 14 characters"}
sudo sh -c 'cat /run/tc-admin.json | sudo -u tc env TC_STATE=/var/lib/tc-control php /run/current-system/sw/share/tc/modules/setup/init.php'
sudo rm /run/tc-admin.json
sudo systemctl restart tc-worker
```

Point the domain at the VM and open HTTPS. `php /run/current-system/sw/share/tc/modules/setup/init.php` creates the installation
encryption key and schema before the panel is usable. It can also reset a local
administrator password during recovery. Back up the key immediately.

Each module owns its command-line entry point. From a checkout, run:

    php modules/setup/init.php
    php modules/setup/migrate.php
    php modules/jobs/worker.php --once
    php modules/monitoring/collect.php

On an installed NixOS system, these paths are relative to
`/run/current-system/sw/share/tc/`.
The worker service and monitoring timer call their respective scripts directly. Run manual commands as
the installation OS user with the same database and TC_STATE environment.
Product operations run through the authenticated panel.

See [self-hosting](docs/self-hosting.md) for installation and recovery.

## Deploy code and provision servers

1. Open **Servers → Create on Proxmox**. The installation supplies the Proxmox
   connection and prepared NixOS template. Review capacity and create a VM.
   Provisioning configures SSH and enrolls the server automatically after verifying
   its identity and runtime readiness. See [Proxmox setup](docs/proxmox.md).
2. Open **Apps → Create app**, choose the repository or image and target server,
   and review build commands, configuration and domain.
3. Follow deployment progress, connect the domain, and go live. Retained releases
   support rollback without rebuilding.

The long-running worker executes queued operations. For a one-shot test:
`php /run/current-system/sw/share/tc/modules/jobs/worker.php --once`. A missing runtime helper, privilege, tool or proxy service is
reported explicitly during inspection. Enrollment never installs or replaces an OS.

Images must contain a service listening on `container_port` (default 8080),
normally the existing FrankenPHP image. `PORT` is injected. Persistent named
mounts live under `/var/lib/tc/runtime/apps/APP/data`; immutable release config
and encrypted secret references are stored separately from host revisions.
`file_secrets` mounts existing PHP configuration files from encrypted credentials,
so static Trongate configuration need not be baked into an image.

Candidate health must pass before routing changes. A failed post-switch check
restores and checks the prior route. Successful releases retain the preceding
container and its configuration; old processes are stopped after acceptance and
restarted for rollback. Public HTTPS checks are enabled by default; set
`"public_check":false` only when DNS/TLS is unavailable in a disposable environment.
The result records whether that check was requested.

## Deployment workflow

The panel includes guided server onboarding, the app launch wizard adapted from
the original Trongate Cloud, app release/rollback controls and live operation
progress. Git launches resolve a public branch to an immutable commit, build on
a separate builder, then queue deployment of the returned image digest. Image
launches skip the build. Environment values are encrypted before jobs are queued.
The Jobs page shows background work and offers explicit reconciliation for uncertain jobs. Each feature owns its actions; there is no generic command console.
Local administrators have installation-wide authority. Original features still awaiting implementation here include multi-tenant roles, GitHub login, private Git
repositories and billing. Their absence here does not remove them from the original product scope.
Application database provisioning/imports, repository configuration suggestions,
mail capture and background-process configuration from the original platform are
not yet available; the wizard describes the supported database connection path.

## Verification

```sh
composer test                      # PHP models, providers, worker and build-generator tests
php tests/web.php                  # real HTTP login, CSRF, authenticated console
TC_DSN='mysql:host=127.0.0.1;dbname=tc' TC_DB_USER=tc php tests/mariadb.php
nix flake check --no-build          # configurations and derivations evaluate
nix build .#default .#runtime .#builder
nix build .#checks.x86_64-linux.runtime-vm -L  # disposable VM, real SSH/Podman/systemd/Caddy
nix build .#proxmox-template -L     # prepared NixOS VM template; needs Linux/KVM
```

The panel, provider actions and managed-server helpers are PHP. `composer test`
includes runtime recovery, isolated builds and resource-allocation tests. The
NixOS VM test uses the standard NixOS test driver; the deployed product does not
require a Python interpreter.

See [verification evidence and limits](docs/verification.md). Fixture tests do not
prove provider provisioning. No live billable resource is created by ordinary CI.

Further reading: [architecture and recovery](docs/architecture.md),
[module boundaries](docs/extensions.md), [source inspection](docs/inspection.md).
