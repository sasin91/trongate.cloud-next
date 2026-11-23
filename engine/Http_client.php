<?php
declare(strict_types=1);

/** Shared HTTPS transport. Callers own authentication, encoding and response interpretation. */
class Http_client {
    /** @return array{status: int, body: string} */
    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $connect_timeout = 15, int $timeout = 45): array {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) ||
            isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || preg_match('/[\s\\\\]/', $url)) {
            throw new InvalidArgumentException('HTTP client requires an HTTPS URL without embedded credentials.');
        }
        if (!preg_match('/^[A-Z]+$/D', $method) || $connect_timeout < 1 || $timeout < $connect_timeout) {
            throw new InvalidArgumentException('Invalid HTTP method or timeout.');
        }
        $lines = [];
        foreach ($headers as $name => $value) {
            if (!is_string($name) || !preg_match('/^[A-Za-z0-9-]+$/D', $name) || !is_string($value) || preg_match('/[\r\n\x00]/', $value)) {
                throw new InvalidArgumentException('Invalid HTTP header.');
            }
            $lines[] = $name . ': ' . $value;
        }
        $curl = curl_init($url);
        try {
            curl_setopt_array($curl, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => $connect_timeout,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => $lines,
            ]);
            if ($body !== null) {
                curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
            }
            $response = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($response === false || $status === 0) {
                // Never include URLs, headers, credentials or remote bodies in errors.
                throw new RuntimeException('HTTP transport failed. Check connectivity and the trusted TLS certificate.');
            }
            return ['status' => $status, 'body' => $response];
        } finally {
            curl_close($curl);
        }
    }
}
