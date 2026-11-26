<?php
declare(strict_types=1);

/**
 * Minimal, zero-dependency SMTP sender for invitation emails when TC_SMTP_HOST is configured.
 * Always optional: the panel also displays a copyable one-time invite link.
 */
final class Mailer {
    /**
     * @return array{sent: bool, reason?: string, error?: string}
     */
    public static function send_invite(
        string $to_email,
        string $invite_url,
        string $invited_by,
        array $summary_lines,
        string $note = '',
        int $expires_at = 0
    ): array {
        $host = trim((string) (getenv('TC_SMTP_HOST') ?: ''));
        if ($host === '') {
            return ['sent' => false, 'reason' => 'not_configured'];
        }
        $port = (int) (getenv('TC_SMTP_PORT') ?: 587);
        if ($port < 1 || $port > 65535) {
            return ['sent' => false, 'error' => 'Invalid TC_SMTP_PORT.'];
        }
        $from = trim((string) (getenv('TC_MAIL_FROM') ?: ''));
        if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return ['sent' => false, 'error' => 'TC_MAIL_FROM must be a valid email address.'];
        }
        if (!filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
            return ['sent' => false, 'error' => 'Invalid recipient email address.'];
        }

        $from_name = self::clean_header((string) (getenv('TC_MAIL_FROM_NAME') ?: 'trongate.cloud'));
        $user = (string) (getenv('TC_SMTP_USER') ?: '');
        $pass = (string) (getenv('TC_SMTP_PASS') ?: '');
        $encryption = strtolower(trim((string) (getenv('TC_SMTP_ENCRYPTION') ?: 'starttls')));

        $subject = 'Invitation to trongate.cloud';
        $lines = [
            'Hello,',
            '',
            ($invited_by !== '' ? $invited_by : 'A workspace administrator') . ' invited you to trongate.cloud.',
        ];
        $lines[] = '';
        $lines[] = 'Granted access:';
        foreach ($summary_lines as $item) {
            $lines[] = '  - ' . $item;
        }
        if (trim($note) !== '') {
            $lines[] = '';
            $lines[] = 'Note:';
            $lines[] = trim($note);
        }
        $lines[] = '';
        $lines[] = 'Accept your invitation here:';
        $lines[] = $invite_url;
        if ($expires_at > 0) {
            $lines[] = '';
            $lines[] = 'This invitation expires on ' . gmdate('Y-m-d H:i', $expires_at) . ' UTC.';
        }
        $lines[] = '';
        $lines[] = 'If you did not expect this invitation, you can safely ignore this email.';

        $body = implode("\r\n", $lines);

        try {
            self::smtp_send($host, $port, $encryption, $user, $pass, $from, $from_name, $to_email, $subject, $body);
            return ['sent' => true];
        } catch (Throwable $e) {
            return ['sent' => false, 'error' => $e->getMessage()];
        }
    }

    private static function clean_header(string $value): string {
        return trim(str_replace(["\r", "\n"], '', $value));
    }

    private static function smtp_send(
        string $host,
        int $port,
        string $encryption,
        string $user,
        string $pass,
        string $from,
        string $from_name,
        string $to,
        string $subject,
        string $body
    ): void {
        $scheme = $encryption === 'tls' ? 'tls://' : 'tcp://';
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            $scheme . $host . ':' . $port,
            $errno,
            $errstr,
            8,
            STREAM_CLIENT_CONNECT
        );
        if (!is_resource($socket)) {
            throw new RuntimeException('Could not connect to SMTP server.');
        }
        stream_set_timeout($socket, 8);

        try {
            self::expect($socket, [220]);
            $ehlo_host = preg_replace('/[^a-zA-Z0-9.-]/', '', (string) (parse_url(BASE_URL, PHP_URL_HOST) ?: 'localhost')) ?: 'localhost';
            self::command($socket, 'EHLO ' . $ehlo_host, [250]);

            if ($encryption === 'starttls') {
                self::command($socket, 'STARTTLS', [220]);
                if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                    throw new RuntimeException('SMTP STARTTLS negotiation failed.');
                }
                self::command($socket, 'EHLO ' . $ehlo_host, [250]);
            }

            if ($user !== '') {
                self::command($socket, 'AUTH LOGIN', [334]);
                self::command($socket, base64_encode($user), [334]);
                self::command($socket, base64_encode($pass), [235]);
            }

            self::command($socket, 'MAIL FROM:<' . self::clean_header($from) . '>', [250]);
            self::command($socket, 'RCPT TO:<' . self::clean_header($to) . '>', [250, 251]);
            self::command($socket, 'DATA', [354]);

            $headers = [
                'From: ' . ($from_name !== '' ? '"' . addcslashes($from_name, '"\\') . '" <' . self::clean_header($from) . '>' : self::clean_header($from)),
                'To: <' . self::clean_header($to) . '>',
                'Subject: ' . self::clean_header($subject),
                'Date: ' . gmdate('r'),
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
            ];
            $escaped_body = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $body));
            $payload = implode("\r\n", $headers) . "\r\n\r\n" . str_replace("\n", "\r\n", (string) $escaped_body) . "\r\n.\r\n";
            fwrite($socket, $payload);
            self::expect($socket, [250]);
            self::command($socket, 'QUIT', [221, 250]);
        } finally {
            fclose($socket);
        }
    }

    private static function command($socket, string $line, array $expected): string {
        fwrite($socket, $line . "\r\n");
        return self::expect($socket, $expected);
    }

    private static function expect($socket, array $expected): string {
        $response = '';
        while (($line = fgets($socket, 1024)) !== false) {
            $response .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $expected, true)) {
            throw new RuntimeException('SMTP server rejected message (code ' . $code . ').');
        }
        return $response;
    }
}
