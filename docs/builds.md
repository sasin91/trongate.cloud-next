# Git Builds in Ephemeral FreeBSD Jails

Application builds run directly on the target FreeBSD host inside ephemeral,
`rctl(8)`- and `pf`-bounded FreeBSD build jails managed by
`infra/runtime/builder.php` (invoked through `tc-runtime` with
`{"action":"build",...}`). No OCI images, Dockerfiles, container engines, or
external image registries are used.

## Build flow

1. **Commit resolution**: The `Apps` module resolves a public GitHub branch or
   tag to an immutable 40-character commit SHA via the GitHub API and queues a
   `build` job.
2. **Admission & locking**: `tc-runtime` acquires a non-blocking `flock` on
   `/var/db/tc/runtime/build.lock` and checks for at least 2 GiB free space
   before writing a `running` journal. Repeating a succeeded operation ID
   returns its recorded result idempotently.
3. **Ephemeral build jail**: `Builder` clones `zroot/tc/skeleton@v1` with
   `quota=2G` into a throwaway jail dataset, mounts the read-only FreeBSD base
   (`zroot/tc/bases/<ver>` at `/.base`), read-only builder runtime
   (`zroot/tc/runtimes/builder` at `/usr/local`), and a clean workspace (`/app`).
   The build jail runs on dedicated `lo1` IP `10.99.254.1/32` inside `pf`
   `<tc_jails>` and is bounded by `rctl(8)` hard caps:
   - `memoryuse:deny=1536M`
   - `swapuse:deny=1536M`
   - `pcpu:deny=200` (2 CPU cores)
   - `maxproc:deny=256`
   - `openfiles:deny=2048`
   - 30-minute subprocess timeout
   The build jail has no access to application secrets, `/var/db/tc/runtime`,
   MariaDB/Valkey sockets, SSH keys, or other apps' datasets, and is always
   stopped and ZFS-destroyed in `finally`.
4. **Shallow fetch & source verification**: Inside the workspace, Git performs
   a shallow fetch (`--depth=1`, `-c protocol.file.allow=never`) and verifies
   `git rev-parse HEAD` matches the exact requested 40-hex commit. Before and
   after running build commands, `Builder` verifies:
   - No symlinks escape the repository root.
   - Required Trongate entry points `public/index.php` and `config/config.php`
     exist.
5. **Composer & optional Node/Bun commands**: `jexec` runs `composer install`
   (when `composer.json` is present) and any base64-encoded user
   `Build_commands` strictly inside the build jail, with
   `PHP_INI_SCAN_DIR=/usr/local/etc/php-base:/etc/tc/php.d` loading only the
   requested curated optional PHP extensions.
6. **Immutable ZFS release**: The built tree (excluding `.git`) is staged into
   an immutable ZFS release directory (`zroot/tc/apps/<app>/releases/<op>`) and
   hashed into a deterministic SHA-256 manifest digest (`sha256:<64hex>`).

## Shared PHP 8.4 runtime and curated optional extensions

`infra/scripts/build-runtime.sh` builds a single read-only PHP 8.4 runtime dataset
(`zroot/tc/runtimes/php84@v1`) mounted into every app and build jail via
`nullfs`:

- **Core Trongate extensions** live in `/usr/local/etc/php-base` and are always
  loaded.
- **Curated optional extensions** (`.so` files pre-installed in the shared
  runtime but NOT enabled globally):
  `imagick`, `soap`, `gmp`, `pdo_pgsql`, `pgsql`, `ldap`, `sockets`, `xsl`,
  `calendar`, `imap`, `pcntl`, `shmop`, `sysvsem`, `ftp`.
- Each jail sets `PHP_INI_SCAN_DIR=/usr/local/etc/php-base:/etc/tc/php.d` and
  writes only its requested optional extensions into
  `/etc/tc/php.d/extensions.ini`.

## Release configuration and secrets

Releases carry immutable specifications validated by `Validate::release()`:

```json
{
  "artifact": "sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
  "domain": "demo.example.com",
  "health": "/",
  "config_version": "v1",
  "php_version": "8.4",
  "extensions": ["imagick"],
  "database": true,
  "memory_mb": 128,
  "cpu_percent": 100,
  "disk_quota_mb": 1024,
  "secrets": {},
  "file_secrets": {
    "/app/config/config.php": "demo-config"
  },
  "mounts": {
    "uploads": "/app/public/uploads"
  },
  "commit": "0123456789abcdef0123456789abcdef01234567",
  "migration": [],
  "schema_compatible": true
}
```

- If `/app/config/config.php` is not explicitly overridden in `file_secrets`,
  `tc-runtime` patches only `BASE_URL` and `ENV` in the release's existing
  `config/config.php` via PHP tokenization, preserving all custom user
  `define()` constants and routing defaults.
- If `database` is `true` and `/app/config/database.php` is not overridden in
  `file_secrets`, `tc-runtime` renders `/app/config/database.php` pointing at
  the app's provisioned MariaDB database over `/var/run/tc/mariadb/mysqld.sock`.
- `file_secrets` are decrypted only at deploy time, materialized `0440` inside
  the release overlay directory, and mounted read-only into `/app/config` via
  `nullfs`.
- `secrets` map uppercase environment variable names to encrypted credential IDs
  injected into the jail's `0600` `php-fpm.conf` (`clear_env = yes`).

Customer build output is not logged in the panel database because build scripts
can echo secrets. Operation steps, exit status, release path, commit, and
manifest digest are recorded in the job journal.
