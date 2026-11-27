# ZFS Module Registry & Marketplace (`registry.trongate.cloud`)

`trongate.cloud` hosts an author-namespaced Trongate Module Registry backed by ZFS datasets, a dedicated unprivileged FreeBSD jail (`tc_registry`), zero-copy `nullfs` mounts for hosted apps, self-hosted Smart HTTP Git repositories, and optional EmbeddingGemma 2 hybrid semantic search.

---

## Architecture & Security Boundary

### ZFS Dataset Layout (`infra/scripts/bootstrap.sh`)

```text
zroot/tc/registry
├── modules/<namespace>/<module>/<version>   # readonly=on, exec=off, setuid=off, @v1 snapshot
├── repos/<namespace>/<module>.git           # author-owned bare Git repositories (lz4 compressed)
└── archives/<namespace>/<module>/<ver>.tar.gz  # immutable archives served from ZFS ARC by Caddy
```

### Unprivileged `tc_registry` Jail (`10.99.0.3/32`)

Provisioned by `infra/scripts/platform-jails.sh` when `REGISTRY_DOMAIN` is set (e.g. `REGISTRY_DOMAIN=registry.trongate.cloud`):

- Runs `php-fpm` (`pm = ondemand`) under `rctl(8)` hard caps (`256 MiB` default) with `allow.mount = 0`, `enforce_statfs = 2`, and `TC_REGISTRY_MODE=1`.
- Mounted read-only: FreeBSD base (`/.base`), runtime (`/usr/local`), `/var/db/tc/registry/modules`, and `/var/db/tc/registry/archives`.
- Mounted read-write: `/var/db/tc/registry/repos` (for authenticated Smart HTTP `git-receive-pack`) and MariaDB/Valkey Unix sockets.
- **PF isolation (`infra/config/pf.conf`)**: `<tc_registry>` can talk to `<tc_services>` on `lo1` (for EmbeddingGemma 2) and outbound internet, and is blocked from host addresses (SSH/Caddy Admin), `<tc_panel>`, `<tc_jails>`, and RFC1918 networks.
- **Zero ZFS privileges in the jail**: `tc_registry` never runs `zfs(8)` or `jail(8)`. Pushing a semver tag (`v1.0.0`) via Smart HTTP enqueues a `registry-publish` job executed by root-owned `tc-runtime` (`infra/runtime/registry.php`), which validates the Git tree (no escaping symlinks, size limits, PHP files present), creates the `readonly=on` ZFS dataset + `@v1` snapshot, computes a deterministic SHA-256 manifest digest, and writes the `.tar.gz` archive.

---

## Author-Owned Namespaces & Self-Hosted Git

To keep authorship and licensing clean, every module lives under its author's namespace (`<namespace>/<module>`), never mixed into a single platform GitHub account:

1. Register a module repository in `/registry/manage` (queues `registry-init-repo` to initialize `zroot/tc/registry/repos/<namespace>/<module>.git`).
2. Mint a push token strictly scoped to `<namespace>` (stored SHA-256 hashed in `registry_tokens`).
3. Push code and a semver tag:
   ```sh
   git remote add registry https://<namespace>:<token>@registry.trongate.cloud/git/<namespace>/<module>.git
   git push registry main
   git tag v1.0.0
   git push registry v1.0.0
   ```
4. `git-receive-pack` enqueues `registry-publish`, which materializes `zroot/tc/registry/modules/<namespace>/<module>/1.0.0` (`readonly=on`, `@v1`), generates the immutable `.tar.gz`, and embeds the module in EmbeddingGemma 2.

Local developers can vendor any public module directly via `git subtree` or immutable `.tar.gz`:

```sh
git subtree add --prefix=modules/error_pages https://registry.trongate.cloud/git/sasin91/error_pages.git v1.0.0 --squash
```

---

## Bandwidth & CDN / Object Storage Offloading

- **Hosted apps use zero network bandwidth**: `tc-runtime` mounts `zroot/tc/registry/modules/<ns>/<name>/<ver>` directly into `/app/modules/<name>` via read-only `nullfs`, sharing a single copy in ZFS ARC across every app jail on the host.
- **External downloads are served straight from ZFS ARC by host Caddy** (`/archives/*` -> `file_server`) with `Cache-Control: public, max-age=31536000, immutable` and `ETag`. Typical pure-PHP Trongate modules are 5–100 KB gzipped (1,000,000 downloads ≈ 50 GB).
- **Pull-through CDN / optional S3 bucket**: Place any pull-through CDN in front of `registry.trongate.cloud/archives/*` (the immutable header gives ~99%+ cache hit rate with zero host credentials), or set `TC_REGISTRY_ARCHIVE_BASE_URL=https://cdn.example.com/modules` if you mirror `zroot/tc/registry/archives/` to an S3/CDN bucket.

---

## App Modules Panel, Zero-Copy `nullfs`, Config Overlays & Auto-Wiring

Every hosted app has a **Modules** tab (`/apps/modules/<app_id>`) where team members with settings access can:

- **Insert** modules directly from the ZFS registry (with inline EmbeddingGemma 2 search)
- **Update** pinned versions with one click when newer releases are published
- **Configure** each module using typed fields declared in `module.json` (`config_schema`, supporting `string`, `int`, `bool`, `select`, and encrypted `secret` fields stored in `credentials`) plus custom `key=value` entries
- **Remove** modules and deploy atomically via blue/green ZFS rollout

Apps can also commit a declarative `trongate-modules.json` in their repository root (panel settings override per module name):

```json
{
  "modules": {
    "sasin91/error_pages": "1.0.0",
    "sasin91/log": "1.0.1"
  },
  "autowire": {
    "error_pages": true,
    "log": true
  }
}
```

At deploy time, `tc-runtime`:

1. Verifies each pinned module's ZFS dataset and SHA-256 manifest digest.
2. Mounts each module read-only at `/app/modules/<name>` via `nullfs` (refusing to overwrite a non-empty vendored directory).
3. Renders per-module configuration read-only (`0440`) at `/app/config/<name>.php` (merging decrypted secret fields at deploy time).
4. When auto-wiring is enabled:
   - `error_pages`: patches `ERROR_404` in `/app/config/config.php` and prepends `/etc/tc/tc_modules_boot.php` via `php_admin_value[auto_prepend_file]` to register exception/fatal handlers (returning friendly HTML or JSON for `fetch()` calls).
   - `log`: preloads `Log.php` and provides `tc_log($channel)` writing structured entries to `/var/log/php-fpm.log` (automatically tailed by the app's Logs tab and 24-hour error rate card).

---

## EmbeddingGemma 2 Semantic Search

The Marketplace uses the same hybrid search architecture proven in `semantic-webshop`:

1. Add the FreeBSD-native **EmbeddingGemma 2 (semantic search)** service preset on `/services` (`llama-cpp` / `llama-server` running `ggml-org/embeddinggemma-2-GGUF:Q8_0` with persistent ZFS model cache at `/var/db/service/llama`).
2. `Registry` auto-discovers a running shared `embeddings` service (or reads `EMBEDDINGS_URL`).
3. Queries use EmbeddingGemma's model-card prompts (`task: search result | query: ...`), Matryoshka truncation + L2 normalization, `registry_query_cache`, and Reciprocal Rank Fusion (`k = 20`, keyword weight `0.5`, semantic weight `1.0`).
4. If the embeddings jail is unconfigured, cold-starting, or slower than 2 seconds, searches gracefully fall back to keyword search with a subtle notice—never an error page.
