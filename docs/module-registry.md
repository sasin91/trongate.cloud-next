# Trongate Module Harbour (`registry.trongate.cloud`)

Ahoy! The Module Harbour is a free, author-namespaced directory of Trongate modules ("crates"). It is backed by immutable ZFS datasets, served from a dedicated unprivileged FreeBSD jail (`tc_registry`), and offers self-hosted Git repositories, `.tar.gz` archives, and optional EmbeddingGemma 2 hybrid search.

Everything in the Harbour is free. There are no paid listings, and none are planned.

Modules are installed the Trongate way: you download the code into your app's `modules/` folder, commit it, and push. The code is yours to read and change, local development matches production, and trongate.cloud never edits your repository or mounts code into your app behind your back.

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

1. Register a module repository in `/harbour/manage` (queues `registry-init-repo` to initialize `zroot/tc/registry/repos/<namespace>/<module>.git`).
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

### Upload a module package

Publish a small module without setting up Git from **Harbour → Manage & Publish → Upload a module**. Enter its namespace, module name, and a new semantic version, then upload a `.tar.gz` archive of the module directory's contents (the module files should be at the archive root):

```sh
tar -czf error_pages.tar.gz -C modules/error_pages .
```

Uploads are limited to 512 KiB compressed. The host checks archive paths, file types, and expanded size before publishing the version as an immutable ZFS snapshot. The uploaded version appears in the shared Harbour, ready for anyone to haul aboard. Versions remain immutable; upload a new version to publish changes.

---

## Bandwidth & CDN / Object Storage Offloading

- **External downloads are served straight from ZFS ARC by host Caddy** (`/archives/*` -> `file_server`) with `Cache-Control: public, max-age=31536000, immutable` and `ETag`. Typical pure-PHP Trongate modules are 5–100 KB gzipped (1,000,000 downloads ≈ 50 GB).
- **Pull-through CDN / optional S3 bucket**: Place any pull-through CDN in front of `registry.trongate.cloud/archives/*` (the immutable header gives ~99%+ cache hit rate with zero host credentials), or set `TC_REGISTRY_ARCHIVE_BASE_URL=https://cdn.example.com/modules` if you mirror `zroot/tc/registry/archives/` to an S3/CDN bucket.

---

## Installing a crate (download, commit, push)

Each module page in the Harbour shows copy-paste commands for its latest version. Run them from your app's repository root:

```sh
# With Git (keeps history tidy and makes updates a one-liner)
git subtree add --prefix=modules/log https://registry.trongate.cloud/git/sasin91/log.git v1.0.1 --squash

# Without Git subtree: archives already contain a top-level <name>/ folder
curl -fsSL https://registry.trongate.cloud/archives/sasin91/log/1.0.1.tar.gz | tar -xzf - -C modules
```

Then commit and push. The next deploy carries the module like any other code in your repository. To update, run `git subtree pull` with the newer tag (shown on the module page and in the app's **Modules** tab), or replace the folder with the newer archive.

## The app's Modules tab: what's aboard

Every hosted app has a read-only **Modules** tab (`/apps/modules/<app_id>`). It works like an add-on manager: it recognises what is installed and keeps a lookout for updates, but it never changes your code.

- During each build, `infra/runtime/builder.php` fingerprints every top-level `modules/<name>` folder with the same SHA-256 manifest digest that the Harbour records for each published version. The fingerprints are stored in the release metadata.
- A fingerprint that matches a published version shows as that crate and version: **Up to date**, or **vX.Y.Z available** with the update command.
- A folder named like a Harbour module whose fingerprint matches no published version shows as **Changed locally**. You've made it your own, so no updates are suggested.
- Every other folder is listed as one of the app's own modules.
- Empty directories are not part of Git trees or archives. A module that ships empty directories may therefore show as changed locally after a Git install.
## EmbeddingGemma 2 Semantic Search

The Harbour uses the same hybrid search architecture proven in `semantic-webshop`:

1. Add the FreeBSD-native **EmbeddingGemma 2 (semantic search)** service preset on `/services` (`llama-cpp` / `llama-server` running `ggml-org/embeddinggemma-2-GGUF:Q8_0` with persistent ZFS model cache at `/var/db/service/llama`).
2. `Registry` auto-discovers a running shared `embeddings` service (or reads `EMBEDDINGS_URL`).
3. Queries use EmbeddingGemma's model-card prompts (`task: search result | query: ...`), Matryoshka truncation + L2 normalization, `registry_query_cache`, and Reciprocal Rank Fusion (`k = 20`, keyword weight `0.5`, semantic weight `1.0`).
4. If the embeddings jail is unconfigured, cold-starting, or slower than 2 seconds, searches gracefully fall back to keyword search with a subtle notice—never an error page.
