<?php
declare(strict_types=1);

/**
 * Host-side ZFS Module Registry manager invoked strictly by tc-runtime (protocol v2).
 *
 * Manages namespaced author-owned bare Git repositories under
 * `<pool>/tc/registry/repos/<namespace>/<module>.git`, immutable read-only ZFS
 * module version datasets under `<pool>/tc/registry/modules/<namespace>/<module>/<version>`
 * (snapshotted at `@v1`), and content-verified `.tar.gz` archives under
 * `<pool>/tc/registry/archives/<namespace>/<module>/<version>.tar.gz`.
 *
 * Security invariant: the unprivileged `tc_registry` jail never executes zfs(8) or
 * jail(8). All ZFS dataset creation, immutability (`readonly=on`), snapshotting,
 * and archive publishing occur strictly here inside root-owned `tc-runtime`.
 */
final class Registry_runtime {
    public const MAX_FILE_BYTES = 20 * 1024 * 1024;
    public const MAX_TOTAL_BYTES = 64 * 1024 * 1024;
    public const MAX_README_BYTES = 65536;
    public const MAX_MANIFEST_BYTES = 65536;
    public const MAX_UPLOAD_BYTES = 524288;
    public const NAMESPACE_REGEX = '[a-z][a-z0-9-]{1,38}';
    public const MODULE_REGEX = '[a-z][a-z0-9_]{0,39}';
    public const VERSION_REGEX = '[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?';

    public string $registry_root;
    public string $modules_root;
    public string $repos_root;
    public string $archives_root;
    public string $meta_root;

    public function __construct(
        private Runtime $runtime,
        ?string $registry_root = null,
    ) {
        $production = $runtime->root === '/var/db/tc/runtime';
        $this->registry_root = $registry_root ?? ($production ? '/' . $runtime->pool . '/tc/registry' : $runtime->root . '/registry');
        $this->modules_root = $this->registry_root . '/modules';
        $this->repos_root = $this->registry_root . '/repos';
        $this->archives_root = $this->registry_root . '/archives';
        $this->meta_root = $runtime->root . '/registry';
        Runtime::directory($this->meta_root, 0700);
    }

    public function init_repo(array $request): array {
        $namespace = Runtime::match($request['namespace'] ?? null, self::NAMESPACE_REGEX);
        $name = Runtime::match($request['name'] ?? null, self::MODULE_REGEX);

        $ns_dataset = $this->runtime->pool . '/tc/registry/repos/' . $namespace;
        $this->ensure_dataset($ns_dataset, ['atime=off', 'compression=lz4']);

        $ns_dir = $this->repos_root . '/' . $namespace;
        Runtime::directory($ns_dir, 0755);

        $repo_path = $ns_dir . '/' . $name . '.git';
        $created = false;
        if (!is_dir($repo_path . '/objects')) {
            Runtime::directory($repo_path, 0775);
            $this->runtime->run(['git', 'init', '--bare', '--shared=group', $repo_path], 30);
            $this->runtime->run(['git', '--git-dir=' . $repo_path, 'config', 'http.receivepack', 'true'], 15);
            $this->runtime->run(['git', '--git-dir=' . $repo_path, 'config', 'receive.denyNonFastForwards', 'true'], 15);
            $this->runtime->run(['git', '--git-dir=' . $repo_path, 'config', 'receive.denyDeletes', 'true'], 15);
            $created = true;
        }

        $this->grant_registry_writable($repo_path);

        return [
            'status' => 'succeeded',
            'created' => $created,
            'namespace' => $namespace,
            'name' => $name,
            'repo_path' => $repo_path,
            'dataset' => $ns_dataset,
        ];
    }

