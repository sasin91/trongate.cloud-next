<?php
declare(strict_types=1);
final class Redact {
    static function text(string $text, array $secrets = []): string {
        foreach ($secrets as $secret) {
            if ($secret !== '') {
                $text = str_replace($secret, '[redacted]', $text);
            }
        }
        $text = preg_replace('/-----BEGIN [^-]*PRIVATE KEY-----.*?-----END [^-]*PRIVATE KEY-----/s', '[redacted private key]', $text);
        $text = preg_replace('/(Authorization:\s*(?:Bearer|Basic)\s+)\S+/i', '$1[redacted]', $text);
        $text = preg_replace('/\b(?:gh[pousr]|github_pat)_[A-Za-z0-9_]{10,}\b/', '[redacted]', $text);
        return substr($text, -65536);
    }
}
