# Verification evidence

Verification was performed from Windows/PHP 8.4 and GitHub-hosted Linux CI.
The workstation has no WSL installation, Nix, Podman or local Linux/KVM runtime.

Initial proof-of-concept code revision: `1df68df1993fc294cdb27ed047ab655bdb9c9ff1`.
[CI run 44031399551](https://github.com/sasin91/trongate.cloud-next/actions/runs/44031399551)
passed both jobs on 2026-11-17. Documentation-only follow-up commits do not rerun VM builds.

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
- Nix flake evaluation (including control-plane, runtime, snapshot configuration
  and backend dependencies), and control-plane/runtime package builds in CI.

The NixOS runtime VM passed in the final run. It uses actual SSH enrollment, independent fingerprint
comparison, a local OCI registry, Podman, persistent systemd units, Caddy routing,
PHP CLI queue operations, successful release/rollback, failed candidate, persistent
files, real proxy routing and changed-host-key rejection. Earlier runs found and
fixed Nix quoting, PHP empty-map encoding and immutable NixOS unit-path issues.
The second VM also passed: the complete control-plane installation module booted,
MariaDB socket-authenticated bootstrap succeeded, PHP-FPM and Caddy served the
HTTPS login page, the local inventory CLI worked and the worker service ran.
The local HTTPS fixture uses an internal certificate and an explicit test-only
`curl -k`; this does not establish public ACME/DNS behavior.

Not executed in that initial verification: the raw snapshot image build, live Hetzner snapshot boot/provisioning,
cloud-init behavior in a real project, a real external Git-to-registry build,
public ACME/DNS deployment, failed-reboot recovery, ARM VM tests, or actual deploy-rs
host activation/rollback. The deploy-rs boundary has fixture tests and its pinned
tooling builds; this is distinct from a successful real activation.
No existing production server was used as a test target.

Reproduce each layer with the commands in README. The VM test is
`nix build .#checks.x86_64-linux.runtime-vm -L` on x86 Linux/KVM. Build the raw snapshot
with `nix build .#snapshot -L`. For live provider verification follow every step in
`docs/hetzner.md` in a disposable project, retaining operation IDs and deleting each
explicitly selected test resource. These commands require operator infrastructure
credentials and explicit billable-resource actions.

## Hosted ARM installation and guided flows (2026-11-17)

A dedicated Hetzner CAX11 was provisioned using hcloud in a separate project.
The pinned application and complete NixOS system built on aarch64 and booted.
Initial Debian conversion required a rescue repair to move old service files
aside; NixOS, database, panel and worker then passed a second reboot. Public
DNS, ACME HTTPS, authenticated login, CSRF rejection and inventory passed at
`next.trongate.cloud`, including an IPv6 HTTPS request. This is a control-plane
installation, not verification of the application's provider snapshot workflow.

Guided-flow revision `5a26897443d1c89b56f6bdc2049e52b23e79cd75` was built and
activated on that ARM installation. Local PHP tests cover encrypted environment
values, invalid launch input without partial app creation, duplicate HTTP
submissions, build-to-deployment handoff replay, authenticated progress polling
and asset serving. Desktop and 390px browser checks exercised the wizard through
image launch into a queued deployment using a disposable SQLite fixture, with no
worker executing against the fixture address. Live HTTPS checks verified the new
panel and all production services remained healthy.

GitHub App authentication, private repositories, automatic push deployments,
application database provisioning/imports and source configuration suggestions
are not implemented by this UI port. No real external Git-to-registry build or
customer application deployment was performed during this change.
