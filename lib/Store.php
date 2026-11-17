<?php
declare(strict_types=1);
final class Store {
    function __construct(public PDO $db) {
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }
    static function open(): self {
        $dsn=getenv('TC_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=tc';
        return new self(new PDO($dsn, getenv('TC_DB_USER') ?: 'tc', getenv('TC_DB_PASSWORD') ?: '', str_starts_with($dsn,'mysql:') ? [PDO::MYSQL_ATTR_FOUND_ROWS=>true] : []));
    }
    function run(string $sql, array $args = []): PDOStatement {
        $s = $this->db->prepare($sql); $s->execute($args); return $s;
    }
    function migrate(): void {
        foreach (explode(';', file_get_contents(dirname(__DIR__) . '/db/001.sql')) as $sql) {
            if (trim($sql) !== '') { $this->db->exec($sql); }
        }
    }
    function get(string $kind, string $id): array {
        $row = $this->run('SELECT data FROM records WHERE kind=? AND id=?', [$kind,$id])->fetch();
        if (!$row) { throw new RuntimeException("Missing $kind record"); }
        return json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR);
    }
    function put(string $kind, string $id, array $data): void {
        // REPLACE is portable to MariaDB and SQLite; records have no dependent FKs.
        $this->run('REPLACE INTO records(kind,id,data) VALUES(?,?,?)', [$kind,$id,json($data)]);
    }
    function all(string $kind): array {
        return array_map(fn($r) => ['id'=>$r['id']] + json_decode($r['data'], true), $this->run('SELECT id,data FROM records WHERE kind=? ORDER BY id', [$kind])->fetchAll());
    }
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
    function enqueue(string $kind, string $resource, array $payload): string {
        $id = bin2hex(random_bytes(16));
        $this->run('INSERT INTO jobs(id,resource,kind,payload,status,created,result) VALUES(?,?,?,?,?,?,?)', [$id,$resource,$kind,json($payload),'pending',(int)(microtime(true)*1000000),'{}']);
        return $id;
    }
    function claim(string $owner): ?array {
        // A sticky resource lock survives expired leases and uncertain outcomes.
        // Only the SAME operation may recover it. Remote locks/journals add fencing.
        $rows = $this->run("SELECT * FROM jobs WHERE status='pending' OR (status='running' AND lease<?) ORDER BY created,id", [time()])->fetchAll();
        foreach ($rows as $r) {
            $this->db->beginTransaction();
            try {
                try { $this->run('INSERT INTO resource_locks VALUES(?,?)', [$r['resource'],$r['id']]); }
                catch (PDOException $e) { if (!in_array((string)$e->getCode(), ['23000','23505'])) { throw $e; } }
                if ($this->run('SELECT operation FROM resource_locks WHERE resource=?', [$r['resource']])->fetchColumn() !== $r['id']) {
                    $this->db->rollBack(); continue;
                }
                $n = $this->run("UPDATE jobs SET status='running',owner=?,lease=?,attempts=attempts+1 WHERE id=? AND (status='pending' OR (status='running' AND lease<?))", [$owner,time()+90,$r['id'],time()])->rowCount();
                $this->db->commit();
                if ($n) { return $this->job($r['id']); }
            } catch (Throwable $e) { if ($this->db->inTransaction()) { $this->db->rollBack(); } throw $e; }
        }
        return null;
    }
    function job(string $id): array {
        $r = $this->run('SELECT * FROM jobs WHERE id=?', [$id])->fetch();
        if (!$r) { throw new RuntimeException('Unknown operation'); } return $r;
    }
    function heartbeat(string $id, string $owner): void {
        if (!$this->run("UPDATE jobs SET lease=? WHERE id=? AND owner=? AND status='running'", [time()+90,$id,$owner])->rowCount()) {
            throw new RuntimeException('Worker lease lost');
        }
    }
    function finish(string $id, string $owner, string $status, array $result): void {
        $this->db->beginTransaction();
        try {
            $n = $this->run("UPDATE jobs SET status=?,result=?,lease=0 WHERE id=? AND owner=? AND status='running'", [$status,json($result),$id,$owner])->rowCount();
            if (!$n) { throw new RuntimeException('Worker lease lost'); }
            if (in_array($status,['succeeded','failed'])) { $this->run('DELETE FROM resource_locks WHERE operation=?', [$id]); }
            $this->db->commit();
        } catch (Throwable $e) { $this->db->rollBack(); throw $e; }
    }
    function step(string $id, string $step, array $data): void {
        $this->run('INSERT INTO steps VALUES(?,?,?,?,?)', [bin2hex(random_bytes(16)),$id,time(),$step,json($data)]);
    }
}
