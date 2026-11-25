<?php
class Setup extends Trongate {
    function __construct(?string $module_name = null) {
        parent::__construct($module_name ?? 'setup');
        if (PHP_SAPI !== 'cli') {
            block_url('setup');
        }
    }
    function _initialize(array $input): void {
        if (strlen($input['password']) < 14) {
            throw new RuntimeException('Administrator password must be at least 14 characters');
        }
        $key = getenv('TC_KEY_FILE') ?: state_dir() . '/key';
        if (!is_file($key)) {
            $handle = fopen($key, 'x');
            fwrite($handle, Encryptor::generate_key());
            fclose($handle);
            chmod($key, 0600);
        }
        $this->model->migrate();
        $this->model->_administrator($input['name'], password_hash($input['password'], PASSWORD_DEFAULT));
    }
}
