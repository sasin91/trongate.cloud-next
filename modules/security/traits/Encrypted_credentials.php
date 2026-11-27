<?php
require_once APPPATH . 'modules/security/services/Encryptor.php';

trait Encrypted_credentials {
    function secret(string $id, ?string $value = null): string {
        if ($value !== null) {
            $this->db->query_bind('REPLACE INTO credentials(id,ciphertext) VALUES(?,?)', [$id,Encryptor::encrypt($value, APP_KEY)], 'statement');
            return $id;
        }
        $cipher = $this->db->query_bind('SELECT ciphertext FROM credentials WHERE id=?', [$id], 'statement')->fetchColumn();
        $plain = $cipher ? Encryptor::decrypt($cipher, APP_KEY) : false;
        if ($plain === false) { throw new RuntimeException('Credential unavailable'); }
        return $plain;
    }
}
