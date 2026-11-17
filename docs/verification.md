# Verification evidence

Verification was performed from Windows/PHP 8.4 and GitHub-hosted Linux CI.
The workstation has no WSL installation, Nix, Podman or local Linux/KVM runtime.

Executed successfully during implementation:

- PHP syntax checks for application/worker/UI code.
- Thirteen PHP operational behavior tests: queue claiming/fencing/recovery, input
  validation, deterministic configuration data, authenticated encryption, Hetzner
  status mapping/label reconciliation, SSH prerequisite reporting and build planning.
- Ten inherited Build_commands tests.
- Real local HTTP test of login, CSRF rejection and authenticated console operation.
- Nine Linux simulated runtime tests: success/rollback/idempotent replay,
  candidate failure, failed public check restoration, interrupted switch recovery,
  uncertain migration, host fencing, flock exclusion, persistent data retention and
  explicit interrupted-host reconciliation.
- Real MariaDB 11.4 queue claim/heartbeat/result persistence and four-process
  conflicting-resource serialization in CI.
- Initial Nix flake evaluation and control-plane/runtime package builds in CI
  (`44026312954`, commit `738a736`).

The NixOS runtime VM passed in CI run `44029177049` (commit `973ad96`). It uses actual SSH enrollment, independent fingerprint
comparison, a local OCI registry, Podman, persistent systemd units, Caddy routing,
PHP CLI queue operations, successful release/rollback, failed candidate, persistent
files, real proxy routing and changed-host-key rejection. Earlier runs found and
fixed Nix quoting, PHP empty-map encoding and immutable NixOS unit-path issues.
The latest test also boots the complete control-plane installation module; its
final result is recorded in the implementation report and CI.

Not established by unit tests: live Hetzner snapshot boot/provisioning, cloud-init
behavior in a real project, a real external Git-to-registry build, a production TLS
deployment, failed-reboot recovery or deploy-rs activation on a provider host.
No existing production server was used as a test target.

Reproduce each layer with the commands in README. The VM test is
`nix build .#checks.x86_64-linux.runtime-vm -L` on x86 Linux/KVM. Build the raw snapshot
with `nix build .#snapshot -L`. For live provider verification follow every step in
`docs/hetzner.md` in a disposable project, retaining operation IDs and deleting each
explicitly selected test resource. These commands require operator infrastructure
credentials and explicit billable-resource actions.
