<?php
trait Inventory_records {
    function get(string $kind, string $id): array {
        $row = $this->run('SELECT data FROM records WHERE kind=? AND id=?', [$kind,$id])->fetch();
        if (!$row) { throw new RuntimeException("Missing $kind record"); }
        return json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR);
    }
    function put(string $kind, string $id, array $data): void {
        // REPLACE is portable to MariaDB and SQLite; records have no dependent FKs.
        $this->run('REPLACE INTO records(kind,id,data) VALUES(?,?,?)', [$kind,$id,tc_json($data)]);
    }
    function all(string $kind): array {
        return array_map(fn($r) => ['id'=>$r['id']] + json_decode($r['data'], true), $this->run('SELECT id,data FROM records WHERE kind=? ORDER BY id', [$kind])->fetchAll());
    }
}
