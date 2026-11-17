# Existing project inspection

Inspected 2026-11-17, before implementation:

| Repository | Inspected commit | Evidence |
|---|---|---|
| `sasin91/trongate.cloud` | `715699cf6c314cdf26e994413316992e381eae7f` | Cloned to a separate local checkout; inspected engine, encryption, deployment worker/task, build generator, image Dockerfile/Caddyfile, tests and `docs/agents.md` |
| `sasin91/trongate-cloud-infra` | `666b88d2deab3d322309e4a00bead723eb537f6b` | Existing clean checkout; inspected license, deployment declarations, checks and `TRONGATE-CLOUD.md` |

The local `platform.trongate.cloud` directory instead points at
`sasin91/hcloud-k3s-platform-kit`. It was not treated as the application source.
No unrelated checkout was modified. No next-generation implementation existed.

Source behavior: the existing worker uses PDO, task scripts, stale thresholds,
GitHub source resolution, generated Dockerfiles, isolated BuildKit work and
Kubernetes manifests. It tracks application config files, deployment steps,
backups and health. Its deployment task and backup orchestration depend on the
old Kubernetes storage/secrets/runtime model, so copying the whole worker would
retain the wrong target semantics.

Reused directly: Trongate dispatcher/base controller, framework-independent
Encryptor, Build_commands and its ten behavior tests. The small PDO worker
retains the existing straightforward task-processing approach while introducing
sticky resource locks, worker fencing and remote journals. Local admin auth is
implemented separately because the original customer/GitHub/token/Valkey setup
does not provide a standalone installation bootstrap.

The old application base uses FrankenPHP in classic mode with PORT=8080, app files
in `/app`, public files in `/app/public`, PHP extensions and Composer inside the
image. That OCI contract is preserved. Its trusted-proxy subnet assumes k3s;
operators must replace that configuration when forwarded client addresses matter
on a Podman host. The outer proxy is Caddy, with loopback-only candidate ports.

Declared infrastructure: the infra checkout contains Hetzner/Kubernetes/Flux and
Traefik configuration. Its narrative says it had not been applied when written.
Neither that statement nor old capacity/pricing notes establish today's runtime
state. No existing production cluster, server, credential or provider account
was contacted during inspection.

License review: `composer.json` marks the application proprietary; `license.txt`
carries the upstream Trongate MIT notice. The new repository remains private and
proprietary, with source provenance and the upstream notice preserved. The infra
repository is MIT (2026 sasin91); no operational cluster manifests were copied.
