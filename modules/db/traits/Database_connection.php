<?php
trait Database_connection {
    static function _connect_database(): PDO {
        $dsn=getenv('TC_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=tc';
        $pdo=new PDO($dsn,getenv('TC_DB_USER') ?: 'tc',getenv('TC_DB_PASSWORD') ?: '',str_starts_with($dsn,'mysql:')?[PDO::MYSQL_ATTR_FOUND_ROWS=>true]:[]);
        $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
        return $pdo;
    }
}
