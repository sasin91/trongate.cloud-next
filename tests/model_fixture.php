<?php
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/modules/trongate_security/traits/Encrypted_credentials.php';

// Tests seed multiple modules on one connection; production Jobs owns only jobs.
class Test_model extends Jobs_model {
    use Encrypted_credentials;

    function get(string $kind, string $id): array {
        $this->module('records');
        return $this->records->_get(['kind' => $kind, 'id' => $id]);
    }

    function put(string $kind, string $id, array $data): void {
        $this->module('records');
        $this->records->_put(['kind' => $kind, 'id' => $id, 'data' => $data]);
    }

    function all(string $kind): array {
        $this->module('records');
        return $this->records->_all($kind);
    }
}
