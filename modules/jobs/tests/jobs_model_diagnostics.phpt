--TEST--
Jobs diagnostics report claim and lock state without exposing lock owners
--FILE--
<?php
$root = dirname(__DIR__, 3);
define('APPPATH', $root . DIRECTORY_SEPARATOR);
require APPPATH . 'engine/Model.php';
require APPPATH . 'modules/jobs/Jobs_model.php';

class Jobs_diagnostics_statement {
    function __construct(private array|null $row = null, private mixed $column = false) {
    }

    function fetch(): array|false {
        return $this->row ?? false;
    }

    function fetchColumn(): mixed {
        return $this->column;
    }
}

class Jobs_diagnostics_db {
    function __construct(
        private array $job,
        private string|false $locked_operation,
        private string|false $blocking_status = false
    ) {
    }

    function query_bind(string $query, array $params = [], string $type = 'statement'): Jobs_diagnostics_statement {
        if (str_starts_with($query, 'SELECT * FROM jobs')) {
            return new Jobs_diagnostics_statement($this->job);
        }
        if (str_starts_with($query, 'SELECT operation FROM resource_locks')) {
            return new Jobs_diagnostics_statement(column: $this->locked_operation);
        }
        if (str_starts_with($query, 'SELECT status FROM jobs')) {
            return new Jobs_diagnostics_statement(column: $this->blocking_status);
        }
        throw new RuntimeException('Unexpected diagnostic query.');
    }
}

class Jobs_diagnostics_model extends Jobs_model {
    function __construct(private Jobs_diagnostics_db $fake_db) {
        parent::__construct('jobs');
    }

    function __get(string $key) {
        return $key === 'db' ? $this->fake_db : parent::__get($key);
    }
}

$id = str_repeat('a', 32);
$blocker = str_repeat('b', 32);
$job = ['id' => $id, 'resource' => 'builder:primary', 'status' => 'pending', 'attempts' => 0, 'lease' => 0];
$free = (new Jobs_diagnostics_model(new Jobs_diagnostics_db($job, false)))->_diagnostics($id);
$same = (new Jobs_diagnostics_model(new Jobs_diagnostics_db($job, $id)))->_diagnostics($id);
$blocked = (new Jobs_diagnostics_model(new Jobs_diagnostics_db($job, $blocker, 'uncertain')))->_diagnostics($id);

if ($free['resource_lock'] !== 'free' || $free['attempts'] !== 0 || $free['lease_active']) {
    throw new RuntimeException('Free queue diagnostics were incorrect.');
}
if ($same['resource_lock'] !== 'held-by-this-job') {
    throw new RuntimeException('Own resource lock was not identified.');
}
if ($blocked['resource_lock'] !== 'held-by-another-job' || $blocked['blocking_job_status'] !== 'uncertain') {
    throw new RuntimeException('Blocking resource lock was not identified.');
}
if (array_key_exists('blocking_operation', $blocked)) {
    throw new RuntimeException('Diagnostic leaked the blocking operation identifier.');
}

echo "queue diagnostics cases passed\n";
?>
--EXPECT--
queue diagnostics cases passed
