--TEST--
Service_spec validation, Services_model encrypted env queueing, and Runtime deploy/boot/destroy service jails
--FILE--
<?php
require dirname(__DIR__, 3) . '/tests/inc/model_fixture.php';
require_once dirname(__DIR__, 3) . '/infra/runtime/runtime.php';

$dir = sys_get_temp_dir() . '/tc-svc-' . bin2hex(random_bytes(6));
mkdir($dir);
putenv('TC_STATE=' . $dir);
putenv('TC_DSN=sqlite:' . $dir . '/db');
file_put_contents($dir . '/key', Encryptor::generate_key());

function svc_check(bool $ok, string $msg): void {
    if (!$ok) {
        throw new RuntimeException($msg);
    }
}

final class Fake_service_runtime extends Runtime {
    public array $commands = [];
    public array $running = [];
    public array $datasets = [];

    public function run(array $args, int $timeout = 120): string {
        $this->commands[] = implode(' ', $args);
        if (($args[0] ?? '') === 'zfs' && ($args[1] ?? '') === 'list') {
            $ds = (string) end($args);
            if (!isset($this->datasets[$ds])) {
                throw new RuntimeException('missing dataset');
            }
            return $ds;
        }
        if (($args[0] ?? '') === 'zfs' && in_array($args[1] ?? '', ['create', 'clone'], true)) {
            $this->datasets[(string) end($args)] = true;
            return '';
        }
        if (($args[0] ?? '') === 'zfs' && ($args[1] ?? '') === 'destroy') {
            unset($this->datasets[(string) end($args)]);
            return '';
        }
        if (($args[0] ?? '') === 'jail' && ($args[1] ?? '') === '-c') {
            $this->running[(string) end($args)] = true;
            return '';
        }
        if (($args[0] ?? '') === 'jail' && ($args[1] ?? '') === '-r') {
            unset($this->running[(string) end($args)]);
            return '';
        }
        if (($args[0] ?? '') === 'jls') {
            $j = (string) ($args[2] ?? '');
            if (!isset($this->running[$j])) {
                throw new RuntimeException('not running');
            }
            return $j;
        }
        if (($args[0] ?? '') === 'pkg' && ($args[1] ?? '') === '-vv') {
            return "  FreeBSD-ports: {\n";
        }
        return '';
    }

    public function tcp_ready(string $ip, int $port, int $attempts = 90): bool {
        return true;
    }
}

