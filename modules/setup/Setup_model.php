<?php
class Setup_model extends Model {
    function migrate(): void {
        $mysql=$this->db->connection()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $file=$mysql?APPPATH . 'setup.sql':__DIR__.'/fixtures/setup.sqlite.sql';
        foreach (explode(';',file_get_contents($file)) as $sql) {
            if (trim($sql)!=='') { $this->db->connection()->exec($sql); }
        }
        if ($mysql && $this->db->query_bind("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('records','credentials','admins','jobs','resource_locks','steps','team_members','team_invitations') AND engine <> 'InnoDB'", [], 'statement')->fetchColumn()>0) { throw new RuntimeException('All control-plane tables must use InnoDB'); }
        $this->module('login');
        $this->login->model->_import_legacy_admins();
    }
    function _administrator(string $name, string $password): void {
        $this->db->query_bind('REPLACE INTO admins(name,password) VALUES(?,?)', [$name, $password], 'statement');
        $this->login->model->_import_legacy_admins();
        $this->db->query_bind('UPDATE trongate_administrators SET password=? WHERE username=?', [$password, $name], 'statement');
    }
}
