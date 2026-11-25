<?php
class Records extends Trongate {
    function _get(array $record): array {
        return $this->model->get($record['kind'], $record['id']);
    }

    function _find(array $record): ?array {
        return $this->model->find($record['kind'], $record['id']);
    }

    function _all(string $kind): array {
        return $this->model->all($kind);
    }

    function _put(array $record): void {
        $this->model->put($record['kind'], $record['id'], $record['data']);
    }

    function _insert(array $record): void {
        $this->model->insert($record['kind'], $record['id'], $record['data']);
    }

    function _delete(array $record): void {
        $this->model->delete($record['kind'], $record['id']);
    }
}
