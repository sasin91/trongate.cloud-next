<?php
require_once dirname(__DIR__).'/db/traits/Database_access.php';
class Setup_model extends Model {
    use Database_access;
    function migrate(): void {
        $mysql=$this->connection()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $file=$mysql?dirname(__DIR__,2).'/setup.sql':__DIR__.'/fixtures/setup.sqlite.sql';
        foreach (explode(';',file_get_contents($file)) as $sql) {
            if (trim($sql)!=='') { $this->connection()->exec($sql); }
        }
        if ($mysql && $this->run("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('records','credentials','admins','jobs','resource_locks','steps') AND engine <> 'InnoDB'")->fetchColumn()>0) { throw new RuntimeException('All control-plane tables must use InnoDB'); }
        (new Login_model('login',$this->connection()))->_import_legacy_admins();
    }
}