    public function publish(array $request): array {
        $namespace = Runtime::match($request['namespace'] ?? null, self::NAMESPACE_REGEX);
        $name = Runtime::match($request['name'] ?? null, self::MODULE_REGEX);
        $version = Runtime::match($request['version'] ?? null, self::VERSION_REGEX);
        $ref = isset($request['ref']) && is_string($request['ref']) && trim($request['ref']) !== ''
            ? Runtime::match(trim($request['ref']), '[A-Za-z0-9._/-]{1,120}')
            : 'v' . $version;
        $subdir = isset($request['subdir']) && is_string($request['subdir']) && trim($request['subdir']) !== ''
            ? Runtime::match(trim($request['subdir'], '/'), '(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+')
            : '';

        $meta_path = $this->meta_path($namespace, $name, $version);
        $dataset = $this->module_dataset($namespace, $name, $version);
        $module_path = $this->module_path($namespace, $name, $version);

        $existing = $this->runtime->read($meta_path);
        if (is_array($existing) && ($existing['status'] ?? '') === 'succeeded' && is_dir($module_path)) {
            return $existing + ['idempotent' => true];
        }

        if ($this->dataset_exists($dataset) && is_dir($module_path)) {
            throw new RuntimeException('Module version already exists on ZFS and is immutable: ' . $namespace . '/' . $name . '@' . $version);
        }

        $work = sys_get_temp_dir() . '/tc-reg-pub-' . bin2hex(random_bytes(12));
        Runtime::directory($work, 0700);
        try {
            $staging = $work . '/module';
            Runtime::directory($staging, 0755);
            $commit = '';
            $repo_ready = false;

            try {
                if (isset($request['archive_b64'])) {
                    if (!is_string($request['archive_b64']) || strlen($request['archive_b64']) > 4 * (int) ceil(self::MAX_UPLOAD_BYTES / 3)) {
                        throw new RuntimeException('Uploaded module archive exceeds the 512 KiB limit');
                    }
                    $archive = base64_decode($request['archive_b64'], true);
                    if (!is_string($archive) || $archive === '' || strlen($archive) > self::MAX_UPLOAD_BYTES || !str_starts_with($archive, "\x1f\x8b")) {
                        throw new RuntimeException('Uploaded module archive is invalid');
                    }
                    $archive_path = $work . '/upload.tar.gz';
                    if (file_put_contents($archive_path, $archive, LOCK_EX) !== strlen($archive)) {
                        throw new RuntimeException('Cannot stage uploaded module archive');
                    }
                    $extract_dir = $work . '/extract';
                    Runtime::directory($extract_dir, 0755);
                    $this->extract_uploaded_archive($archive_path, $extract_dir);
                    $this->copy_tree($extract_dir, $staging);
                } else {
                    $repo_path = $this->repos_root . '/' . $namespace . '/' . $name . '.git';
                    if (!is_dir($repo_path . '/objects')) {
                        throw new RuntimeException('Module Git repository does not exist on host: ' . $namespace . '/' . $name);
                    }
                    $repo_ready = true;
                    $commit = $this->resolve_git_commit($repo_path, $ref, $version);
                    $tar_file = $work . '/export.tar';
                    $archive_cmd = ['git', '--git-dir=' . $repo_path, 'archive', '--format=tar', '--output=' . $tar_file, $commit];
                    if ($subdir !== '') {
                        $archive_cmd[] = $subdir;
                    }
                    $this->runtime->run($archive_cmd, 120);

                    $extract_dir = $work . '/extract';
                    Runtime::directory($extract_dir, 0755);
                    $this->extract_tar($tar_file, $extract_dir);
                    $picked = $subdir !== '' ? $extract_dir . '/' . $subdir : $extract_dir;
                    if (!is_dir($picked)) {
                        throw new RuntimeException('Requested module subdirectory not found in Git tree');
                    }
                    $this->copy_tree($picked, $staging);
                }

                $inspection = $this->verify_module_tree($staging, $name);
                $metadata = $this->extract_metadata($staging, $namespace, $name, $version);
            } catch (Throwable $error) {
                if (isset($request['archive_b64'])) {
                    $reason = Runtime::safe_error($error->getMessage());
                    return [
                        'status' => 'failed',
                        'error' => $reason !== '' ? 'Uploaded module package rejected: ' . $reason : 'Uploaded module package could not be validated.',
                    ];
                }
                throw $error;
            }

            $parent_ds = $this->runtime->pool . '/tc/registry/modules/' . $namespace . '/' . $name;
            $this->ensure_dataset($parent_ds, ['atime=off', 'compression=lz4']);
            $this->ensure_dataset($dataset, ['atime=off', 'compression=lz4', 'exec=off', 'setuid=off']);

            Runtime::directory($module_path, 0755);
            $this->copy_tree($staging, $module_path);

            $digest = self::manifest_digest($module_path);

            $archive_dir = $this->archives_root . '/' . $namespace . '/' . $name;
            Runtime::directory($archive_dir, 0755);
            $archive_path = $archive_dir . '/' . $version . '.tar.gz';
            $this->build_archive($module_path, $name, $archive_path);
            $archive_sha256 = (string) hash_file('sha256', $archive_path);
            $archive_bytes = (int) filesize($archive_path);

            $this->runtime->run(['zfs', 'set', 'readonly=on', $dataset], 30);
            $snapshot = $dataset . '@v1';
            $this->runtime->run(['zfs', 'snapshot', $snapshot], 30);

            $record = [
                'status' => 'succeeded',
                'namespace' => $namespace,
                'name' => $name,
                'version' => $version,
                'ref' => $ref,
                'commit' => $commit,
                'repo_ready' => $repo_ready,
                'digest' => $digest,
                'dataset' => $dataset,
                'snapshot' => $snapshot,
                'module_path' => $module_path,
                'archive_path' => $archive_path,
                'archive_sha256' => $archive_sha256,
                'archive_bytes' => $archive_bytes,
                'file_count' => $inspection['file_count'],
                'total_bytes' => $inspection['total_bytes'],
                'metadata' => $metadata,
                'published_at' => time(),
            ];
            $this->runtime->write($meta_path, $record, 0644);
            return $record;
        } finally {
            Runtime::remove_tree($work);
        }
    }

