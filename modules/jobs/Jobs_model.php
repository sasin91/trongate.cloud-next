<?php
class Jobs_model extends Model {
    function enqueue(string $kind, string $resource, array $payload): string {
        $id = bin2hex(random_bytes(16));
        $this->db->query_bind('INSERT INTO jobs(id,resource,kind,payload,status,created,result) VALUES(?,?,?,?,?,?,?)', [$id,$resource,$kind,self::_encode_json($payload),'pending',(int)(microtime(true)*1000000),'{}'], 'statement');
        return $id;
    }
    function claim(string $owner): ?array {
        // A sticky resource lock survives expired leases and uncertain outcomes.
        // Only the SAME operation may recover it. Remote locks/journals add fencing.
        $rows = $this->db->query_bind("SELECT * FROM jobs WHERE status='pending' OR (status='running' AND lease<?) ORDER BY created,id", [time()], 'statement')->fetchAll();
        foreach ($rows as $r) {
            $this->db->connection()->beginTransaction();
            try {
                try { $this->db->query_bind('INSERT INTO resource_locks VALUES(?,?)', [$r['resource'],$r['id']], 'statement'); }
                catch (PDOException $e) { if (!in_array((string)$e->getCode(), ['23000','23505'])) { throw $e; } }
                if ($this->db->query_bind('SELECT operation FROM resource_locks WHERE resource=?', [$r['resource']], 'statement')->fetchColumn() !== $r['id']) {
                    $this->db->connection()->rollBack(); continue;
                }
                $n = $this->db->query_bind("UPDATE jobs SET status='running',owner=?,lease=?,attempts=attempts+1 WHERE id=? AND (status='pending' OR (status='running' AND lease<?))", [$owner,time()+90,$r['id'],time()], 'statement')->rowCount();
                $this->db->connection()->commit();
                if ($n) { return $this->job($r['id']); }
            } catch (Throwable $e) { if ($this->db->connection()->inTransaction()) { $this->db->connection()->rollBack(); } throw $e; }
        }
        return null;
    }
    function job(string $id): array {
        $r = $this->db->query_bind('SELECT * FROM jobs WHERE id=?', [$id], 'statement')->fetch();
        if (!$r) { throw new RuntimeException('Unknown operation'); } return $r;
    }
    function heartbeat(string $id, string $owner): void {
        if (!$this->db->query_bind("UPDATE jobs SET lease=? WHERE id=? AND owner=? AND status='running'", [time()+90,$id,$owner], 'statement')->rowCount()) {
            throw new RuntimeException('Worker lease lost');
        }
    }
    function finish(string $id, string $owner, string $status, array $result): void {
        $this->db->connection()->beginTransaction();
        try {
            $n = $this->db->query_bind("UPDATE jobs SET status=?,result=?,lease=0 WHERE id=? AND owner=? AND status='running'", [$status,self::_encode_json($result),$id,$owner], 'statement')->rowCount();
            if (!$n) { throw new RuntimeException('Worker lease lost'); }
            if (in_array($status,['succeeded','failed','waiting'])) { $this->db->query_bind('DELETE FROM resource_locks WHERE operation=?', [$id], 'statement'); }
            $this->db->connection()->commit();
        } catch (Throwable $e) { $this->db->connection()->rollBack(); throw $e; }
    }
    function step(string $id, string $step, array $data): void {
        $this->db->query_bind('INSERT INTO steps VALUES(?,?,?,?,?)', [bin2hex(random_bytes(16)),$id,time(),$step,self::_encode_json($data)], 'statement');
    }
    function resolve(string $id,array $result): void {
        if (!in_array($result['status'],['succeeded','failed'])) { return; }
        $this->db->connection()->beginTransaction();
        try {
            $n=$this->db->query_bind("UPDATE jobs SET status=?,result=? WHERE id=? AND status='uncertain'",[$result['status'],self::_encode_json($result),$id], 'statement')->rowCount();
            if (!$n) { throw new RuntimeException('Operation changed during recovery; inspect it again'); }
            $this->db->query_bind('DELETE FROM resource_locks WHERE operation=?',[$id], 'statement');
            $this->step($id,'operator-reconciled',$result); $this->db->connection()->commit();
        } catch (Throwable $e) { $this->db->connection()->rollBack(); throw $e; }
    }
    function _recent(): array {
        return $this->db->query_bind('SELECT id,resource,kind,status,attempts,created,result FROM jobs ORDER BY created DESC LIMIT 100', [], 'statement')->fetchAll();
    }

    function _details(string $id): array {
        return ['job' => $this->job($id), 'steps' => $this->db->query_bind(
            'SELECT at,step,data FROM steps WHERE operation=? ORDER BY at,id', [$id], 'statement')->fetchAll()];
    }

    function _retry(string $id): string {
        $changed = $this->db->query_bind("UPDATE jobs SET status='pending',attempts=0 WHERE id=? AND status='uncertain'", [$id], 'statement')->rowCount();
        if (!$changed) {
            throw new RuntimeException('Only uncertain jobs can be reconciled');
        }
        return $id;
    }

    private static function _encode_json(array $value): string {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
