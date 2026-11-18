<?php
trait Encrypted_credentials {
    function secret(string $id, ?string $value = null): string {
        if ($value !== null) {
            $this->run('REPLACE INTO credentials(id,ciphertext) VALUES(?,?)', [$id,Encryptor::encrypt($value, installation_key())]);
            return $id;
        }
        $cipher = $this->run('SELECT ciphertext FROM credentials WHERE id=?', [$id])->fetchColumn();
        $plain = $cipher ? Encryptor::decrypt($cipher, installation_key()) : false;
        if ($plain === false) { throw new RuntimeException('Credential unavailable'); }
        return $plain;
    }
}
