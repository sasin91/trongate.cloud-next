--TEST--
SSH panel-worker inspection validates and exposes only sanitized PHP error locations
--FILE--
<?php
define('APPPATH', dirname(__DIR__, 3) . DIRECTORY_SEPARATOR);
require APPPATH . 'engine/Model.php';
require APPPATH . 'engine/Process.php';
require APPPATH . 'modules/validation/services/Validate.php';
require APPPATH . 'modules/servers/services/Ssh.php';

function state_dir(): string {
    return sys_get_temp_dir();
}

class Panel_worker_test_model extends Model {
    public function __call(string $method, array $arguments): mixed {
        if ($method === 'secret') {
            return 'private key';
        }
        throw new RuntimeException('Unexpected model method.');
    }
}

class Panel_worker_test_process extends Process {
    function __construct(private string $response) {
    }

    function run(array $argv, string $input = '', int $timeout = 60, ?callable $heartbeat = null, array $extra_env = []): array {
        return ['exit' => 0, 'out' => $this->response, 'err' => ''];
    }
}

$server = [
    'host' => '192.168.0.150',
    'port' => 22,
    'user' => 'tc-agent',
    'credential' => 'panel-key',
    'host_key' => 'ssh-ed25519 AAAA',
];
$response = [
    'status' => 'succeeded',
    'panel_jail_running' => true,
    'worker_service_active' => true,
    'worker_process_samples' => [1, 1, 1],
    'worker_source_sha256' => str_repeat('a', 64),
    'latest_php_error' => [
        'type' => 'TypeError',
        'file' => 'modules/jobs/Jobs.php',
        'line' => 89,
        'message' => 'private details must not cross this boundary',
    ],
];
$ssh = new Ssh(new Panel_worker_test_model('servers'), new Panel_worker_test_process(json_encode($response)));
$result = $ssh->inspect_panel_worker($server);
if ($result['latest_php_error'] !== ['type' => 'TypeError', 'file' => 'modules/jobs/Jobs.php', 'line' => 89]) {
    throw new RuntimeException('Sanitized PHP error location was not preserved.');
}

$response['latest_php_error']['file'] = '../../private.php';
$ssh = new Ssh(new Panel_worker_test_model('servers'), new Panel_worker_test_process(json_encode($response)));
$rejected = false;
try {
    $ssh->inspect_panel_worker($server);
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'The host returned an invalid panel PHP error location.') {
        throw $error;
    }
    $rejected = true;
}
if (!$rejected) {
    throw new RuntimeException('Invalid PHP error location was accepted.');
}

echo "sanitized PHP error transport passed\n";
?>
--EXPECT--
sanitized PHP error transport passed
