# Product direction

- The product has two headline features: code deployments (DeployHQ-style workflow)
  and NixOS server provisioning on Proxmox.
- Proxmox is the sole provisioning platform. Do not add cloud-provider adapters,
  pricing catalogs, Hetzner support, or Kubernetes/k3s infrastructure.
- Preserve the original vision, feature specifications, and look and feel from
  ~/Herd/trongate.cloud. Consult its docs/ui-design.md, docs/platform-architecture.md,
  and existing modules before changing customer-facing behavior. Replace the
  infrastructure implementation; do not silently discard the original product features.
- Keep implementations explicit and minimal. Introduce abstractions only for a
  demonstrated need. modules/jobs/worker.php runs the worker directly; modules/setup/init.php and modules/setup/migrate.php handle installation; modules/monitoring/collect.php collects metrics; product
  workflows belong in the panel.
- Block internal Proxmox actions individually with block_url('proxmox/action').
  Do not block the entire controller in its constructor. Browser-facing actions
  must enforce authentication and CSRF for mutations.

# Code conventions

- Follow Trongate module conventions. Keep application code in its owning module,
  use module models for persistence and traits for shared model logic.
- Use K&R PHP style: opening braces on the same line, four-space indentation,
  and readable multiline method and control-flow bodies.
- Preserve engine/Core.php routing conventions. Proxmox is a top-level module
  with its own model. Protect internal actions with block_url(...), and use the
  native model database object instead of database access traits.
  Enrollment, provisioning and SSH keys are actions within Proxmox,
  not separate modules.

- Controllers use $this->model. Load other modules through Trongate module loading;
  do not construct module models in application code or views. Pass prepared data
  into views. The loader shares database transactions with child modules.

- Records owns the shared records table through internal controller actions and
  its model. Use Modules::run() for independent calls and the transaction-sharing
  module loader for atomic feature writes. Keep JSON encoding helpers local to
  their owning classes rather than registering global helpers.

- Keep HTTP calls and workflow orchestration in controllers or HTTP clients. Models
  own persistence; do not move transport or key generation into model classes.

HTTP transport is shared through the standalone `engine/Http_client.php` class.
It owns TLS, connection deadlines and raw responses; modules own authentication,
request encoding and API-specific errors. The client does not retry mutations.

Controllers access the shared HTTP client through lazy `$this->http`, provided
by `Trongate::__get` like `$this->model`. Test doubles implement `Http_client`;
provider controllers must not double as their own transport.

- Keep Proxmox HTTP protocol details in its module-local Proxmox_client class;
  the controller orchestrates actions and the model handles persistence.

- Proxmox SSH setup and verification belong in the module-local Proxmox_ssh class.
  Keep enrollment decisions in the controller and inventory writes in the model.

- Jobs owns queue persistence, worker lifecycle, progress and recovery only. Dispatch job actions through Modules::run() with a Job_context containing identity, payload and heartbeat; keep clients and database dependencies in the owning module. Feature workflows belong to Apps, Servers, Deployments or Proxmox; do not add a generic command dispatcher.
