<?php
require_once dirname(__DIR__).'/db/traits/Database_access.php';
require_once dirname(__DIR__).'/operations/traits/Inventory_records.php';
require_once dirname(__DIR__).'/operations/traits/Encrypted_credentials.php';
class Operations_model extends Model {
    use Database_access, Inventory_records, Encrypted_credentials;
    function enqueue(string $kind, string $resource, array $payload): string {
        $id = bin2hex(random_bytes(16));
        $this->run('INSERT INTO jobs(id,resource,kind,payload,status,created,result) VALUES(?,?,?,?,?,?,?)', [$id,$resource,$kind,tc_json($payload),'pending',(int)(microtime(true)*1000000),'{}']);
        return $id;
    }
    function claim(string $owner): ?array {
        // A sticky resource lock survives expired leases and uncertain outcomes.
        // Only the SAME operation may recover it. Remote locks/journals add fencing.
        $rows = $this->run("SELECT * FROM jobs WHERE status='pending' OR (status='running' AND lease<?) ORDER BY created,id", [time()])->fetchAll();
        foreach ($rows as $r) {
            $this->connection()->beginTransaction();
            try {
                try { $this->run('INSERT INTO resource_locks VALUES(?,?)', [$r['resource'],$r['id']]); }
                catch (PDOException $e) { if (!in_array((string)$e->getCode(), ['23000','23505'])) { throw $e; } }
                if ($this->run('SELECT operation FROM resource_locks WHERE resource=?', [$r['resource']])->fetchColumn() !== $r['id']) {
                    $this->connection()->rollBack(); continue;
                }
                $n = $this->run("UPDATE jobs SET status='running',owner=?,lease=?,attempts=attempts+1 WHERE id=? AND (status='pending' OR (status='running' AND lease<?))", [$owner,time()+90,$r['id'],time()])->rowCount();
                $this->connection()->commit();
                if ($n) { return $this->job($r['id']); }
            } catch (Throwable $e) { if ($this->connection()->inTransaction()) { $this->connection()->rollBack(); } throw $e; }
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
        $this->connection()->beginTransaction();
        try {
            $n = $this->run("UPDATE jobs SET status=?,result=?,lease=0 WHERE id=? AND owner=? AND status='running'", [$status,tc_json($result),$id,$owner])->rowCount();
            if (!$n) { throw new RuntimeException('Worker lease lost'); }
            if (in_array($status,['succeeded','failed'])) { $this->run('DELETE FROM resource_locks WHERE operation=?', [$id]); }
            $this->connection()->commit();
        } catch (Throwable $e) { $this->connection()->rollBack(); throw $e; }
    }
    function step(string $id, string $step, array $data): void {
        $this->run('INSERT INTO steps VALUES(?,?,?,?,?)', [bin2hex(random_bytes(16)),$id,time(),$step,tc_json($data)]);
    }
    function resolve(string $id,array $result): void {
        if (!in_array($result['status'],['succeeded','failed'])) { return; }
        $this->connection()->beginTransaction();
        try {
            $n=$this->run("UPDATE jobs SET status=?,result=? WHERE id=? AND status='uncertain'",[$result['status'],tc_json($result),$id])->rowCount();
            if (!$n) { throw new RuntimeException('Operation changed during recovery; inspect it again'); }
            $this->run('DELETE FROM resource_locks WHERE operation=?',[$id]);
            $this->step($id,'operator-reconciled',$result); $this->connection()->commit();
        } catch (Throwable $e) { $this->connection()->rollBack(); throw $e; }
    }
}
