--TEST--
Encryptor authenticates ciphertext and Redact scrubs credentials, bearer tokens, and PEM keys
--FILE--
<?php
require dirname(__DIR__, 3) . '/bootstrap.php';

$key = Encryptor::generate_key();
$cipher = Encryptor::encrypt('secret-value', $key);
var_dump(
    !str_contains($cipher, 'secret-value'),
    Encryptor::decrypt($cipher, $key) === 'secret-value',
    Encryptor::decrypt($cipher, Encryptor::generate_key())
);

$text = "credential-value\nAuthorization: Bearer abc123\n-----BEGIN OPENSSH PRIVATE KEY-----\nencoded\n-----END OPENSSH PRIVATE KEY-----";
$redacted = Redact::text($text, ['credential-value']);
var_dump(
    !str_contains($redacted, 'credential-value'),
    !str_contains($redacted, 'abc123'),
    !str_contains($redacted, 'encoded')
);
?>
--EXPECT--
bool(true)
bool(true)
bool(false)
bool(true)
bool(true)
bool(true)
