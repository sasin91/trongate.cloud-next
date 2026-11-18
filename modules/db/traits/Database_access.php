<?php
require_once __DIR__.'/Database_connection.php';
trait Database_access {
    use Database_connection;
    private ?PDO $connection=null;
    function __construct(?string $module_name=null,?PDO $connection=null) {
        parent::__construct($module_name);
        $this->connection=$connection;
        if ($connection) {
            $connection->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
            $connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
        }
    }
    function connection(): PDO { return $this->connection ??= self::_connect_database(); }
    function run(string $sql,array $args=[]): PDOStatement {
        $statement=$this->connection()->prepare($sql); $statement->execute($args); return $statement;
    }
}
