<?php
declare(strict_types=1);

class Setup_model extends Model {
    public function migrate(): void {
        $schema_file = APPPATH . 'setup.sql';
        $schema = file_get_contents($schema_file);
        if ($schema === false) {
            throw new RuntimeException('Unable to read the application schema.');
        }

        foreach (explode(';', $schema) as $sql) {
            if (trim($sql) !== '') {
                $this->db->connection()->exec($sql);
            }
        }

        if ($this->db->connection()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $table_check = $this->db->query_bind(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('records','credentials','admins','jobs','resource_locks','steps','trongate_user_levels','trongate_users','trongate_administrators','trongate_tokens','login_attempts','password_resets','team_members','team_invitations','registry_tokens','registry_embeddings','registry_query_cache') AND engine <> 'InnoDB'",
                [],
                'statement'
            );
            if ((int) $table_check->fetchColumn() > 0) {
                throw new RuntimeException('All control-plane tables must use InnoDB.');
            }
        }

        $this->module('login');
        $this->login->model->_import_legacy_admins();
    }

    public function initialize_administrator(string $name, string $password_hash): void {
        $connection = $this->db->connection();
        $connection->beginTransaction();
        try {
            $existing = (int) $this->db->query_bind(
                'SELECT COUNT(*) FROM trongate_administrators',
                [],
                'statement'
            )->fetchColumn();
            if ($existing > 0) {
                throw new RuntimeException('An administrator account already exists.');
            }

            $this->db->query_bind(
                'INSERT INTO trongate_users(code, user_level_id) VALUES(?, 1)',
                [bin2hex(random_bytes(16))],
                'statement'
            );
            $user_id = (int) $connection->lastInsertId();
            $this->db->query_bind(
                'INSERT INTO trongate_administrators(username, password, trongate_user_id) VALUES(?, ?, ?)',
                [$name, $password_hash, $user_id],
                'statement'
            );
            $connection->commit();
        } catch (Throwable $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $error;
        }
    }
}
