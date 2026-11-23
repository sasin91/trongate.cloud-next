<?php
declare(strict_types=1);

/** Proxmox API boundary: credentials, form encoding, envelopes and task responses. */
class Proxmox_client {
    private string $endpoint;
    private string $token;

    public function __construct(string $endpoint, string $token, private Http_client $transport = new Http_client) {
        $parts = parse_url($endpoint);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) ||
            isset($parts['user']) || isset($parts['pass']) ||
            isset($parts['query']) || isset($parts['fragment']) ||
            !in_array($parts['path'] ?? '', ['', '/'], true) || preg_match('/[\s\\\\]/', $endpoint)) {
            throw new RuntimeException('Enter a Proxmox HTTPS origin, including its API port (usually 8006).');
        }
        if (!preg_match('/^[^\s=!@]+@[^\s=!@]+![^\s=!]+=[^\s=]+$/D', $token)) {
            throw new RuntimeException('Use a Proxmox API token in USER@REALM!TOKENID=SECRET format.');
        }
        $this->endpoint = rtrim($endpoint, '/');
        $this->token = $token;
    }

    public function get(string $path): array {
        [$status, $raw] = $this->send($path);
        return $this->decode($status, $raw);
    }

    public function post(string $path, array $body): string {
        [$status, $raw] = $this->send($path, $body);
        $data = $this->decode_data($status, $raw);
        if (!is_string($data) || !str_starts_with($data, 'UPID:')) {
            throw new Provider_error('Invalid Proxmox task response; reconcile before retrying.');
        }
        return $data;
    }

    public function put(string $path, array $body): void {
        [$status, $raw] = $this->send($path, $body, 'PUT');
        if ($this->decode_data($status, $raw) !== null) {
            throw new Provider_error('Invalid Proxmox configuration response; inspect before retrying.');
        }
    }

    private function send(string $path, ?array $body = null, string $method = 'POST'): array {
        $headers = ['Authorization' => 'PVEAPIToken=' . $this->token, 'Accept' => 'application/json'];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        }
        try {
            $response = $this->transport->request($body === null ? 'GET' : $method,
                $this->endpoint . '/api2/json' . $path, $headers,
                $body === null ? null : http_build_query($body, '', '&', PHP_QUERY_RFC3986));
        } catch (RuntimeException) {
            throw new Provider_error('Proxmox connection failed. Check network access and the trusted TLS certificate.');
        }
        return [$response['status'], $response['body']];
    }

    private function decode(int $status, string $raw): array {
        $data = $this->decode_data($status, $raw);
        if (!is_array($data)) {
            throw new Provider_error('Invalid Proxmox response envelope.');
        }
        return $data;
    }

    private function decode_data(int $status, string $raw): mixed {
        if ($status < 200 || $status >= 300) {
            throw new Provider_error('Proxmox HTTP ' . $status, $status);
        }
        try {
            $response = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new Provider_error('Invalid Proxmox JSON response.');
        }
        if (!is_array($response) || !array_key_exists('data', $response)) {
            throw new Provider_error('Invalid Proxmox response envelope.');
        }
        return $response['data'];
    }
}
