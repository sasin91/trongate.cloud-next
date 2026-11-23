<?php
class Records_model extends Model {
    public function get(string $kind, string $id): array {
        $record = $this->find($kind, $id);
        if ($record === null) {
            throw new RuntimeException("Missing $kind record");
        }
        return $record;
    }

    public function find(string $kind, string $id): ?array {
        $row = $this->db->query_bind('SELECT data FROM records WHERE kind=? AND id=?', [$kind, $id], 'statement')->fetch();
        return $row ? json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR) : null;
    }

    public function all(string $kind): array {
        $rows = $this->db->query_bind('SELECT id,data FROM records WHERE kind=? ORDER BY id', [$kind], 'statement')->fetchAll();
        return array_map(static fn(array $row): array => ['id' => $row['id']] + json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR), $rows);
    }

    public function put(string $kind, string $id, array $data): void {
        // Records have no dependent foreign keys; both supported databases accept REPLACE.
        $this->db->query_bind('REPLACE INTO records(kind,id,data) VALUES(?,?,?)', [$kind, $id, $this->_encode_json($data)], 'statement');
    }

    public function insert(string $kind, string $id, array $data): void {
        // Preserve uniqueness failures for request deduplication and app/server creation.
        $this->db->query_bind('INSERT INTO records(kind,id,data) VALUES(?,?,?)', [$kind, $id, $this->_encode_json($data)], 'statement');
    }

    public function delete(string $kind, string $id): void {
        $this->db->query_bind('DELETE FROM records WHERE kind=? AND id=?', [$kind, $id], 'statement');
    }

    private function _encode_json(array $data): string {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