try {
    // 1. Service_spec validation
    svc_check(Service_spec::url_variable('laya') === 'LAYA_URL', 'url_variable mismatch');
    svc_check(Service_spec::runtime_name('laya') === 'laya', 'global runtime_name mismatch');
    $rtA = Service_spec::runtime_name('laya', 'app-one');
    $rtB = Service_spec::runtime_name('laya', 'app-two');
    svc_check($rtA !== $rtB && $rtA !== 'laya', 'app-scoped runtime_name must be distinct per app');
    svc_check(Service_spec::name_error($rtA) === null && Service_spec::name_error($rtB) === null, 'app-scoped runtime_name must match tc-runtime pattern');
    svc_check(Service_spec::name_error('laya') === null, 'valid name rejected');
    svc_check(Service_spec::name_error('Bad-Name') !== null, 'invalid name accepted');
    svc_check(Service_spec::port_error(80) !== null, 'privileged port accepted');
    svc_check(Service_spec::port_error(8080) === null, 'valid port rejected');
    svc_check(Service_spec::memory_error(32) !== null, 'tiny memory accepted');
    svc_check(Service_spec::memory_error(3072) === null, '3072MB memory rejected');
    svc_check(Service_spec::parse_packages('py312-pytorch, py312-fastapi py312-pytorch') === ['py312-pytorch', 'py312-fastapi'], 'package list parse failed');
    svc_check(Service_spec::parse_env("# comment\nLAYA_THREADS=2\nLAYA_API_KEY=sec=ret") === ['LAYA_THREADS' => '2', 'LAYA_API_KEY' => 'sec=ret'], 'env parse failed');
    svc_check(isset(Service_spec::presets()['laya']), 'laya preset missing');

    // 2. Services_model persistence, app-scoping & encrypted env
    $s = new Test_model('jobs');
    (new Setup_model('setup', $s->db->connection()))->migrate();
    $s->put('server', 'tc-host1', ['application_ready' => true, 'local_builder_ready' => true, 'host' => '10.99.0.1']);
    $s->put('app', 'app-one', ['server' => 'tc-host1', 'label' => 'app-one', 'domain' => 'app-one.trongate.dev']);
    $s->put('app', 'app-two', ['server' => 'tc-host1', 'label' => 'app-two', 'domain' => 'app-two.trongate.dev']);

    $model = new Services_model('services', $s->db->connection());
    $added = $model->_add([
        'service_name' => 'laya',
        'service_packages' => 'py312-pytorch py312-fastapi',
        'service_setup' => 'echo setup',
        'service_command' => 'python3.12 -m laya.serve',
        'service_port' => '8080',
        'service_memory_mb' => '3072',
        'service_env' => "LAYA_API_KEY=super-secret-value\nLAYA_THREADS=2",
    ]);
    svc_check($added['service'] === 'laya', 'service name mismatch');
    $job = $s->job($added['operation']);
    svc_check($job['kind'] === 'deploy-service', 'job kind mismatch');
    svc_check(!str_contains($job['payload'], 'super-secret-value'), 'service secret leaked to job payload');
    $record = $s->get('service', 'laya');
    svc_check($s->secret($record['env_refs']['LAYA_API_KEY']) === 'super-secret-value', 'encrypted service env missing');

    // App-scoped services: two apps can each have their own 'laya' with isolated secrets & records
    $appOneSvc = $model->_add([
        'app' => 'app-one',
        'service_name' => 'laya',
        'service_command' => 'python3.12 -m laya.serve',
        'service_port' => '8080',
        'service_memory_mb' => '512',
        'service_env' => 'LAYA_API_KEY=app-one-secret',
    ]);
    $appTwoSvc = $model->_add([
        'app' => 'app-two',
        'service_name' => 'laya',
        'service_command' => 'python3.12 -m laya.serve',
        'service_port' => '8081',
        'service_memory_mb' => '512',
        'service_env' => 'LAYA_API_KEY=app-two-secret',
    ]);
    svc_check($appOneSvc['service'] === $rtA && $appOneSvc['name'] === 'laya' && $appOneSvc['app'] === 'app-one', 'app-one service mismatch');
    svc_check($appTwoSvc['service'] === $rtB && $appTwoSvc['name'] === 'laya' && $appTwoSvc['app'] === 'app-two', 'app-two service mismatch');
    svc_check(count($model->_all('app-one')) === 1 && $model->_all('app-one')[0]['id'] === $rtA, 'app-one _all filter leaked other services');
    svc_check(count($model->_all('app-two')) === 1 && $model->_all('app-two')[0]['id'] === $rtB, 'app-two _all filter leaked other services');
    svc_check($s->secret($s->get('service', $rtA)['env_refs']['LAYA_API_KEY']) === 'app-one-secret', 'app-one secret overwritten');
    svc_check($s->secret($s->get('service', $rtB)['env_refs']['LAYA_API_KEY']) === 'app-two-secret', 'app-two secret overwritten');

    $appsModel = new Apps_model('apps', $s->db->connection());
    $ovOne = $appsModel->_overview('app-one');
    $ovTwo = $appsModel->_overview('app-two');
    svc_check(count($ovOne['services']) === 1 && $ovOne['services'][0]['id'] === $rtA, 'app-one overview must only include app-one services');
    svc_check(count($ovTwo['services']) === 1 && $ovTwo['services'][0]['id'] === $rtB, 'app-two overview must only include app-two services');

    // Duplicate name in same app must be rejected
    $dupRejected = false;
    try {
        $model->_add(['app' => 'app-one', 'service_name' => 'laya', 'service_command' => 'python3.12 -m http.server 8080']);
    } catch (RuntimeException) {
        $dupRejected = true;
    }
    svc_check($dupRejected, 'duplicate service in same app must be rejected');

    // Cross-app redeploy/remove must be rejected
    $crossRejected = false;
    try {
        $model->_queue_remove($rtA, 'app-two');
    } catch (RuntimeException) {
        $crossRejected = true;
    }
    svc_check($crossRejected, 'cross-app service removal must be rejected');
    $model->_queue_redeploy($rtA, 'app-one');
    $model->_queue_remove($rtB, 'app-two');

    // 3. Runtime deploy-service, boot, and destroy-service
    $rt = new Fake_service_runtime($dir . '/rt', 'tank');
    $op = str_repeat('a', 32);
    $deployed = $rt->dispatch([
        'action' => 'deploy-service',
        'operation' => $op,
        'service' => [
            'name' => 'laya',
            'packages' => ['py312-pytorch'],
            'setup' => 'echo ok',
            'command' => 'python3.12 -m laya.serve',
            'port' => 8080,
            'memory_mb' => 3072,
        ],
        'env' => ['LAYA_THREADS' => '2'],
    ]);
    svc_check(($deployed['status'] ?? '') === 'succeeded', 'deploy-service failed: ' . json_encode($deployed));
    svc_check(($deployed['ip'] ?? '') === '10.99.251.1', 'service IP mismatch: ' . ($deployed['ip'] ?? ''));
    svc_check(($deployed['url'] ?? '') === 'http://10.99.251.1:8080', 'service URL mismatch');
    svc_check(is_file($dir . '/rt/jail.conf.d/tc_svc_laya.conf'), 'service jail conf missing');
    svc_check(str_contains(file_get_contents($dir . '/rt/jails/svc-laya/etc/tc/service.env'), "export LAYA_THREADS='2'"), 'service env file missing');
    svc_check(in_array('pfctl -t tc_services -T add 10.99.251.1', $rt->commands, true), 'tc_services pf table not updated');
    svc_check(in_array('rctl -a jail:tc_svc_laya:memoryuse:deny=3072M', $rt->commands, true), 'service rctl cap missing');

    // Reboot durability via boot action
    $rt->running = [];
    $boot = $rt->dispatch(['action' => 'boot']);
    svc_check(in_array('service:laya', $boot['started'] ?? [], true), 'boot did not restart active service');

    // Destroy service
    $destroyed = $rt->dispatch(['action' => 'destroy-service', 'operation' => str_repeat('b', 32), 'name' => 'laya']);
    svc_check(($destroyed['status'] ?? '') === 'succeeded', 'destroy-service failed');
    svc_check(!is_file($dir . '/rt/jail.conf.d/tc_svc_laya.conf'), 'service jail conf not removed');
    svc_check(in_array('pfctl -t tc_services -T delete 10.99.251.1', $rt->commands, true), 'tc_services pf entry not deleted');

    echo "PASS: Services spec, encrypted queue, and FreeBSD service jail runtime\n";
} finally {
    unset($appsModel, $model, $s, $rt);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($dir);
}
?>
--EXPECT--
PASS: Services spec, encrypted queue, and FreeBSD service jail runtime
