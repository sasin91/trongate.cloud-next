# Adding an adapter without changing the product model

Only Hetzner is currently supported as a provider. Hostinger, Linode and Vultr
are future adapters, not UI options or empty implementations.

A provider implementation should expose the small set of concrete operations
actually used by `Control`/`Worker`: account validation, option/image enumeration,
resource creation/inspection, asynchronous action inspection, reboot and explicit
deletion. Use an injectable HTTP boundary like `Http` for fixture tests. Keep
provider credentials in `credentials`, referenced by the connection record.

Persist operation intent before mutations, label/tag resources with the stable
operation ID, and implement reconciliation for a timeout after acceptance. If a
provider lacks an idempotency key or discoverable label, do not automatically retry
an ambiguous create. Map statuses to pending/succeeded/failed/uncertain; a 202 or
new provider ID is not successful provisioning. Validate architecture/image/type
compatibility using that provider's live catalog. New provider code does not own
SSH, application rollout or Nix activation.

To replace deploy-rs, implement `HostDeployer::deploy`, `inspect` and `rollback`
using the existing server inventory and frozen revision directory. Return our
structured statuses, intended/observed store generation, prior generation and
health findings. Preserve remote fencing and generation roots. Keep backend CLI
flags/output parsing inside that implementation, and change the worker's single
backend construction point. No provider, release, inventory or customer status
schema should need replacement. A subprocess launching successfully is insufficient;
observe the activated configuration and service/application health.

The SSH transport owns scanned identity confirmation, strict subsequent checking,
temporary 0600 credential files, argument-array local process execution, deadlines
and heartbeat callbacks. Fixed helper commands accept JSON on stdin; do not splice
operator settings into remote shell expressions. A transport replacement must
preserve equivalent authenticated host identity and result uncertainty semantics.

The runtime owns staged containers, health checks, route transitions and retained
releases. Runtime extensions must maintain the journal-before-mutation ordering and
must not clear uncertainty when a migration or detached remote operation may still
be running. Add failure/retry tests through normal CLI entry points and disposable
hosts, alongside fixture tests for provider boundaries.
