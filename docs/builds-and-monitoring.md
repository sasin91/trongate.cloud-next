# Builds and capacity monitoring

Application-server builds are the default. The NixOS runtime module enables
`services.trongate-runtime.localBuilds` and supplies a rootless `tc-builder` user.
Build commands run only inside its Podman containers. Its home has no application
secrets, provider tokens, runtime mounts, or SSH private keys. Builds use one CPU,
1 GiB RAM and 512 tasks; the full user slice is capped at 1.5 GiB. Preflight checks
require 1.5 GiB available memory and 5 GiB free disk. These checks are admission
hints, not storage quotas: a large build can still exhaust space.

Local images are exported as OCI archives and imported on that same host. They
are addressed by their verified image ID. No DNS name or registry is required.
The running app is retained during the build. Successful imports delete the
transfer archive; old dangling builder cache is pruned before the next build.
An hourly cleanup removes retired app releases after 24 hours, retaining active,
previous and staged releases. User uploads are never removed by this cleanup.
Failed or interrupted builds may require inspection; they are never blindly
replayed. Abandoned archives and unused builds without a release are not yet
automatically collected; capacity monitoring exposes pressure before another build.

## Optional dedicated builder

Use a server with the same architecture as the application server. No server is
created or resized automatically. The provider creation page requires review of
its cost. Import `inputs.trongate.nixosModules.builder` and configure:

```nix
services.trongate-builder = {
  enable = true;
  authorizedKeys = [ "ssh-ed25519 YOUR_PUBLIC_KEY" ];
  registryDomain = "build.example.com";
  registryPasswordFile = "/var/lib/tc-builder-secrets/registry.htpasswd";
};
```

Keep the bcrypt htpasswd file outside the Nix store, owned by root with group
`docker-registry`, mode 0640. Configure push access in
`/home/tc-builder/.config/containers/auth.json` (owner tc-builder, mode 0600).
Connect this server in the UI as SSH user `tc-builder`. The readiness check
requires rootless Podman and an active systemd user manager.

Configure `services.trongate-cloud.buildRegistry` (host/namespace) and
`buildRegistrySecret` (the encrypted credential reference for read-only pull
auth JSON) on the control installation. Select Dedicated build server in app
setup; compatible, unused-for-apps builders are ranked by queued builds.
The existing dedicated builder remains available for existing registry images.
Do not delete it until those images have been migrated or rebuilt locally.

## Monitoring and scaling

The capacity overview follows the original Trongate Cloud overview: observed
CPU and memory use, capacity, and pressure hints. This runtime adds disk usage
and free space, load average, app count and configured app CPU/memory limits.
Unlike the original Kubernetes collector, it reads Linux host counters over the
existing pinned SSH connection. No public metrics port is opened.

`tc monitor` runs every five minutes. Application servers and upgraded dedicated builders are sampled. Each server retains up to 288 samples
(about 24 hours). Missing or stale readings are shown as unavailable, not zero.
Three high samples spanning at least 500 seconds trigger CPU/memory/disk hints
at 85%; less than 5 GiB free produces an immediate build-space hint. CPU is a
one-second sample, so hints are indicative, not continuous profiling.

Monitoring never resizes a server. Future autoscaling must require explicit
user opt-in, permitted sizes, a cost ceiling, cooldowns, and a way to revoke
consent. No existing server-creation approval grants ongoing scaling consent.

## Go live

A first deployment builds and passes local checks before entering Waiting to go
live. The app candidate stays on the server. DNS records and a Check DNS and go
live action appear in the deployment timeline. A failed DNS/HTTPS attempt keeps
the deployment staged for another attempt. New deployments of the same app are
blocked until the staged deployment is completed; other apps can deploy.
