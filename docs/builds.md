# Public GitHub to OCI

The first Git flow is a public GitHub repository and an exact 40-character commit.
Resolve a branch to a reviewed SHA with Git/GitHub, then pass that SHA. Private
GitHub App OAuth, webhooks, branch-head reconciliation and the old Kubernetes
configuration editor are not ported. The original Build_commands generator and
its tests are reused, including encoded custom commands and Node/Bun build stages.

Use a separate, disposable builder VM with no route/credentials to the control
plane database and no fleet/provider secrets. Enable rootless Podman with working
user namespaces/cgroup delegation, git, Python and sufficient disk space. Install:

```sh
nix profile install /opt/trongate-cloud-next#builder
```

Make `tc-build` available in the dedicated builder SSH user's noninteractive PATH.
Enable its systemd user manager (for example `loginctl enable-linger BUILDER_USER`)
and cgroup delegation; build commands run in a bounded user scope. Rootless Podman
still needs its ordinary in-namespace build capabilities to install PHP extensions.
Configure registry credentials on that builder with `podman login REGISTRY` as
the builder user; use credentials scoped to pushing its application repositories.
They stay outside the source/build context. Do not mount a container-engine socket,
installation state or host credentials into customer build containers.

Enroll the builder using the same two-phase SSH identity flow. Its application
readiness may be false; that is expected for an unprivileged build-only machine.
The web wizard automatically selects an inspected, unprivileged builder with
`tc-build`, working Podman and a systemd user manager. It matches the application's
CPU architecture, excludes the application host and hosts already assigned apps,
and prefers the shortest build queue. Reinspect existing builders to record these
capabilities. Configure `services.trongate-cloud.buildRegistry` (or
`TC_BUILD_REGISTRY`) with a registry/namespace such as `registry.example.com/apps`.
The wizard generates a distinct image tag per launch; registry authentication
stays on the builder. Image pulls must also be accessible to application hosts.
No extra VMs or registry accounts are created by automatic selection.

```json
{
  "builder":"builder-one",
  "repository":"owner/public-trongate-app",
  "commit":"0123456789012345678901234567890123456789",
  "base":"registry.example.com/trongate-base@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
  "tag":"registry.example.com/demo:reviewed-build",
  "extensions":["intl"],
  "commands":"npm ci\nnpm run build"
}
```

```sh
tc build build.json
tc operation operation.json
# Copy the succeeded result's image and commit into a release specification.
tc release release.json
tc deploy deploy.json
```

Leave `base` blank to use the pinned FrankenPHP 1.12 / PHP 8.4 Debian image.
The generated build adds Composer and common Trongate PHP extensions and listens
on the selected container port (8080 by default). Advanced users can supply their
own digest-pinned base image. Application output still needs your registry.
The default upstream image and Composer digests were resolved on 2026-11-19.
See https://frankenphp.dev/docs/docker/ for the upstream image conventions. Build source must
contain `public/index.php` and `config/config.php`. Escaping source symlinks are
rejected. Composer install and custom commands run inside rootless build containers
limited to 2 GiB, two CPU cores and 512 PIDs; subprocesses have a 30-minute limit.
Dedicated builder VM disk quota/firewall enforce total storage/network boundaries.
The VM must not host trusted workloads: containers are not a hostile-code VM boundary.

A repository Dockerfile is ignored; an application Caddyfile is preserved. The
published registry digest, not the mutable tag, is the deployment artifact.
App config is preserved in the image unless overridden by release `file_secrets`:

```json
"file_secrets": {
  "/app/config/config.php":"demo-config",
  "/app/config/database.php":"demo-database"
}
```

Create those credentials from complete valid PHP configuration files. They are
encrypted in SQL, materialized only on the target in 0600 release files, and
mounted read-only. They never enter the image or Nix store. Environment `secrets`
map environment names to credential IDs; multiline values require a file secret.
Use new credential IDs and a new `config_version` when changing application
configuration. A retained deployed release keeps its original materialized files;
redeploying a specification resolves its referenced credential IDs at execution time.
For private images, `registry_secret` refers to an encrypted Podman auth.json;
the target uses a temporary auth file only during pull and removes it afterwards.

Customer output is not logged because build/migration output can echo secrets.
Operation steps, exit outcomes and digests are retained; inspect builder-local
tools privately for deeper diagnostics. A builder interruption is uncertain until
its locked, per-operation result is observed. Container resource limits do not
bound Git repository size; impose disk quotas and network policy on the builder VM.
