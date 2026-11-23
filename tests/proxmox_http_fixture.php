<?php
require_once dirname(__DIR__) . '/bootstrap.php';

/** Provider fixtures now exercise the real controller decoding over an HTTP boundary. */
abstract class Proxmox_http_fixture extends Http_client {
    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $connect_timeout = 15, int $timeout = 45): array {
        [$endpoint, $path] = explode('/api2/json', $url, 2);
        $token = substr($headers['Authorization'], strlen('PVEAPIToken='));
        parse_str($body ?? '', $form);
        try {
            if ($method === 'GET') {
                $data = $this->read($endpoint, $path, $token);
            } elseif ($method === 'PUT') {
                $this->configure($endpoint, $path, $token, $form);
                $data = null;
            } else {
                $data = $this->mutate($endpoint, $path, $token, $form);
            }
            return ['status' => 200, 'body' => json_encode(['data' => $data], JSON_THROW_ON_ERROR)];
        } catch (Provider_error $error) {
            if ($error->getCode() < 400) {
                throw $error;
            }
            return ['status' => $error->getCode(), 'body' => '{}'];
        }
    }
}
