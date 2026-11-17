# Trongate Cloud Next · proof of concept

A self-hostable PHP/Trongate administrator panel and durable SQL worker. Managed
NixOS hosts and OCI application deployment are independent operations. Hetzner is
a provider; SSH is the transport, including for manually enrolled Linux hosts.

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
sudo sh -c 'cat /run/tc-admin.json | sudo -u tc env TC_STATE=/var/lib/tc-control tc init'
sudo rm /run/tc-admin.json
sudo systemctl restart tc-worker
```

Point the domain at the VM and open HTTPS. `tc init` creates the installation
encryption key and schema before the panel is usable. It can also reset a local
administrator password during recovery. Back up the key immediately.

Run administration commands as the installation OS user:

```sh
sudo -u tc env TC_STATE=/var/lib/tc-control tc inventory </dev/null
```

The examples below abbreviate that invocation to `tc`. Use `TC_STATE` and the
same database environment for every command. JSON can be read from a file or
stdin; never place secrets in shell arguments. See [self-hosting](docs/self-hosting.md)
for worker separation, database access, updates and restore.

## First image deployment

1. Prepare a target with `tc.nixosModules.runtime` and
   `services.trongate-runtime.enable = true`, preserving its existing hardware and
   dedicated SSH public key configuration. For another Linux distribution use
   [the preparation instructions](docs/self-hosting.md#prepared-linux-target).
2. Store the dedicated SSH private key using `tc credential credential.json`:
   `{"id":"web-key","value":"PRIVATE KEY CONTENT INCLUDING NEWLINES"}`.
3. Scan the host; this does **not** trust it or modify it:

```sh
tc server-scan examples/server.json
# Independently obtain SHA256:... from the host console with:
# ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub
tc server-confirm confirm.json
# confirm.json: {"id":"web","enrollment":"ID FROM SCAN","fingerprint":"SHA256:..."}
tc app-create examples/app.json
tc release release.json
# release.json follows examples/release.json, with a real image digest/domain.
tc deploy deploy.json
# deploy.json: {"app":"demo","release":"ID FROM RELEASE"}
tc operations </dev/null
tc operation operation.json
# operation.json: {"operation":"ID FROM DEPLOY"}
tc rollback examples/rollback.json
```

The long-running worker executes queued operations. For a one-shot test:
`tc worker --once`. A missing runtime helper, privilege, tool or proxy service is
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

## Other supported operations

- **Build:** `tc build build.json` on a separately enrolled, isolated builder.
  The result is a digest for the normal `release`/`deploy` flow. See
  [Git builds](docs/builds.md). This PoC supports public GitHub repositories at a
  full commit SHA, generated Dockerfiles, PHP extensions and custom build commands.
- **Managed host update:** prepare a frozen configuration with
  `php bin/prepare-host.php host.json`, then `tc host-update update.json`.
  See [host operations](docs/architecture.md#managed-host-operations).
- **Hetzner:** save a token as a credential, then `provider-connect`,
  `provider-options` and `provision`. Only compatible, available NixOS snapshots
  labeled `tc-nixos=true` are accepted. Reboot/deletion require explicit operator
  confirmation. See [snapshot bootstrap and cleanup](docs/hetzner.md).
- **Recovery:** `tc reconcile operation.json` resumes observation of an uncertain
  operation. It does not release its resource lock or blindly repeat a mutation.

The panel exposes essential operations through a minimal action form with JSON
fields, server readiness, inventory and operation results. Local administrators
have installation-wide authority. Multi-tenant roles, GitHub login, private Git
repositories, billing and Kubernetes migration are outside this PoC.

## Verification

```sh
composer test                      # PHP behavior + inherited build-generator tests
php tests/web.php                  # real HTTP login, CSRF, authenticated console
python3 -m unittest discover -s tests -p '*_test.py' -v  # Linux, simulated remote operations
TC_DSN='mysql:host=127.0.0.1;dbname=tc' TC_DB_USER=tc php tests/mariadb.php
nix flake check --no-build          # configurations and derivations evaluate
nix build .#default .#runtime .#builder
nix build .#checks.x86_64-linux.runtime-vm -L  # disposable VM, real SSH/Podman/systemd/Caddy
nix build .#snapshot -L             # x86 Hetzner raw disk image; needs Linux/KVM
```

See [verification evidence and limits](docs/verification.md). Fixture tests do not
prove provider provisioning. No live billable resource is created by ordinary CI.

Further reading: [architecture and recovery](docs/architecture.md),
[adapter extension guide](docs/extensions.md), [source inspection](docs/inspection.md).