    public function list_modules(): array {
        $items = [];
        foreach (glob($this->meta_root . '/*.json') ?: [] as $path) {
            $data = $this->runtime->read($path);
            if (!is_array($data) || ($data['status'] ?? '') !== 'succeeded') {
                continue;
            }
            $data['present'] = is_dir((string) ($data['module_path'] ?? ''));
            $items[] = $data;
        }
        usort($items, static fn(array $a, array $b): int => strcmp(
            ($a['namespace'] ?? '') . '/' . ($a['name'] ?? '') . '@' . ($a['version'] ?? ''),
            ($b['namespace'] ?? '') . '/' . ($b['name'] ?? '') . '@' . ($b['version'] ?? '')
        ));
        return [
            'status' => 'succeeded',
            'modules' => $items,
        ];
    }

    public function inspect_module(array $request): array {
        $namespace = Runtime::match($request['namespace'] ?? null, self::NAMESPACE_REGEX);
        $name = Runtime::match($request['name'] ?? null, self::MODULE_REGEX);
        $version = Runtime::match($request['version'] ?? null, self::VERSION_REGEX);
        $meta = $this->runtime->read($this->meta_path($namespace, $name, $version));
        if (!is_array($meta)) {
            return ['status' => 'failed', 'error' => 'Module version not found on host'];
        }
        $module_path = $this->module_path($namespace, $name, $version);
        $present = is_dir($module_path);
        $verified = $present && self::manifest_digest($module_path) === ($meta['digest'] ?? '');
        return $meta + ['present' => $present, 'verified' => $verified];
    }

    public function yank(array $request): array {
        $namespace = Runtime::match($request['namespace'] ?? null, self::NAMESPACE_REGEX);
        $name = Runtime::match($request['name'] ?? null, self::MODULE_REGEX);
        $version = Runtime::match($request['version'] ?? null, self::VERSION_REGEX);

        $dataset = $this->module_dataset($namespace, $name, $version);
        if ($this->dataset_exists($dataset)) {
            $this->runtime->run(['zfs', 'set', 'readonly=off', $dataset], 30);
            $this->runtime->run(['zfs', 'destroy', '-rf', $dataset], 60);
        }
        $module_path = $this->module_path($namespace, $name, $version);
        if (is_dir($module_path)) {
            Runtime::remove_tree($module_path);
        }
        Runtime::remove($this->archives_root . '/' . $namespace . '/' . $name . '/' . $version . '.tar.gz');
        Runtime::remove($this->meta_path($namespace, $name, $version));

        return [
            'status' => 'succeeded',
            'namespace' => $namespace,
            'name' => $name,
            'version' => $version,
        ];
    }

