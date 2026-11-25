--TEST--
Ssh::inspect uses forced-command tc-runtime protocol v2 and requires pinned Ed25519 identity
--FILE--
<?php
require dirname(__DIR__, 3) . '/tests/inc/model_fixture.php';

class FixtureProcess extends Process {
    public array $args = [];
    public string $input = '';
    public array $response = [];

    function run(array $argv, string $input = '', int $timeout = 60, ?callable $heartbeat = null, array $extra_env = []): array {
        $this->args = $argv;
        $this->input = $input;
        return $this->response;
    }
}

$dir = sys_get_temp_dir() . '/tc-ssh-test-' . bin2hex(random_bytes(5));
mkdir($dir);
putenv('TC_STATE=' . $dir);
file_put_contents($dir . '/key', Encryptor::generate_key());
try {
    $s = new Test_model('jobs', new PDO('sqlite::memory:'));
    (new Setup_model('setup', $s->db->connection()))->migrate();
    $s->secret('key', 'not-a-real-key');

    $process = new FixtureProcess;
    $process->response = [
        'exit' => 0,
        'out' => json_encode([
            'status' => 'succeeded',
            'protocol' => 2,
            'os' => 'freebsd',
            'arch' => 'amd64',
            'freebsd_version' => '15.1-RELEASE',
            'zfs_pool' => 'zroot',
            'checks' => [
                'zfs' => true, 'jail' => true, 'rctl' => true, 'pf' => true,
                'caddy' => true, 'mariadb' => true, 'valkey' => true, 'php' => true,
                'base_dataset' => true, 'runtime_dataset' => true, 'builder_ready' => true, 'protocol' => true,
            ],
            'php_versions' => ['8.4'],
            'optional_extensions' => ['imagick', 'gmp'],
            'application_ready' => true,
            'local_builder_ready' => true,
            'missing' => [],
        ], JSON_THROW_ON_ERROR),
        'err' => '',
    ];

    $ssh = new Ssh($s, $process);
    $server = ['host' => '10.99.0.1', 'port' => 22, 'user' => 'tc-agent', 'credential' => 'key'];
    try {
        $ssh->inspect($server);
        echo "FAIL unpinned accepted\n";
    } catch (RuntimeException $e) {
        echo "unpinned=", $e->getMessage(), "\n";
    }

    $server['host_key'] = 'ssh-ed25519 ' . base64_encode('fixture');
    $ready = $ssh->inspect($server);
    echo "stdin=", $process->input, "\n";
    echo "cmd=", end($process->args), "\n";
    var_dump(
        in_array('StrictHostKeyChecking=yes', $process->args, true),
        $ready['application_ready'],
        $ready['local_builder_ready'],
        $ready['php_versions'] === ['8.4'],
        $ready['missing'] === []
    );

    // Missing rctl and wrong OS fail closed.
    $process->response['out'] = json_encode([
        'status' => 'succeeded', 'protocol' => 1, 'os' => 'linux',
        'checks' => ['zfs' => true, 'rctl' => false],
    ], JSON_THROW_ON_ERROR);
    $unready = $ssh->inspect($server);
    var_dump(
        $unready['application_ready'],
        in_array('os', $unready['missing'], true),
        in_array('rctl', $unready['missing'], true),
        in_array('protocol', $unready['missing'], true)
    );

    // CRLF private key is normalized to LF in temporary identity file.
    $s->secret('key', "  -----BEGIN OPENSSH PRIVATE KEY-----\r\nfixture\r\n-----END OPENSSH PRIVATE KEY-----  ");
    $ssh->with_credentials($server, function ($opts, $temporary) {
        var_dump(file_get_contents($temporary . '/identity') === "-----BEGIN OPENSSH PRIVATE KEY-----\nfixture\n-----END OPENSSH PRIVATE KEY-----\n");
    });

    $failed = new class extends Process {
        function run(array $argv, string $input = '', int $timeout = 60, ?callable $heartbeat = null, array $extra_env = []): array {
            return ['exit' => 255, 'out' => '', 'err' => 'Permission denied (publickey).'];
        }
    };
    try {
        (new Ssh($s, $failed))->inspect($server);
        echo "FAIL rejected key accepted\n";
    } catch (RuntimeException $e) {
        var_dump(str_contains($e->getMessage(), 'server rejected'));
    }
} finally {
    if (is_file($dir . '/key')) {
        unlink($dir . '/key');
    }
    if (is_dir($dir)) {
        rmdir($dir);
    }
}
?>
--EXPECT--
unpinned=Host identity not confirmed
stdin={"action":"inspect-host"}
cmd=tc-runtime
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
