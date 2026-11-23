<?php
declare(strict_types=1);
/** Explicit Linux integration check: php tests/resource_podman.php LOCAL_IMAGE_ID.
 * Uses only disposable containers and a fresh temporary runtime directory.
 * The local image must contain /bin/sh and sleep; no app entrypoint is executed.
 */
require dirname(__DIR__) . '/runtime/tc-runtime.php';
require_once dirname(__DIR__) . '/runtime/tc_resources.php';

class Integration_resources extends Resources {
    public function host(): array {
        return ['memory_mb' => 4096, 'available_mb' => 3500, 'cpus' => 4,
            'system_memory_mb' => 512, 'database_memory_mb' => 512, 'build_memory_mb' => 1536,
            'system_cpus' => 0.25, 'database_cpus' => 0.25, 'build_cpus' => 1];
    }
}
function resource_check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
if (PHP_OS_FAMILY !== 'Linux' || empty($argv[1]) || str_starts_with($argv[1], '-')) {
    fwrite(STDERR, "Usage on Linux: php tests/resource_podman.php LOCAL_IMAGE_ID\n");
    exit(1);
}
$names = [];
$directory = sys_get_temp_dir() . '/tc-resource-integration-' . bin2hex(random_bytes(8));
$runtime = new Runtime($directory);
$resources = new Integration_resources($runtime);
try {
    foreach (['resourcechecka', 'resourcecheckb'] as $app) {
        $operation = bin2hex(random_bytes(16));
        $name = 'tc-' . $app . '-' . $operation;
        $names[] = $name;
        $runtime->run(['podman', 'run', '--detach', '--name', $name, '--memory', '256m',
            '--cpus', '1', '--entrypoint', '/bin/sh', $argv[1], '-c', 'sleep 300']);
        $runtime->write($directory . '/apps/' . $app . '/state.json', ['active' => ['operation' => $operation]]);
        $runtime->write($directory . '/apps/' . $app . '/releases/' . $operation . '/release.json', ['container' => $name]);
    }
    $resources->apply();
    foreach ($resources->containers() as $item) {
        resource_check($item['memory_mb'] === 384, 'Wrong memory limit');
        resource_check($item['shares'] === (int) round(0.62 * 1024), 'Wrong CPU shares');
        resource_check($item['effective_quota'] == 0 || $item['effective_quota'] >= Resources::cpu_count(), 'CPU bursting is capped');
        resource_check($item['effective_memory_mb'] === 384, 'Kernel memory limit differs');
    }
    $resources->apply(change: ['app' => 'resourcechecka', 'mode' => 'manual', 'memory_mb' => 512, 'cpus' => 0.5]);
    $limits = array_column($resources->containers(), 'memory_mb');
    sort($limits);
    resource_check($limits === [256, 512], 'Manual allocation was not applied');
    $before = $resources->containers();
    $rejected = false;
    try {
        $resources->apply(change: ['app' => 'resourcechecka', 'mode' => 'manual', 'memory_mb' => 999999, 'cpus' => 0.5]);
    } catch (RuntimeException) {
        $rejected = true;
    }
    resource_check($rejected, 'Over-budget allocation was accepted');
    resource_check(array_column($resources->containers(), 'memory_mb') === array_column($before, 'memory_mb'), 'Rejected edit changed limits');
    $runtime->run(['podman', 'stop', '--time', '0', $names[0]]);
    $resources->apply(change: ['app' => 'resourcechecka', 'mode' => 'manual', 'memory_mb' => 400, 'cpus' => 0.2]);
    $runtime->run(['podman', 'start', $names[0]]);
    $restarted = array_column($resources->containers(), null, 'container')[$names[0]];
    resource_check($restarted['effective_memory_mb'] === 400, 'Stopped container did not retain its new limit');
    resource_check($restarted['effective_quota'] >= Resources::cpu_count(), 'Restarted CPU quota capped bursting');
    resource_check($restarted['shares'] === (int) round(0.2 * 1024), 'Restarted shares differ');
    $resources->runtime->write($resources->pending, ['before' => $resources->containers(), 'policies' => $resources->runtime->read($resources->path)]);
    $resources->recover();
    resource_check(!is_file($resources->pending), 'Recovery journal not cleared');
    $snapshot = $resources->snapshot();
    resource_check(count($snapshot['apps']) === 2, 'Wrong app count');
    foreach ($snapshot['apps'] as $row) {
        resource_check($row['versions'] === 1 && $row['used_mb'] > 0 && $row['cpu_percent'] !== null, 'Live usage missing');
    }
    unlink(glob($directory . '/apps/resourcechecka/releases/*/release.json')[0]);
    resource_check(count($resources->containers()) === 2, 'Interrupted creation must still count');
    $runtime->run(['podman', 'rm', '--force', '--time', '0', $names[0]]);
    resource_check(count($resources->containers()) === 1, 'Removed containers must not break inspection');
    echo "Real Podman limits, full-host CPU bursting, boundaries, usage and recovery passed\n";
} finally {
    foreach ($names as $name) {
        $runtime->run(['podman', 'rm', '--force', '--time', '0', '--ignore', $name]);
    }
    Runtime::remove_tree($directory);
}
