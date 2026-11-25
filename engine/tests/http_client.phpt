--TEST--
Http_client rejects unsafe URLs/headers, powers GitHub requests, and stays lazy on controllers
--FILE--
<?php
require dirname(__DIR__, 2) . '/bootstrap.php';

class Recording_http_client extends Http_client {
    public array $calls = [];
    public array $response = ['status' => 200, 'body' => '{}'];

    function request(string $method, string $url, array $headers = [], ?string $body = null, int $connect_timeout = 15, int $timeout = 45): array {
        $this->calls[] = compact('method', 'url', 'headers', 'body', 'connect_timeout', 'timeout');
        return $this->response;
    }
}

// 1. Unsafe URLs and header injection rejected before connecting without leaking secrets.
foreach ([
    ['http://example.test', []],
    ['https://user:secret@example.test', []],
    ['https://example.test', ['Authorization' => "secret\r\nInjected: yes"]],
] as [$url, $headers]) {
    try {
        (new Http_client)->request('GET', $url, $headers);
        echo "FAIL accepted {$url}\n";
    } catch (InvalidArgumentException $error) {
        var_dump(!str_contains($error->getMessage(), 'secret'));
    }
}

// 2. GitHub uses shared client with headers, shorter deadline, and safe error handling.
$apps = new Apps('apps');
$http = new Recording_http_client;
$http->response = ['status' => 200, 'body' => '{"sha":"fixture"}'];
$apps->_use_http_client($http);
var_dump($apps->_github('owner/repository/commits/main'));
echo $http->calls[0]['url'], "\n";
echo $http->calls[0]['headers']['Accept'], "\n";
echo $http->calls[0]['connect_timeout'], '/', $http->calls[0]['timeout'], "\n";

$http->response = ['status' => 403, 'body' => 'private remote diagnostic'];
try {
    $apps->_github('owner/repository/commits/main');
    echo "FAIL 403 accepted\n";
} catch (RuntimeException $error) {
    var_dump(
        str_contains($error->getMessage(), 'Could not read the public GitHub repository'),
        !str_contains($error->getMessage(), 'private remote diagnostic')
    );
}

// 3. Lazy controller HTTP client does not open a database.
$module = new Apps('apps');
$module->_share_database(static function (): PDO {
    throw new LogicException('HTTP access must not connect to the database');
});
$first = $module->http;
var_dump($first instanceof Http_client, $first === $module->http);
$fixture = new Recording_http_client;
$module->_use_http_client($fixture);
var_dump($fixture === $module->http);
$module->_use_http_client(null);
var_dump($first !== $module->http, $module->http instanceof Http_client);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
array(1) {
  ["sha"]=>
  string(7) "fixture"
}
https://api.github.com/repos/owner/repository/commits/main
application/vnd.github+json
5/15
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
