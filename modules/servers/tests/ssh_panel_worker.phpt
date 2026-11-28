--TEST--
SSH panel-worker inspection validates and exposes only sanitized PHP error locations
--FILE--
<?php
define('APPPATH', dirname(__DIR__, 3) . DIRECTORY_SEPARATOR);
require APPPATH . 'engine/Model.php';
require APPPATH . 'engine/Process.php';
require APPPATH . 'modules/validation/services/Validate.php';
require APPPATH . 'modules/servers/services/Ssh.php';

class Panel_worker_test_model extends Model {
    public function __call(string $method, array $arguments): mixed {
        if ($method === 'secret') {
            return 'private key';
        }
        throw new RuntimeException('Unexpected model method.');
    }
}

class Panel_worker_test_process extends Process {
    function __construct(private string $response, private int $exit = 0, private string $error = '') {
    }

    function run(array $argv, string $input = '', int $timeout = 60, ?callable $heartbeat = null, array $extra_env = []): array {
        return ['exit' => $this->exit, 'out' => $this->response, 'err' => $this->error];
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

$ssh = new Ssh(
    new Panel_worker_test_model('servers'),
    new Panel_worker_test_process('', 255, 'Permission denied (publickey).')
);
try {
    $ssh->inspect_panel_worker($server);
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'The server rejected the SSH key. Check the SSH username and use the private key matching the public key installed on this server.') {
        throw $error;
    }
    echo "SSH error classified\n";
}

$ssh = new Ssh(
    new Panel_worker_test_model('servers'),
    new Panel_worker_test_process('', 255, 'connect to host 10.99.0.1 port 22: Operation not permitted')
);
try {
    $ssh->inspect_panel_worker($server);
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'The panel jail cannot open the SSH connection. Check the host PF rule and the panel-to-host route.') {
        throw $error;
    }
    echo "panel route error classified\n";
}

$ssh = new Ssh(
    new Panel_worker_test_model('servers'),
    new Panel_worker_test_process('', 1, 'doas: Operation not permitted')
);
try {
    $ssh->inspect_panel_worker($server);
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'The host denied the tc-runtime forced command. Check the doas policy for tc-agent.') {
        throw $error;
    }
    echo "doas error classified\n";
}

$ssh = new Ssh(
    new Panel_worker_test_model('servers'),
    new Panel_worker_test_process(
        "PHP Warning: sanitized startup warning\n"
            . json_encode(['status' => 'uncertain', 'error' => 'RuntimeException', 'reason' => 'private failure detail'])
            . "\n",
        1
    )
);
try {
    $ssh->inspect_panel_worker($server);
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'The remote tc-runtime inspection failed (RuntimeException).') {
        throw $error;
    }
    echo "remote runtime error classified\n";
}

$ssh = new Ssh(
    new Panel_worker_test_model('servers'),
    new Panel_worker_test_process('unexpected banner text', 255)
);
try {
    $ssh->inspect_panel_worker($server);
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'SSH returned a non-protocol response (exit code 255, stdout 22 bytes).') {
        throw $error;
    }
    echo "non-protocol output classified\n";
}

$ssh = new Ssh(
    new Panel_worker_test_model('servers'),
    new Panel_worker_test_process('', 255)
);
try {
    $ssh->inspect_panel_worker($server);
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'SSH exited with code 255 without a response (stderr 0 bytes).') {
        throw $error;
    }
    echo "silent exit classified\n";
}

echo "sanitized PHP error transport passed\n";
?>
--EXPECT--
SSH error classified
panel route error classified
doas error classified
remote runtime error classified
non-protocol output classified
silent exit classified
sanitized PHP error transport passed
