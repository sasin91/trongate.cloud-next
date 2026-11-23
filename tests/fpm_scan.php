<?php
/** Exercise SSH scanning through the authenticated PHP-FPM web entry point. */
$options = getopt('', ['url:', 'credentials:', 'insecure']);
if (!isset($options['url'], $options['credentials'])) {
    fwrite(STDERR, "Usage: php tests/fpm_scan.php --url URL --credentials FILE [--insecure]\n");
    exit(1);
}
$credentials = json_decode(file_get_contents($options['credentials']), true, 512, JSON_THROW_ON_ERROR);
$client = curl_init();
curl_setopt_array($client, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_COOKIEFILE => '',
    CURLOPT_SSL_VERIFYPEER => !isset($options['insecure']),
    CURLOPT_SSL_VERIFYHOST => isset($options['insecure']) ? 0 : 2,
]);
$request = function (string $path, ?array $data = null) use ($client, $options): string {
    curl_setopt($client, CURLOPT_URL, rtrim($options['url'], '/') . $path);
    if ($data !== null) {
        curl_setopt($client, CURLOPT_POST, true);
        curl_setopt($client, CURLOPT_POSTFIELDS, http_build_query($data));
    } else {
        curl_setopt($client, CURLOPT_HTTPGET, true);
    }
    $body = curl_exec($client);
    $status = curl_getinfo($client, CURLINFO_RESPONSE_CODE);
    if ($body === false || $status < 200 || $status >= 400) {
        // Never include the authenticated page, request body, or credentials in a failed check.
        throw new RuntimeException('HTTP scan request failed with status ' . $status);
    }
    return $body;
};
try {
    $page = $request('/login/login/1');
    if (!preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $page, $match)) {
        throw new RuntimeException('Login page did not supply a CSRF token');
    }
    $csrf = $match[1];
    $request('/login/submit_login/1', ['csrf_token' => $csrf, 'identifier' => $credentials['name'], 'password' => $credentials['password']]);
    $page = $request('/servers/scan', ['csrf_token' => $csrf,
        'host' => '127.0.0.1', 'port' => 22, 'user' => 'root', 'credential' => 'http-scan-probe']);
    if (!str_contains($page, 'SHA256:') || !str_contains($page, 'enrollment')) {
        throw new RuntimeException('HTTP SSH scan did not return a host fingerprint');
    }
    $request('/login/logout', ['csrf_token' => $csrf]);
    echo "PASS: authenticated PHP-FPM SSH scan returned a host fingerprint\n";
} finally {
    curl_close($client);
}
