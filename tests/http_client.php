<?php
require __DIR__ . '/bootstrap.php';
require dirname(__DIR__) . '/bootstrap.php';

class Recording_http_client extends Http_client {
    public array $calls = [];
    public array $response = ['status' => 200, 'body' => '{}'];

    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $connect_timeout = 15, int $timeout = 45): array {
        $this->calls[] = compact('method', 'url', 'headers', 'body', 'connect_timeout', 'timeout');
        return $this->response;
    }
}

test('shared client rejects unsafe URLs and header injection before connecting', function () {
    foreach ([['http://example.test', []], ['https://user:secret@example.test', []],
        ['https://example.test', ['Authorization' => "secret\r\nInjected: yes"]]] as [$url, $headers]) {
        try {
            (new Http_client)->request('GET', $url, $headers);
            throw new RuntimeException('Invalid request accepted');
        } catch (InvalidArgumentException $error) {
            assert_same(false, str_contains($error->getMessage(), 'secret'));
        }
    }
});

test('Proxmox owns form encoding and API envelopes over the shared transport', function () {
    $http = new Recording_http_client;
    $http->response = ['status' => 200, 'body' => '{"data":null}'];
    $provider = new Proxmox_client('https://pve.test:8006', 'user@pve!token=secret', $http);
    $provider->put('/nodes/pve/qemu/201/config', ['sshkeys' => 'ssh-ed25519 value+comment']);
    assert_same('PUT', $http->calls[0]['method']);
    assert_same('https://pve.test:8006/api2/json/nodes/pve/qemu/201/config', $http->calls[0]['url']);
    assert_same('application/x-www-form-urlencoded', $http->calls[0]['headers']['Content-Type']);
    parse_str($http->calls[0]['body'], $body);
    assert_same(['sshkeys' => 'ssh-ed25519 value+comment'], $body);
    $http->response = ['status' => 403, 'body' => 'private remote diagnostic'];
    assert_throws(fn() => $provider->get('/nodes'), 'Proxmox HTTP 403');
    assert_same(2, count($http->calls));
});

test('GitHub uses the same client with its own headers and shorter deadline', function () {
    $apps = new Apps('apps');
    $http = new Recording_http_client;
    $http->response = ['status' => 200, 'body' => '{"sha":"fixture"}'];
    $apps->_use_http_client($http);
    assert_same(['sha' => 'fixture'], $apps->_github('owner/repository/commits/main'));
    assert_same('https://api.github.com/repos/owner/repository/commits/main', $http->calls[0]['url']);
    assert_same('application/vnd.github+json', $http->calls[0]['headers']['Accept']);
    assert_same(5, $http->calls[0]['connect_timeout']);
    assert_same(15, $http->calls[0]['timeout']);
});

test('controllers reuse their lazy HTTP client without loading a database', function () {
    $module = new Apps('apps');
    $module->_share_database(static function (): PDO {
        throw new LogicException('HTTP access must not connect to the database');
    });
    $first = $module->http;
    assert_same(true, $first instanceof Http_client);
    assert_same($first, $module->http);
    $fixture = new Recording_http_client;
    $module->_use_http_client($fixture);
    assert_same($fixture, $module->http);
    $module->_use_http_client(null);
    assert_same(false, $first === $module->http);
    assert_same(true, $module->http instanceof Http_client);
});

finish_tests();
