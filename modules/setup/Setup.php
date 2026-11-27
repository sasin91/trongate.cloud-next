<?php
declare(strict_types=1);

class Setup extends Trongate {
    public function __construct(?string $module_name = null) {
        parent::__construct($module_name ?? 'setup');
        if (PHP_SAPI !== 'cli') {
            block_url('setup');
        }
    }
}