    public function module_dataset(string $namespace, string $name, string $version): string {
        return $this->runtime->pool . '/tc/registry/modules/' . $namespace . '/' . $name . '/' . $version;
    }

    public function module_path(string $namespace, string $name, string $version): string {
        return $this->modules_root . '/' . $namespace . '/' . $name . '/' . $version;
    }

    public function meta_path(string $namespace, string $name, string $version): string {
        return $this->meta_root . '/' . $namespace . '__' . $name . '__' . $version . '.json';
    }

    public static function manifest_digest(string $dir): string {
        $root = realpath($dir);
        if ($root === false) {
            throw new RuntimeException('Cannot inspect registry module directory');
        }
        $entries = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $full = $item->getPathname();
            $relative = str_replace('\\', '/', substr($full, strlen($root) + 1));
            if ($item->isLink()) {
                $entries[$relative] = 'L ' . $relative . ' ' . (string) readlink($full);
            } elseif ($item->isDir()) {
                $entries[$relative] = 'D ' . $relative;
            } else {
                $hash = hash_file('sha256', $full);
                if ($hash === false) {
                    throw new RuntimeException('Cannot hash registry module file');
                }
                $entries[$relative] = 'F ' . $relative . ' ' . $hash;
            }
        }
        ksort($entries, SORT_STRING);
        return 'sha256:' . hash('sha256', implode("\n", $entries) . "\n");
    }

    private function resolve_git_commit(string $repo_path, string $ref, string $version): string {
        $candidates = array_values(array_unique([$ref, 'refs/tags/' . $ref, 'refs/tags/v' . $version, 'refs/tags/' . $version]));
        foreach ($candidates as $candidate) {
            try {
                $sha = trim($this->runtime->run(['git', '--git-dir=' . $repo_path, 'rev-parse', '--verify', $candidate . '^{commit}'], 15));
                if (preg_match('/\A[a-f0-9]{40}\z/', $sha) === 1) {
                    return $sha;
                }
            } catch (RuntimeException) {
                // Try next ref format.
            }
        }
        throw new RuntimeException('Git ref not found in repository: ' . $ref);
    }

    private function extract_tar(string $tar_file, string $dest): void {
        if (class_exists('PharData')) {
            $phar = new PharData($tar_file);
            $phar->extractTo($dest, null, true);
            return;
        }
        $this->runtime->run(['tar', '-xf', $tar_file, '-C', $dest], 120);
    }

    private function extract_uploaded_archive(string $archive_path, string $dest): void {
        $tar_path = substr($archive_path, 0, -3);
        $input = gzopen($archive_path, 'rb');
        $output = fopen($tar_path, 'wb');
        if ($input === false || $output === false) {
            if (is_resource($input)) {
                gzclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            throw new RuntimeException('Cannot read uploaded module archive');
        }

        $expanded_bytes = 0;
        try {
            while (!gzeof($input)) {
                $chunk = gzread($input, 8192);
                if (!is_string($chunk) || $chunk === '') {
                    if (!gzeof($input)) {
                        throw new RuntimeException('Uploaded module archive is corrupt');
                    }
                    break;
                }
                $expanded_bytes += strlen($chunk);
                if ($expanded_bytes > self::MAX_TOTAL_BYTES + 1048576) {
                    throw new RuntimeException('Uploaded module archive expands beyond the 64 MiB limit');
                }
                if (fwrite($output, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('Cannot stage uploaded module archive');
                }
            }
        } finally {
            gzclose($input);
            fclose($output);
        }

        if ($expanded_bytes === 0) {
            throw new RuntimeException('Uploaded module archive is empty');
        }

        $archive = new PharData($tar_path);
        $iterator = new RecursiveIteratorIterator($archive, RecursiveIteratorIterator::SELF_FIRST);
        $file_count = 0;
        $total_bytes = 0;
        foreach ($iterator as $key => $entry) {
            if (!$entry instanceof PharFileInfo) {
                throw new RuntimeException('Uploaded module archive contains an invalid entry');
            }
            $path = str_replace('\\', '/', (string) $key);
            $prefix = 'phar://' . str_replace('\\', '/', $tar_path) . '/';
            if (str_starts_with($path, $prefix)) {
                $path = substr($path, strlen($prefix));
            } elseif (str_starts_with($path, str_replace('\\', '/', $tar_path) . '/')) {
                $path = substr($path, strlen(str_replace('\\', '/', $tar_path)) + 1);
            }
            $parts = explode('/', trim($path, '/'));
            if ($path === '' || str_starts_with($path, '/') || preg_match('/^[a-zA-Z]:/', $path) === 1 || in_array('..', $parts, true)) {
                throw new RuntimeException('Uploaded module archive contains an unsafe path');
            }
            if ($entry->isLink() || (!$entry->isDir() && !$entry->isFile())) {
                throw new RuntimeException('Uploaded module archive may contain only regular files and directories');
            }
            if ($entry->isFile()) {
                $size = (int) $entry->getSize();
                if ($size > self::MAX_FILE_BYTES) {
                    throw new RuntimeException('Module file exceeds 20 MiB limit');
                }
                $total_bytes += $size;
                $file_count++;
                if ($total_bytes > self::MAX_TOTAL_BYTES || $file_count > 10000) {
                    throw new RuntimeException('Module archive exceeds the file or 64 MiB limit');
                }
            }
        }
        unset($iterator, $archive);

        $this->extract_tar($tar_path, $dest);
    }

    private function build_archive(string $module_path, string $name, string $archive_path): void {
        $tmp_tar = $archive_path . '.tmp.' . bin2hex(random_bytes(4)) . '.tar';
        $tmp_gz = $tmp_tar . '.gz';
        try {
            $phar = new PharData($tmp_tar);
            $root = realpath($module_path);
            if ($root === false) {
                throw new RuntimeException('Cannot open module directory for archiving');
            }
            $files = [];
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $item) {
                if ($item->isLink() || !$item->isFile()) {
                    continue;
                }
                $rel = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));
                $files[$rel] = $item->getPathname();
            }
            ksort($files, SORT_STRING);
            foreach ($files as $rel => $full) {
                $phar->addFile($full, $name . '/' . $rel);
            }
            $phar->compress(Phar::GZ);
            unset($phar);
            Runtime::remove($tmp_tar);
            if (!rename($tmp_gz, $archive_path)) {
                throw new RuntimeException('Cannot publish module archive');
            }
            if (PHP_OS_FAMILY !== 'Windows') {
                chmod($archive_path, 0644);
            }
        } catch (Throwable $e) {
            Runtime::remove($tmp_tar);
            Runtime::remove($tmp_gz);
            throw $e instanceof RuntimeException ? $e : new RuntimeException('Cannot build module archive: ' . $e->getMessage());
        }
    }

    /**
     * @return array{file_count: int, total_bytes: int}
     */
    private function verify_module_tree(string $dir, string $name): array {
        $root = realpath($dir);
        if ($root === false) {
            throw new RuntimeException('Module staging directory missing');
        }
        $file_count = 0;
        $total_bytes = 0;
        $has_php = false;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $full = $item->getPathname();
            if ($item->isLink()) {
                $resolved = realpath($full);
                if ($resolved === false || ($resolved !== $root && !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR))) {
                    throw new RuntimeException('Module symlink escapes module root');
                }
                continue;
            }
            if ($item->isDir()) {
                if ($item->getFilename() === '.git') {
                    throw new RuntimeException('Module archive must not contain .git metadata');
                }
                continue;
            }
            $size = (int) $item->getSize();
            if ($size > self::MAX_FILE_BYTES) {
                throw new RuntimeException('Module file exceeds 20 MiB limit');
            }
            $total_bytes += $size;
            if ($total_bytes > self::MAX_TOTAL_BYTES) {
                throw new RuntimeException('Module exceeds 64 MiB uncompressed size limit');
            }
            $file_count++;
            if (str_ends_with(strtolower($item->getFilename()), '.php')) {
                $has_php = true;
            }
        }
        if ($file_count === 0 || !$has_php) {
            throw new RuntimeException('Trongate module "' . $name . '" must contain at least one PHP file');
        }
        return ['file_count' => $file_count, 'total_bytes' => $total_bytes];
    }

    private function extract_metadata(string $dir, string $namespace, string $name, string $version): array {
        $meta = [
            'namespace' => $namespace,
            'name' => $name,
            'version' => $version,
            'title' => ucwords(str_replace('_', ' ', $name)),
            'summary' => '',
            'description' => '',
            'license' => 'MIT',
            'author' => $namespace,
            'homepage' => '',
            'tags' => [],
            'category' => 'general',
            'php' => '>=8.2',
            'readme' => '',
        ];

        $manifest_file = $dir . '/module.json';
        if (is_file($manifest_file) && filesize($manifest_file) <= self::MAX_MANIFEST_BYTES) {
            $decoded = json_decode((string) file_get_contents($manifest_file), true);
            if (is_array($decoded)) {
                foreach (['title', 'summary', 'description', 'license', 'author', 'homepage', 'category', 'php'] as $field) {
                    if (isset($decoded[$field]) && is_string($decoded[$field]) && trim($decoded[$field]) !== '') {
                        $meta[$field] = substr(trim($decoded[$field]), 0, 1000);
                    }
                }
                if (isset($decoded['tags']) && is_array($decoded['tags'])) {
                    $tags = [];
                    foreach ($decoded['tags'] as $tag) {
                        if (is_string($tag) && preg_match('/\A[a-z0-9][a-z0-9-]{0,31}\z/', strtolower(trim($tag))) === 1) {
                            $tags[] = strtolower(trim($tag));
                        }
                    }
                    $meta['tags'] = array_values(array_unique(array_slice($tags, 0, 12)));
                }
            }
        }

        $readme_file = $dir . '/README.md';
        if (is_file($readme_file)) {
            $raw = (string) file_get_contents($readme_file, false, null, 0, self::MAX_README_BYTES);
            $meta['readme'] = trim($raw);
            if ($meta['summary'] === '') {
                $meta['summary'] = self::summary_from_readme($raw);
            }
        }

        return $meta;
    }

    private static function summary_from_readme(string $readme): string {
        $lines = preg_split('/\r?\n/', trim($readme)) ?: [];
        $buf = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                if ($buf !== []) {
                    break;
                }
                continue;
            }
            if (str_starts_with($trimmed, '#') || preg_match('/^Version\s+\d/i', $trimmed) === 1 || str_starts_with($trimmed, '```')) {
                continue;
            }
            $buf[] = $trimmed;
        }
        return substr(implode(' ', $buf), 0, 280);
    }

    private function ensure_dataset(string $dataset, array $props = []): void {
        if ($this->dataset_exists($dataset)) {
            return;
        }
        $cmd = ['zfs', 'create', '-p'];
        foreach ($props as $prop) {
            $cmd[] = '-o';
            $cmd[] = $prop;
        }
        $cmd[] = $dataset;
        $this->runtime->run($cmd, 30);
    }

    private function dataset_exists(string $dataset): bool {
        try {
            $this->runtime->run(['zfs', 'list', '-H', '-o', 'name', $dataset], 15);
            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    private function copy_tree(string $source, string $target): void {
        Runtime::directory($target, 0755);
        foreach (new FilesystemIterator($source, FilesystemIterator::SKIP_DOTS) as $entry) {
            $name = $entry->getFilename();
            if ($name === '.git') {
                continue;
            }
            $src = $entry->getPathname();
            $dst = $target . '/' . $name;
            if ($entry->isLink()) {
                $link = readlink($src);
                if ($link === false || !symlink($link, $dst)) {
                    throw new RuntimeException('Cannot copy module symlink');
                }
            } elseif ($entry->isDir()) {
                $this->copy_tree($src, $dst);
            } else {
                if (!copy($src, $dst)) {
                    throw new RuntimeException('Cannot copy module file');
                }
                if (PHP_OS_FAMILY !== 'Windows') {
                    chmod($dst, 0644);
                }
            }
        }
    }

    private function grant_registry_writable(string $path): void {
        if (PHP_OS_FAMILY === 'Windows' || $this->runtime->root !== '/var/db/tc/runtime') {
            return;
        }
        $this->runtime->run(['chown', '-R', 'www:www', $path], 30);
    }
}
