<?php
/** Runs as the isolated, unprivileged build user. No application or fleet credentials. */
class Builder {
    public string $home;

    public function __construct(?string $home = null) {
        $this->home = $home ?? (getenv('HOME') ?: throw new RuntimeException('Builder home is missing'));
    }

    public function run(array $args, int $timeout = 1800): string {
        $output = tmpfile();
        $errors = tmpfile();
        $process = proc_open($args, [0 => ['pipe', 'r'], 1 => $output, 2 => $errors], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('builder step failed: ' . $args[0]);
        }
        fclose($pipes[0]);
        $deadline = microtime(true) + $timeout;
        try {
            do {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    break;
                }
                if (microtime(true) >= $deadline) {
                    proc_terminate($process, 9);
                    throw new RuntimeException('builder step timed out: ' . $args[0]);
                }
                usleep(20000);
            } while (true);
            if ($status['exitcode'] !== 0) {
                throw new RuntimeException('builder step failed: ' . $args[0]);
            }
            rewind($output);
            return trim(stream_get_contents($output));
        } finally {
            proc_close($process);
            fclose($output);
            fclose($errors);
        }
    }

    public function free_space(): float {
        $space = disk_free_space($this->home . '/.local/state/tc-build');
        if ($space === false) {
            throw new RuntimeException('Cannot determine builder free space');
        }
        return $space;
    }

    private function write(string $path, array $value): void {
        $temporary = $path . '.tmp';
        $handle = fopen($temporary, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Cannot write build journal');
        }
        try {
            chmod($temporary, 0600);
            $data = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (fwrite($handle, $data) !== strlen($data) || !fflush($handle) || !fsync($handle)) {
                throw new RuntimeException('Cannot persist build journal');
            }
        } finally {
            fclose($handle);
        }
        if (!rename($temporary, $path)) {
            throw new RuntimeException('Cannot publish build journal');
        }
        // Persist the renamed journal entry before a build can outlive this process.
        if (PHP_OS_FAMILY !== 'Windows') {
            $directory = fopen(dirname($path), 'r');
            if ($directory === false) {
                throw new RuntimeException('Cannot sync builder state directory');
            }
            try {
                if (!fsync($directory)) {
                    throw new RuntimeException('Cannot sync builder state directory');
                }
            } finally {
                fclose($directory);
            }
        }
    }

    private function remove_work(string $path): void {
        // Only called with the newly created, random build workspace; never follow links.
        foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $item) {
            if ($item->isDir() && !$item->isLink()) {
                $this->remove_work($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($path);
    }

    public function build(array $request): array {
        foreach (['operation' => '[a-f0-9]{32}', 'repository' => '[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+',
            'commit' => '[a-f0-9]{40}', 'tag' => '[a-z0-9][a-z0-9.:/_-]+:[a-z0-9._-]+'] as $key => $pattern) {
            if (!is_string($request[$key] ?? null) || !preg_match('~^' . $pattern . '$~D', $request[$key])) {
                throw new InvalidArgumentException('invalid builder input');
            }
        }
        $root = $this->home . '/.local/state/tc-build';
        if (!is_dir($root) && !mkdir($root, 0700, true)) {
            throw new RuntimeException('Cannot create builder state');
        }
        $result = $root . '/' . $request['operation'] . '.json';
        $lock = fopen($root . '/lock', 'ab');
        if (!$lock || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('Cannot lock builder');
        }
        try {
            if (is_file($result)) {
                $recorded = json_decode(file_get_contents($result), true, 512, JSON_THROW_ON_ERROR);
                return ($recorded['status'] ?? '') === 'succeeded' ? $recorded :
                    ['status' => 'uncertain', 'reason' => 'interrupted build; inspect builder before issuing a new operation'];
            }
            if ($this->free_space() < 5 * 1024 ** 3) {
                return ['status' => 'failed', 'reason' => 'Build needs at least 5 GB of free disk space. Free space or select a dedicated builder.'];
            }
            $local = ($request['output'] ?? '') === 'archive';
            $this->run(['podman', 'image', 'prune', '--force', '--filter', 'until=24h']);
            // Persist before work that may outlive the SSH session; never repeat an interrupted build.
            $this->write($result, ['status' => 'running', 'operation' => $request['operation']]);
            $work = sys_get_temp_dir() . '/tc-build-' . bin2hex(random_bytes(16));
            if (!mkdir($work, 0700)) {
                throw new RuntimeException('Cannot create build workspace');
            }
            try {
                $source = $work . '/src';
                $this->run(['git', 'init', $source]);
                $this->run(['git', '-C', $source, 'remote', 'add', 'origin', 'https://github.com/' . $request['repository'] . '.git']);
                $this->run(['git', '-C', $source, '-c', 'protocol.file.allow=never', 'fetch', '--depth=1', 'origin', $request['commit']]);
                $this->run(['git', '-C', $source, 'checkout', '--detach', 'FETCH_HEAD']);
                if ($this->run(['git', '-C', $source, 'rev-parse', 'HEAD']) !== $request['commit']) {
                    throw new RuntimeException('source commit mismatch');
                }
                $sourceRoot = realpath($source);
                foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $item) {
                    if ($item->isLink()) {
                        $resolved = realpath($item->getPathname());
                        if ($resolved === false || ($resolved !== $sourceRoot && !str_starts_with($resolved, $sourceRoot . DIRECTORY_SEPARATOR))) {
                            throw new RuntimeException('source symlink escapes context');
                        }
                    }
                }
                if (!is_file($source . '/public/index.php') || !is_file($source . '/config/config.php')) {
                    throw new RuntimeException('source must be a Trongate application');
                }
                $dockerfile = $request['dockerfile'];
                if (!is_string($dockerfile)) {
                    throw new InvalidArgumentException('invalid Dockerfile');
                }
                if (is_file($source . '/Caddyfile')) {
                    $dockerfile .= "COPY Caddyfile /Caddyfile\n";
                }
                if (file_put_contents($work . '/Dockerfile', $dockerfile) === false) {
                    throw new RuntimeException('Cannot write generated Dockerfile');
                }
                $this->run(['systemd-run', '--user', '--scope', '--quiet', '--property=MemoryMax=' . ($local ? '1G' : '2G'),
                    '--property=CPUQuota=' . ($local ? '100%' : '200%'), '--property=TasksMax=512',
                    'podman', 'build', '--memory=' . ($local ? '1g' : '2g'), '--cpu-quota=' . ($local ? '100000' : '200000'), '--force-rm',
                    '--security-opt=no-new-privileges', '--tag', $request['tag'], '--file', $work . '/Dockerfile', $source]);
                $imageName = substr($request['tag'], 0, strrpos($request['tag'], ':'));
                if ($local) {
                    $imageId = $this->run(['podman', 'image', 'inspect', '--format', '{{.Id}}', $request['tag']]);
                    $imageId = preg_replace('/^sha256:/', '', $imageId);
                    if (!preg_match('/^[a-f0-9]{64}$/D', $imageId)) {
                        throw new RuntimeException('invalid local image ID');
                    }
                    $this->run(['podman', 'save', '--format', 'oci-archive', '--output', $root . '/' . $request['operation'] . '.oci', $request['tag']]);
                    $image = $imageName . '@sha256:' . $imageId;
                    $this->run(['podman', 'rmi', '--no-prune', $request['tag']]);
                } else {
                    $auth = $this->home . '/.config/containers/auth.json';
                    $this->run(array_merge(['podman', 'push'], is_file($auth) ? ['--authfile', $auth] : [],
                        ['--digestfile', $work . '/digest', $request['tag']]));
                    $digest = trim(file_get_contents($work . '/digest'));
                    if (!preg_match('/^sha256:[a-f0-9]{64}$/D', $digest)) {
                        throw new RuntimeException('registry did not return a digest');
                    }
                    $image = $imageName . '@' . $digest;
                }
                $output = ['status' => 'succeeded', 'image' => $image, 'commit' => $request['commit']];
                $this->write($result, $output);
                return $output;
            } finally {
                $this->remove_work($work);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    umask(0077);
    try {
        $data = stream_get_contents(STDIN, 1048577);
        if (strlen($data) > 1048576) {
            throw new InvalidArgumentException('request too large');
        }
        $request = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        $builder = new Builder();
        if (($request['action'] ?? '') === 'metrics') {
            require_once __DIR__ . '/tc_metrics.php';
            $result = Metrics::collect($builder->home);
        } else {
            $result = $builder->build($request);
        }
        echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
    } catch (Throwable $error) {
        // Never disclose request bodies, credentials or customer process output.
        echo json_encode(['status' => 'uncertain', 'error' => get_class($error)]) . "\n";
    }
}

