<?php
class Records extends Trongate {
    public function _get(array $record): array {
        return $this->model->get($record['kind'], $record['id']);
    }

    public function _find(array $record): ?array {
        return $this->model->find($record['kind'], $record['id']);
    }

    public function _all(string $kind): array {
        return $this->model->all($kind);
    }

    public function _put(array $record): void {
        $this->model->put($record['kind'], $record['id'], $record['data']);
    }

    public function _insert(array $record): void {
        $this->model->insert($record['kind'], $record['id'], $record['data']);
    }

    public function _delete(array $record): void {
        $this->model->delete($record['kind'], $record['id']);
    }
}
