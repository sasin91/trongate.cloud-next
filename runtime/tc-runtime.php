<?php
declare(strict_types=1);

/** Root-owned JSON host protocol. All command arguments stay separate from shell text. */
class Runtime {
    public ?Closure $progress = null;

    public function __construct(public string $root = '/var/lib/tc/runtime') {
        foreach (['', '/operations', '/apps', '/routes', '/roots', '/units', '/enabled'] as $directory) {
            self::directory($root . $directory);
        }
    }

    public static function directory(string $path, int $mode = 0700): void {
        if (!is_dir($path) && !mkdir($path, $mode, true) && !is_dir($path)) {
            throw new RuntimeException('Cannot create runtime directory');
        }
    }

    public static function process(array $args, string $input = '', int $timeout = 120, ?string $cwd = null): string {
        // File descriptors avoid pipe deadlocks and work on Windows as well as Linux.
        $stdin = tmpfile();
        $stdout = tmpfile();
        $stderr = tmpfile();
        if ($stdin === false || $stdout === false || $stderr === false) {
            throw new RuntimeException('Cannot allocate controlled command streams');
        }
        $process = null;
        try {
            if (fwrite($stdin, $input) !== strlen($input)) {
                throw new RuntimeException('Controlled command input failed');
            }
            rewind($stdin);
            $process = proc_open($args, [0 => $stdin, 1 => $stdout, 2 => $stderr], $pipes, $cwd);
            if (!is_resource($process)) {
                throw new RuntimeException('Cannot start controlled command');
            }
            $deadline = microtime(true) + $timeout;
            do {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    if ($status['exitcode'] !== 0) {
                        throw new RuntimeException('Controlled command failed: ' . basename($args[0]));
                    }
                    rewind($stdout);
                    return trim(stream_get_contents($stdout));
                }
                if (microtime(true) > $deadline) {
                    proc_terminate($process, 9);
                    throw new RuntimeException('Controlled command timed out');
                }
                usleep(10000);
            } while (true);
        } finally {
            if (is_resource($process)) {
                proc_close($process);
            }
            fclose($stdin);
            fclose($stdout);
            fclose($stderr);
        }
    }

    public function run(array $args, int $timeout = 120): string {
        return self::process($args, '', $timeout);
    }

    public function checkpoint(string $phase): void {
        if ($this->progress !== null) {
            ($this->progress)($phase);
        }
    }

    public function write(string $path, mixed $value): void {
        self::directory(dirname($path));
        $temporary = $path . '.tmp';
        $handle = fopen($temporary, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Cannot write runtime journal');
        }
        try {
            chmod($temporary, 0600);
            $data = is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $offset = 0;
            while ($offset < strlen($data)) {
                $written = fwrite($handle, substr($data, $offset));
                if (!$written) {
                    throw new RuntimeException('Runtime journal write failed');
                }
                $offset += $written;
            }
            if (!fflush($handle) || !fsync($handle)) {
                throw new RuntimeException('Runtime journal sync failed');
            }
        } finally {
            fclose($handle);
        }
        if (!rename($temporary, $path)) {
            throw new RuntimeException('Runtime journal replace failed');
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            $directory = fopen(dirname($path), 'r');
            if ($directory === false) {
                throw new RuntimeException('Runtime directory sync failed');
            }
            try {
                if (!fsync($directory)) {
                    throw new RuntimeException('Runtime directory sync failed');
                }
            } finally {
                fclose($directory);
            }
        }
    }

    public function read(string $path, mixed $default = null): mixed {
        return is_file($path) ? json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) : $default;
    }

    public static function match(mixed $value, string $expression): string {
        if (!is_string($value) || preg_match('~\A(?:' . str_replace('~', '\\~', $expression) . ')\z~D', $value) !== 1) {
            throw new InvalidArgumentException('Invalid runtime input');
        }
        return $value;
    }

    public static function remove(string $path): void {
        if ((file_exists($path) || is_link($path)) && !unlink($path)) {
            throw new RuntimeException('Cannot remove runtime file');
        }
    }

    public static function remove_tree(string $path): void {
        if (is_link($path) || !is_dir($path)) {
            self::remove($path);
            return;
        }
        foreach (new FilesystemIterator($path) as $entry) {
            self::remove_tree($entry->getPathname());
        }
        if (!rmdir($path)) {
            throw new RuntimeException('Cannot remove runtime directory');
        }
    }

    public function health(string $url, int $attempts = 20): bool {
        for ($i = 0; $i < $attempts; $i++) {
            try {
                $this->run(['curl', '--fail', '--silent', '--max-time', '5', '--output', '/dev/null', $url], 10);
                return true;
            } catch (RuntimeException) {
                sleep(1);
            }
        }
        return false;
    }

    public function proxy(): void {
        $text = "{\n admin 127.0.0.1:2019\n}\n";
        foreach (glob($this->root . '/routes/*.caddy') as $path) {
            $text .= file_get_contents($path) . "\n";
        }
        $this->write($this->root . '/Caddyfile', $text);
        foreach (['validate', 'reload'] as $command) {
            $this->run(['caddy', $command, '--config', $this->root . '/Caddyfile', '--adapter', 'caddyfile']);
        }
    }

    public function route(string $app, ?array $release): void {
        $path = $this->root . '/routes/' . $app . '.caddy';
        if ($release === null) {
            self::remove($path);
        } else {
            $domain = self::match($release['domain'], '[a-z0-9]+(?:[.-][a-z0-9]+)+');
            $this->write($path, $domain . " {\n reverse_proxy 127.0.0.1:" . (int) $release['port'] . "\n}\n");
        }
        $this->proxy();
    }

    public function stop(?array $release): void {
        if ($release !== null) {
            self::remove($this->root . '/enabled/' . $release['unit']);
            $this->run(['systemctl', 'daemon-reload']);
            $this->run(['systemctl', 'stop', $release['unit']]);
        }
    }

    public function start(array $release): void {
        $this->write($this->root . '/enabled/' . $release['unit'], '');
        $this->run(['systemctl', 'daemon-reload']);
        $this->run(['systemctl', 'start', $release['unit']]);
    }

    public function healthy(?array $release): bool {
        return $release !== null && $this->health('http://127.0.0.1:' . $release['port'] . $release['health']);
    }

    public function stage(string $app, string $operation, array $spec, array $secrets, ?array $files = null, ?string $registry_auth = null): array {
        $image = self::match($spec['image'], '[a-z0-9][a-z0-9./:_-]+@sha256:[a-f0-9]{64}');
        self::match($spec['health'], '/[a-zA-Z0-9/_.-]*');
        $name = 'tc-' . $app . '-' . $operation;
        $directory = $this->root . '/apps/' . $app . '/releases/' . $operation;
        self::directory($directory);
        $environment = '';
        foreach ($secrets as $key => $value) {
            self::match($key, '[A-Z_][A-Z0-9_]*');
            if (!is_string($value) || strpbrk($value, "\r\n\0") !== false) {
                throw new InvalidArgumentException('Environment secrets must be single-line strings');
            }
            $environment .= $key . '=' . $value . "\n";
        }
        $this->write($directory . '/environment', $environment);
        $auth = [];
        $auth_file = $directory . '/registry-auth';
        if ($registry_auth !== null) {
            json_decode($registry_auth, true, 512, JSON_THROW_ON_ERROR);
            $this->write($auth_file, $registry_auth);
            $auth = ['--authfile', $auth_file];
        }
        try {
            $this->checkpoint('pulling-image');
            if (str_starts_with($image, 'localhost/tc/')) {
                $image_id = explode('@', $image)[1];
                $observed = $this->run(['podman', 'image', 'inspect', '--format', '{{.Id}}', $image_id]);
                if (preg_replace('/^sha256:/', '', $observed) !== substr($image_id, 7)) {
                    throw new RuntimeException('Local build image is missing; rebuild on this server');
                }
                $image = $image_id;
            } else {
                $this->run(array_merge(['podman', 'pull'], $auth, [$image]), 600);
            }
        } finally {
            self::remove($auth_file);
        }
        $args = ['podman', 'create', '--name', $name, '--label', 'tc.operation=' . $operation,
            '--publish', '127.0.0.1::' . (int) $spec['container_port'], '--memory', (int) $spec['memory_mb'] . 'm',
            '--memory-swap', ((int) $spec['memory_mb'] * 2) . 'm', '--cpu-shares', (string) max(2, round($spec['cpus'] * 1024)),
            '--pids-limit', '256', '--cap-drop', 'ALL', '--cap-add', 'NET_BIND_SERVICE', '--security-opt', 'no-new-privileges',
            '--env', 'PORT=' . (int) $spec['container_port'], '--env-file', $directory . '/environment'];
        foreach (($spec['mounts'] ?? []) as $volume => $target) {
            self::match($volume, '[a-z][a-z0-9-]{0,39}');
            self::match($target, '/app/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+');
            $data = $this->root . '/apps/' . $app . '/data/' . $volume;
            self::directory($data, 0750);
            array_push($args, '--volume', $data . ':' . $target);
        }
        $index = 0;
        foreach (($files ?? []) as $target => $content) {
            self::match($target, '/app/config/[A-Za-z0-9_-]+\.php');
            $source = $directory . '/config-' . $index++;
            $this->write($source, $content);
            array_push($args, '--volume', $source . ':' . $target . ':ro');
        }
        $args[] = $image;
        $this->checkpoint('creating-container');
        $this->run($args);
        $unit = $name . '.service';
        $podman = $this->run(['which', 'podman']);
        $this->checkpoint('writing-unit');
        $this->write($this->root . '/units/' . $unit, "[Unit]\nAfter=network-online.target\nWants=network-online.target\n[Service]\nRestart=always\nRestartSec=3\nTimeoutStopSec=45\nExecStart=$podman start --attach $name\nExecStop=$podman stop --time 30 $name\n[Install]\nWantedBy=multi-user.target\n");
        $this->run(['systemctl', 'daemon-reload']);
        $this->checkpoint('starting-candidate');
        $this->start(['unit' => $unit]);
        $port = null;
        for ($i = 0; $i < 25; $i++) {
            $inspection = json_decode($this->run(['podman', 'inspect', $name]), true, 512, JSON_THROW_ON_ERROR)[0];
            $mapping = $inspection['NetworkSettings']['Ports'][$spec['container_port'] . '/tcp'] ?? null;
            if ($mapping) {
                $port = (int) $mapping[0]['HostPort'];
                break;
            }
            usleep(200000);
        }
        if ($port === null) {
            throw new RuntimeException('Candidate port did not become available');
        }
        unset($spec['secrets']);
        $release = array_replace($spec, ['operation' => $operation, 'unit' => $unit, 'container' => $name, 'port' => $port]);
        $this->write($directory . '/release.json', $release);
        return $release;
    }

    public function allocate(string $app): array {
        require_once __DIR__ . '/tc_resources.php';
        return (new Resources($this))->apply(incoming: $app, starting: true);
    }

    public function app_operation(array $request): array {
        $operation = self::match($request['operation'], '[a-f0-9]{32}');
        $app = self::match($request['app'], '[a-z][a-z0-9-]{0,39}');
        $journal_path = $this->root . '/jobs/' . $operation . '.json';
        $state_path = $this->root . '/apps/' . $app . '/state.json';
        $state = $this->read($state_path, ['active' => null, 'previous' => null]);
        $journal = $this->read($journal_path);
        $this->progress = function (string $phase) use (&$journal, $journal_path): void {
            if ($journal) {
                $journal['phase'] = $phase;
                $journal['events'][] = ['step' => $phase, 'at' => time()];
                $this->write($journal_path, $journal);
            }
        };
        if ($journal && $journal['status'] === 'waiting') {
            if (empty($request['go_live'])) {
                return $journal;
            }
            if ($state != $journal['before']) {
                throw new RuntimeException('The active release changed while waiting to go live');
            }
            try {
                return $this->complete_candidate($app, $state_path, $journal_path, $journal, $request);
            } catch (Throwable $error) {
                $journal['error'] = $error instanceof RuntimeException ? $error->getMessage() : get_class($error);
                return $this->recover($app, $journal_path, $state_path, $journal);
            }
        }
        if ($journal) {
            return in_array($journal['status'], ['succeeded', 'failed'], true) ? $journal : $this->recover($app, $journal_path, $state_path, $journal);
        }
        $staged = $this->read($this->root . '/apps/' . $app . '/staged.json');
        if ($staged && $staged['operation'] !== $operation) {
            throw new RuntimeException('Finish the staged deployment before starting another one');
        }
        $journal = ['operation' => $operation, 'status' => 'running', 'phase' => 'intent', 'before' => $state,
            'public_check' => $request['public_check'] ?? true, 'events' => []];
        $this->write($journal_path, $journal);
        try {
            if ($request['action'] === 'rollback') {
                $candidate = $state['previous'];
                if (!$candidate) {
                    throw new RuntimeException('No retained previous release');
                }
                $this->checkpoint('allocating-resources');
                $this->allocate($app);
                $this->start($candidate);
            } else {
                $spec = $request['release'];
                foreach (glob($this->root . '/apps/*', GLOB_ONLYDIR) as $other) {
                    if (basename($other) === $app) {
                        continue;
                    }
                    $active = $this->read($other . '/state.json', [])['active'] ?? null;
                    $staged_other = $this->read($other . '/staged.json', []);
                    $pending = $this->read($this->root . '/jobs/' . ($staged_other['operation'] ?? '') . '.json', [])['candidate'] ?? null;
                    foreach ([$active, $pending] as $item) {
                        if ($item && $item['domain'] === $spec['domain']) {
                            throw new RuntimeException('Domain is already assigned');
                        }
                    }
                }
                $this->checkpoint('allocating-resources');
                $allocation = $this->allocate($app);
                $spec = array_replace($spec, ['memory_mb' => $allocation['memory_mb'], 'cpus' => $allocation['cpus']]);
                $candidate = $this->stage($app, $operation, $spec, $request['secret_values'] ?? [], $request['file_values'] ?? [], $request['registry_auth'] ?? null);
            }
            $journal['candidate'] = $candidate;
            $journal['phase'] = 'candidate';
            $this->write($journal_path, $journal);
            if ($request['action'] !== 'rollback' && !empty($request['release']['migration'])) {
                if (($request['release']['schema_compatible'] ?? null) !== true) {
                    throw new RuntimeException('Migration must be backward compatible');
                }
                $journal['phase'] = 'migration-started';
                $this->write($journal_path, $journal);
                $this->run(array_merge(['podman', 'exec', $candidate['container']], $request['release']['migration']), 600);
                $journal['phase'] = 'migration-completed';
                $journal['migration'] = 'completed; application rollback does not undo data changes';
                $this->write($journal_path, $journal);
            }
            return $this->complete_candidate($app, $state_path, $journal_path, $journal, $request);
        } catch (Throwable $error) {
            $journal['error'] = $error instanceof RuntimeException || $error instanceof InvalidArgumentException ? $error->getMessage() : get_class($error);
            $this->write($journal_path, $journal);
            return $this->recover($app, $journal_path, $state_path, $journal);
        }
    }

    public function complete_candidate(string $app, string $state_path, string $journal_path, array &$journal, array $request): array {
        $candidate = $journal['candidate'];
        $previous = $journal['before']['active'];
        $this->checkpoint('checking-local');
        if (!$this->healthy($candidate)) {
            throw new RuntimeException('Candidate health check failed');
        }
        if (!empty($request['defer_public']) && ($request['public_check'] ?? true) && !$previous && empty($request['go_live'])) {
            return $this->waiting($app, $journal_path, $journal);
        }
        $journal['phase'] = 'switching';
        $this->write($journal_path, $journal);
        $this->route($app, $candidate);
        if (!$this->healthy($candidate)) {
            throw new RuntimeException('Health check failed after traffic switch');
        }
        $this->checkpoint('checking-public');
        if (($request['public_check'] ?? true) && !$this->health('https://' . $candidate['domain'] . $candidate['health'], 30)) {
            if (!empty($request['defer_public'])) {
                if ($previous) {
                    $this->route($app, $previous);
                }
                $journal['message'] = 'Public HTTPS verification is still pending. Certificate issuance may take a little longer; try again shortly.';
                return $this->waiting($app, $journal_path, $journal);
            }
            throw new RuntimeException('Public health check failed');
        }
        self::remove($this->root . '/apps/' . $app . '/staged.json');
        $this->write($state_path, ['active' => $candidate, 'previous' => $previous]);
        unset($journal['message']);
        $journal = array_replace($journal, ['status' => 'succeeded', 'phase' => 'accepted', 'active' => $candidate['operation']]);
        $this->write($journal_path, $journal);
        try {
            if ($previous && $previous['operation'] !== $candidate['operation']) {
                $this->stop($previous);
            }
        } catch (Throwable) {
            $journal['retirement'] = 'previous process still running; safe to retire later';
            $this->write($journal_path, $journal);
        }
        return $journal;
    }

    private function waiting(string $app, string $journal_path, array &$journal): array {
        $journal['status'] = 'waiting';
        $journal['phase'] = 'awaiting-go-live';
        $this->write($this->root . '/apps/' . $app . '/staged.json', ['operation' => $journal['operation']]);
        $this->write($journal_path, $journal);
        return $journal;
    }

    public function recover(string $app, string $journal_path, string $state_path, array $journal): array {
        $previous = $journal['before']['active'];
        try {
            if ($previous) {
                $this->start($previous);
                if (!$this->healthy($previous)) {
                    throw new RuntimeException('Previous release unhealthy');
                }
            }
            $this->route($app, $previous);
            if ($previous && ($journal['public_check'] ?? true) && !$this->health('https://' . $previous['domain'] . $previous['health'], 3)) {
                throw new RuntimeException('Restored route not verified publicly');
            }
            $this->write($state_path, $journal['before']);
            $candidate = $journal['candidate'] ?? null;
            if ($candidate && (!$previous || $candidate['operation'] !== $previous['operation'])) {
                $this->stop($candidate);
            } elseif (!$candidate && !empty($journal['operation'])) {
                $name = 'tc-' . $app . '-' . $journal['operation'];
                self::remove($this->root . '/enabled/' . $name . '.service');
                $this->run(['systemctl', 'daemon-reload']);
                if (is_file($this->root . '/units/' . $name . '.service')) {
                    $this->run(['systemctl', 'stop', $name . '.service']);
                }
                $this->run(['podman', 'rm', '--force', '--ignore', $name]);
            }
            $journal['status'] = $journal['phase'] === 'migration-started' ? 'uncertain' : 'failed';
            $journal['restored'] = $previous['operation'] ?? null;
            if ($journal['status'] === 'failed') {
                self::remove($this->root . '/apps/' . $app . '/staged.json');
            }
        } catch (Throwable) {
            $journal['status'] = 'uncertain';
            $journal['recovery'] = 'cannot verify restored route; operator reconciliation required';
        }
        $this->write($journal_path, $journal);
        return $journal;
    }

    public function host_inspect(): array {
        $healthy = true;
        try {
            $this->run(['systemctl', 'is-active', 'sshd', 'tc-proxy']);
        } catch (Throwable) {
            $healthy = false;
        }
        foreach (glob($this->root . '/apps/*/state.json') as $path) {
            $active = $this->read($path, [])['active'] ?? null;
            if ($active && !$this->healthy($active)) {
                $healthy = false;
            }
        }
        return ['generation' => realpath('/run/current-system'), 'services_healthy' => $healthy,
            'reservation' => $this->read($this->root . '/host-reservation.json')];
    }

    public function host(array $request): array {
        $action = $request['action'];
        if ($action === 'host-inspect') {
            return $this->host_inspect();
        }
        $operation = self::match($request['operation'], '[a-f0-9]{32}');
        $journal_path = $this->root . '/jobs/host-' . $operation . '.json';
        $terminal = $this->read($journal_path);
        if ($terminal && in_array($terminal['status'] ?? '', ['succeeded', 'failed'], true)) {
            $observed = $this->host_inspect();
            return $observed['generation'] === $terminal['generation'] && $observed['services_healthy'] ? $terminal :
                ['status' => 'uncertain', 'reason' => 'host changed after recorded completion'] + $observed;
        }
        $reservation_path = $this->root . '/host-reservation.json';
        $reservation = $this->read($reservation_path);
        if ($reservation && $reservation['operation'] !== $operation) {
            return ['status' => 'uncertain', 'reason' => 'host reserved by another operation'];
        }
        if ($action === 'host-reconcile') {
            if (!$reservation || ($request['workers_stopped'] ?? null) !== true) {
                return ['status' => 'uncertain', 'reason' => 'stop all workers before explicit host recovery'];
            }
            foreach (glob('/proc/[0-9]*/cmdline') as $path) {
                $command = @file_get_contents($path);
                if ($command !== false && (str_contains($command, 'activate-rs') || str_contains($command, '/bin/switch-to-configuration'))) {
                    return ['status' => 'uncertain', 'reason' => 'remote activation process still exists'];
                }
            }
            $observed = $this->host_inspect();
            if (!$observed['services_healthy']) {
                return ['status' => 'uncertain'] + $observed;
            }
            if ($observed['generation'] === ($reservation['expected'] ?? null)) {
                $result = ['status' => 'succeeded'] + $observed;
            } elseif ($observed['generation'] === $reservation['previous_generation']) {
                $result = ['status' => 'failed', 'restored_generation' => $observed['generation']] + $observed;
            } else {
                return ['status' => 'uncertain'] + $observed;
            }
            $this->write($journal_path, $result);
            self::remove($reservation_path);
            return $result;
        }
        if ($action === 'host-begin') {
            if ($reservation) {
                return ['status' => 'uncertain', 'reason' => 'prior host activation requires reconciliation'] + $this->host_inspect();
            }
            $before = self::match($this->host_inspect()['generation'], '/nix/store/[a-z0-9]{32}-nixos-system-[A-Za-z0-9._-]+');
            $this->run(['nix-store', '--add-root', $this->root . '/roots/' . $operation, '--realise', $before]);
            $this->write($reservation_path, ['operation' => $operation, 'previous_generation' => $before,
                'expected' => $request['expected'] ?? null, 'revision' => $request['revision'] ?? null]);
            return ['status' => 'reserved', 'previous_generation' => $before];
        }
        if ($action === 'host-rollback') {
            if ($reservation) {
                return ['status' => 'uncertain', 'reason' => 'interrupted rollback requires explicit reconciliation'] + $this->host_inspect();
            }
            $generation = self::match($request['generation'], '/nix/store/[a-z0-9]{32}-nixos-system-[A-Za-z0-9._-]+');
            if (!in_array($generation, array_map('realpath', glob($this->root . '/roots/*')), true)) {
                throw new InvalidArgumentException('Generation is not retained');
            }
            $previous = $this->host_inspect()['generation'];
            $this->run(['nix-store', '--add-root', $this->root . '/roots/' . $operation, '--realise', $previous]);
            $this->write($reservation_path, ['operation' => $operation, 'previous_generation' => $previous, 'expected' => $generation]);
            $this->run(['nix-env', '--profile', '/nix/var/nix/profiles/system', '--set', $generation]);
            $this->run([$generation . '/bin/switch-to-configuration', 'switch'], 600);
            $request['expected'] = $generation;
        }
        $observed = $this->host_inspect();
        if ($observed['generation'] === ($request['expected'] ?? null) && $observed['services_healthy']) {
            $result = ['status' => 'succeeded'] + $observed;
            $this->write($journal_path, $result);
            self::remove($reservation_path);
            return $result;
        }
        return ['status' => 'uncertain'] + $observed;
    }

    public function local_build(array $request): array {
        $operation = self::match($request['operation'], '[a-f0-9]{32}');
        self::match($request['tag'], 'localhost/tc/[a-z][a-z0-9-]{0,39}:build-[a-f0-9]{24}');
        $journal_path = $this->root . '/jobs/' . $operation . '.json';
        $lock = fopen($this->root . '/build.lock', 'a');
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                return ['status' => 'pending', 'reason' => 'Another local build is running.'];
            }
            $recorded = $this->read($journal_path);
            if (($recorded['status'] ?? '') === 'succeeded') {
                return $recorded;
            }
            if (disk_free_space($this->root) < 5 * 1024 ** 3) {
                return ['status' => 'failed', 'reason' => 'Free at least 5 GB on this server or use a dedicated builder.'];
            }
            preg_match('/^MemAvailable:\s+(\d+)/m', file_get_contents('/proc/meminfo'), $memory);
            if ((int) ($memory[1] ?? 0) < 1536 * 1024) {
                return ['status' => 'failed', 'reason' => 'Local builds need 1.5 GB available memory. Use a dedicated builder or a larger server.'];
            }
            $plan = array_intersect_key($request, array_flip(['operation', 'repository', 'commit', 'tag', 'dockerfile']));
            $plan['output'] = 'archive';
            $output = self::process(['runuser', '-u', 'tc-builder', '--', 'env', '-i', 'HOME=/home/tc-builder',
                'USER=tc-builder', 'LOGNAME=tc-builder', 'PATH=/run/current-system/sw/bin', 'XDG_RUNTIME_DIR=/run/user/1100',
                'DBUS_SESSION_BUS_ADDRESS=unix:path=/run/user/1100/bus', 'tc-build'], json_encode($plan, JSON_THROW_ON_ERROR), 3500, '/home/tc-builder');
            $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            if (($result['status'] ?? '') !== 'succeeded') {
                return $result;
            }
            $image = self::match($result['image'], 'localhost/tc/[a-z][a-z0-9-]{0,39}@sha256:[a-f0-9]{64}');
            $archive = '/home/tc-builder/.local/state/tc-build/' . $operation . '.oci';
            if (!is_file($archive) || is_link($archive)) {
                throw new RuntimeException('Local build archive is missing');
            }
            $this->run(['podman', 'load', '--input', $archive], 600);
            $image_id = explode('@', $image)[1];
            $actual = $this->run(['podman', 'image', 'inspect', '--format', '{{.Id}}', $image_id]);
            if (preg_replace('/^sha256:/', '', $actual) !== substr($image_id, 7)) {
                throw new RuntimeException('Imported image does not match build output');
            }
            $this->write($journal_path, $result);
            self::remove($archive);
            return $result;
        } finally {
            fclose($lock);
        }
    }

    public function cleanup(): array {
        if (is_file($this->root . '/resources-pending.json')) {
            return ['status' => 'waiting', 'reason' => 'Reconcile the interrupted resource update before retiring containers.'];
        }
        $removed = 0;
        foreach (glob($this->root . '/apps/*', GLOB_ONLYDIR) as $directory) {
            $keep = [];
            foreach ($this->read($directory . '/state.json', []) as $release) {
                if (is_array($release) && isset($release['operation'])) {
                    $keep[] = $release['operation'];
                }
            }
            $staged = $this->read($directory . '/staged.json', []);
            if ($staged) {
                $keep[] = $staged['operation'];
            }
            foreach (glob($directory . '/releases/*/release.json') as $path) {
                $release = $this->read($path);
                $operation = $release['operation'];
                $journal = $this->read($this->root . '/jobs/' . $operation . '.json', []);
                if (in_array($operation, $keep, true) || !in_array($journal['status'] ?? '', ['succeeded', 'failed'], true) || filemtime($path) > time() - 86400) {
                    continue;
                }
                $this->stop($release);
                $this->run(['podman', 'rm', '--ignore', $release['container']]);
                self::remove($this->root . '/units/' . $release['unit']);
                if (str_starts_with($release['image'], 'localhost/tc/')) {
                    try {
                        $this->run(['podman', 'rmi', explode('@', $release['image'])[1]]);
                    } catch (RuntimeException) {
                        // Other retained containers may still use this image.
                    }
                }
                self::remove_tree(dirname($path));
                $removed++;
            }
        }
        return ['status' => 'succeeded', 'removed_releases' => $removed];
    }

    public function dispatch(array $request): array {
        $action = $request['action'];
        if ($action === 'build') {
            return $this->local_build($request);
        }
        if ($action === 'metrics') {
            require_once __DIR__ . '/tc_metrics.php';
            return Metrics::collect($this->root, $this);
        }
        if ($action === 'status') {
            $operation = self::match($request['operation'], '[a-f0-9]{32}');
            return array_intersect_key($this->read($this->root . '/jobs/' . $operation . '.json', []), array_flip(['status', 'phase', 'events']));
        }
        $lock = fopen($this->root . '/operation.lock', 'a');
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                return ['status' => 'uncertain', 'reason' => 'remote operation still executing'];
            }
            if (str_starts_with($action, 'host-')) {
                return $this->host($request);
            }
            if (is_file($this->root . '/host-reservation.json')) {
                return ['status' => 'uncertain', 'reason' => 'host activation reserved'];
            }
            if (in_array($action, ['resources', 'resources-update'], true)) {
                require_once __DIR__ . '/tc_resources.php';
                $resources = new Resources($this);
                if ($action === 'resources-update') {
                    try {
                        $resources->apply(change: $request);
                    } catch (RuntimeException $error) {
                        return ['status' => 'failed', 'error' => $error->getMessage()];
                    }
                }
                return $resources->snapshot();
            }
            if ($action === 'cleanup') {
                return $this->cleanup();
            }
            if ($action === 'inspect') {
                return ['status' => 'succeeded', 'applications' => array_map(fn($path) => $this->read($path), glob($this->root . '/apps/*/state.json'))];
            }
            if (!in_array($action, ['deploy', 'rollback'], true)) {
                throw new InvalidArgumentException('Unknown operation');
            }
            return $this->app_operation($request);
        } finally {
            fclose($lock);
        }
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    umask(0077);
    if (array_slice($argv, 1) === ['--version']) {
        echo "1\n";
        exit;
    }
    try {
        $data = stream_get_contents(STDIN, 1048577);
        if (strlen($data) > 1048576) {
            throw new InvalidArgumentException('Request too large');
        }
        echo json_encode((new Runtime())->dispatch(json_decode($data, true, 512, JSON_THROW_ON_ERROR)), JSON_THROW_ON_ERROR) . "\n";
    } catch (Throwable $error) {
        echo json_encode(['status' => 'uncertain', 'error' => get_class($error)]) . "\n";
    }
}
