<?php
declare(strict_types=1);

/**
 * Isolated per-app MariaDB inspection, SQL execution, and .sql setup/migration runner.
 *
 * Connects strictly over the shared MariaDB Unix socket using the app's own
 * MariaDB user (tc_<app>) so MariaDB enforces per-app database isolation.
 */
final class App_database {
    public const PAGE_SIZE = 50;
    public const MAX_RESULT_ROWS = 250;
    public const MAX_RESULT_BYTES = 1048576;
    public const MAX_QUERY_BYTES = 65536;
    public const MAX_IMPORT_BYTES = 524288;
    public const STATEMENT_SECONDS = 15;
    public const PREVIEW_CHARS = 1000;

    private const READ_KEYWORDS = ['SELECT', 'WITH', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN'];
    private const WRITE_KEYWORDS = ['INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'CREATE', 'ALTER', 'DROP', 'TRUNCATE', 'RENAME'];
    private const BINARY_TYPES = ['binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'geometry', 'point', 'linestring', 'polygon'];
    private const SKIPPED_IMPORT = '/^(START\s+TRANSACTION|BEGIN|COMMIT|ROLLBACK|(UN)?LOCK\s+TABLES?)\b/i';
    private const ALLOWED_SET = '/^SET\s+(SESSION\s+|@@SESSION\.|@@)?(NAMES|CHARACTER\s+SET|CHARSET|SQL_MODE|TIME_ZONE|FOREIGN_KEY_CHECKS|UNIQUE_CHECKS|SQL_NOTES)\b/i';
    private const FORBIDDEN_LEADING = '/^(USE|GRANT|REVOKE|LOAD|SHUTDOWN|FLUSH|RESET|PURGE|KILL|INSTALL|UNINSTALL|HANDLER|PREPARE|EXECUTE|DEALLOCATE)\b/i';
    private const FORBIDDEN_DDL = '/^(CREATE|ALTER|DROP)\s+(DATABASE|SCHEMA|USER|ROLE|EVENT|SERVER|TABLESPACE|LOGFILE)\b/i';

    public static function open(string $socket, string $database, string $user, string $password, int $statement_seconds = self::STATEMENT_SECONDS): PDO {
        @ini_set('mysqlnd.net_read_timeout', (string) ($statement_seconds + 5));
        $dsn = 'mysql:unix_socket=' . $socket . ';dbname=' . $database . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5,
            PDO::MYSQL_ATTR_LOCAL_INFILE => false,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
            PDO::ATTR_STRINGIFY_FETCHES => true,
        ]);
        try {
            $pdo->exec('SET SESSION max_statement_time = ' . max(1, min(60, $statement_seconds)));
        } catch (PDOException) {
            // Non-MariaDB engines (e.g. SQLite in unit tests) do not support max_statement_time.
        }
        return $pdo;
    }

    /** @return array{database: string, user: string, password: string}|null */
    public static function credentials_from_overlay(string $path): ?array {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $raw = (string) file_get_contents($path);
        $extract = static function (string $pattern) use ($raw): ?string {
            if (preg_match($pattern, $raw, $m) !== 1) {
                return null;
            }
            return stripcslashes($m[1]);
        };

        $user = $extract("/'user'\s*=>\s*'((?:[^'\\\\]|\\\\.)*)'/")
            ?? $extract("/define\('USER',\s*'((?:[^'\\\\]|\\\\.)*)'\)/");
        $password = $extract("/'password'\s*=>\s*'((?:[^'\\\\]|\\\\.)*)'/")
            ?? $extract("/define\('PASSWORD',\s*'((?:[^'\\\\]|\\\\.)*)'\)/");
        $database = $extract("/'database'\s*=>\s*'((?:[^'\\\\]|\\\\.)*)'/")
            ?? $extract("/define\('DATABASE',\s*'((?:[^'\\\\]|\\\\.)*)'\)/");

        if ($user === null || $password === null || $database === null || $database === '') {
            return null;
        }
        return ['database' => $database, 'user' => $user, 'password' => $password];
    }

    /** @return list<array{path: string, bytes: int}> */
    public static function discover_sql_files(string $release_dir): array {
        if ($release_dir === '' || !is_dir($release_dir)) {
            return [];
        }
        $base = rtrim(str_replace('\\', '/', $release_dir), '/');
        $found = [];
        $walk = static function (string $dir, int $depth) use (&$walk, &$found, $base): void {
            if ($depth > 4 || count($found) >= 50) {
                return;
            }
            $entries = @scandir($dir);
            if (!is_array($entries)) {
                return;
            }
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                if (in_array($entry, ['node_modules', 'vendor', '.git', 'storage', 'tmp'], true)) {
                    continue;
                }
                $full = $dir . '/' . $entry;
                if (is_link($full)) {
                    continue;
                }
                if (is_dir($full)) {
                    $walk($full, $depth + 1);
                    continue;
                }
                if (!is_file($full) || !preg_match('/\.sql$/i', $entry)) {
                    continue;
                }
                $rel = ltrim(substr(str_replace('\\', '/', $full), strlen($base)), '/');
                if (self::is_offered_sql_path($rel)) {
                    $found[] = ['path' => $rel, 'bytes' => (int) (@filesize($full) ?: 0)];
                }
            }
        };
        $walk($base, 0);

        $priority = static function (string $path): int {
            $lower = strtolower($path);
            if (in_array($lower, ['schema.sql', 'database/schema.sql', 'db/schema.sql', 'sql/schema.sql'], true)) {
                return 0;
            }
            if (str_ends_with($lower, '/setup.sql') || $lower === 'setup.sql') {
                return 1;
            }
            return 2;
        };

        usort($found, static function (array $a, array $b) use ($priority): int {
            $pa = $priority($a['path']);
            $pb = $priority($b['path']);
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }
            return strnatcasecmp($a['path'], $b['path']);
        });

        return $found;
    }

    public static function is_offered_sql_path(string $rel): bool {
        if ($rel === '' || str_contains($rel, '..') || str_starts_with($rel, '/')) {
            return false;
        }
        foreach ([
            '#^[^/]+\.sql$#i',
            '#^(database|db|sql|migrations)/.+\.sql$#i',
            '#^modules/[^/]+/(?:[^/]+/)*[^/]+\.sql$#i',
        ] as $pattern) {
            if (preg_match($pattern, $rel) === 1) {
                return true;
            }
        }
        return false;
    }

    public static function inspect(PDO $pdo, string $database, ?string $table = null, string $view = 'data', int $page = 1, array $sql_files = []): array {
        $stmt = $pdo->query(
            'SELECT TABLE_NAME, TABLE_TYPE, TABLE_ROWS, DATA_LENGTH + INDEX_LENGTH AS bytes '
            . 'FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME'
        );
        $tables = [];
        foreach ($stmt->fetchAll() as $row) {
            $is_view = ($row['TABLE_TYPE'] ?? '') === 'VIEW';
            $name = (string) $row['TABLE_NAME'];
            $tables[$name] = [
                'name' => $name,
                'view' => $is_view,
                'rows' => $is_view || $row['TABLE_ROWS'] === null ? null : (int) $row['TABLE_ROWS'],
                'bytes' => $row['bytes'] === null ? null : (int) $row['bytes'],
            ];
        }

        $selected_table = ($table !== null && isset($tables[$table])) ? $table : null;
        $columns = [];
        $indexes = [];
        $rows = [];
        $has_next = false;
        $page = max(1, min(10000, $page));

        if ($selected_table !== null) {
            $col_stmt = $pdo->prepare(
                'SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, EXTRA, COLUMN_DEFAULT '
                . 'FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION'
            );
            $col_stmt->execute([$selected_table]);
            $binary_cols = [];
            foreach ($col_stmt->fetchAll() as $c) {
                $type = strtolower((string) $c['DATA_TYPE']);
                $is_bin = in_array($type, self::BINARY_TYPES, true);
                if ($is_bin) {
                    $binary_cols[(string) $c['COLUMN_NAME']] = true;
                }
                $columns[] = [
                    'name' => (string) $c['COLUMN_NAME'],
                    'type' => $type,
                    'column_type' => (string) $c['COLUMN_TYPE'],
                    'nullable' => ($c['IS_NULLABLE'] ?? '') === 'YES',
                    'key' => (string) ($c['COLUMN_KEY'] ?? ''),
                    'default' => $c['COLUMN_DEFAULT'] === null ? null : (string) $c['COLUMN_DEFAULT'],
                    'extra' => (string) ($c['EXTRA'] ?? ''),
                ];
            }

            if ($view === 'structure') {
                if (!$tables[$selected_table]['view']) {
                    $idx_stmt = $pdo->query('SHOW INDEX FROM ' . self::quote_name($selected_table));
                    foreach ($idx_stmt->fetchAll() as $idx) {
                        $indexes[] = [
                            'name' => (string) ($idx['Key_name'] ?? ''),
                            'column' => (string) ($idx['Column_name'] ?? ''),
                            'unique' => ((int) ($idx['Non_unique'] ?? 1)) === 0,
                            'type' => (string) ($idx['Index_type'] ?? ''),
                        ];
                    }
                }
            } else {
                $offset = ($page - 1) * self::PAGE_SIZE;
                $limit = self::PAGE_SIZE + 1;
                $data_stmt = $pdo->query('SELECT * FROM ' . self::quote_name($selected_table) . ' LIMIT ' . $limit . ' OFFSET ' . $offset);
                $fetched = $data_stmt->fetchAll(PDO::FETCH_NUM);
                if (count($fetched) > self::PAGE_SIZE) {
                    $has_next = true;
                    array_pop($fetched);
                }
                foreach ($fetched as $r) {
                    $formatted = [];
                    foreach ($r as $i => $val) {
                        $col_name = $columns[$i]['name'] ?? '';
                        $formatted[] = self::format_cell($val, isset($binary_cols[$col_name]));
                    }
                    $rows[] = $formatted;
                }
            }
        }

        return [
            'ok' => true,
            'database' => $database,
            'tables' => array_values($tables),
            'sql_files' => $sql_files,
            'table' => $selected_table,
            'columns' => $columns,
            'indexes' => $indexes,
            'rows' => $rows,
            'page' => $page,
            'has_next' => $has_next,
        ];
    }

    /** @return list<string> */
    public static function split_statements(string $sql): array {
        $statements = [];
        $current = '';
        $len = strlen($sql);
        $i = 0;
        $quote = null;

        while ($i < $len) {
            $ch = $sql[$i];
            $next = $i + 1 < $len ? $sql[$i + 1] : '';

            if ($quote !== null) {
                $current .= $ch;
                if ($ch === '\\' && $quote !== '`' && $i + 1 < $len) {
                    $current .= $sql[$i + 1];
                    $i += 2;
                    continue;
                }
                if ($ch === $quote) {
                    if ($next === $quote) {
                        $current .= $next;
                        $i += 2;
                        continue;
                    }
                    $quote = null;
                }
                $i++;
                continue;
            }

            // Line comments: -- or #
            if ($ch === '#' || ($ch === '-' && $next === '-' && ($i + 2 >= $len || preg_match('/\s/', $sql[$i + 2]) === 1))) {
                $nl = strpos($sql, "\n", $i);
                if ($nl === false) {
                    $current .= substr($sql, $i);
                    break;
                }
                $current .= substr($sql, $i, $nl - $i + 1);
                $i = $nl + 1;
                continue;
            }

            // Block comments: /* ... */
            if ($ch === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                if ($end === false) {
                    $current .= substr($sql, $i);
                    break;
                }
                $current .= substr($sql, $i, $end - $i + 2);
                $i = $end + 2;
                continue;
            }

            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $quote = $ch;
                $current .= $ch;
                $i++;
                continue;
            }

            if ($ch === ';') {
                $trimmed = trim($current);
                if ($trimmed !== '' && self::strip_leading_comments($trimmed) !== '') {
                    $statements[] = $trimmed;
                }
                $current = '';
                $i++;
                continue;
            }

            $current .= $ch;
            $i++;
        }

        $trimmed = trim($current);
        if ($trimmed !== '' && self::strip_leading_comments($trimmed) !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }

    /** @return array{statements: list<string>, skipped: int, refusal: ?string} */
    public static function validate(string $sql, bool $allow_changes = false, string $mode = 'query'): array {
        $is_import = $mode === 'import';
        $max_bytes = $is_import ? self::MAX_IMPORT_BYTES : self::MAX_QUERY_BYTES;
        if (strlen($sql) > $max_bytes) {
            $kb = intdiv($max_bytes, 1024);
            return ['statements' => [], 'skipped' => 0, 'refusal' => "SQL exceeds the {$kb} KB limit."];
        }
        if (str_contains($sql, "\0")) {
            return ['statements' => [], 'skipped' => 0, 'refusal' => 'SQL contains invalid null bytes.'];
        }

        $raw_statements = self::split_statements($sql);
        if ($raw_statements === []) {
            return ['statements' => [], 'skipped' => 0, 'refusal' => 'Enter at least one SQL statement.'];
        }

        $allowed = [];
        $skipped = 0;
        $total = count($raw_statements);

        foreach ($raw_statements as $idx => $stmt) {
            $start = self::strip_leading_comments($stmt);
            $prefix = $total > 1 ? 'Statement ' . ($idx + 1) . ': ' : '';

            if ($is_import && preg_match(self::SKIPPED_IMPORT, $start) === 1) {
                $skipped++;
                continue;
            }

            $unquoted = self::strip_quoted_and_comments($stmt);
            if (preg_match('/\bINTO\s+(OUTFILE|DUMPFILE)\b/i', $unquoted) === 1) {
                return ['statements' => [], 'skipped' => 0, 'refusal' => $prefix . 'INTO OUTFILE and INTO DUMPFILE are not allowed.'];
            }
            if (preg_match('/\bLOAD_FILE\s*\(/i', $unquoted) === 1) {
                return ['statements' => [], 'skipped' => 0, 'refusal' => $prefix . 'LOAD_FILE() is not allowed.'];
            }
            if (preg_match(self::FORBIDDEN_LEADING, $start) === 1 || preg_match(self::FORBIDDEN_DDL, $start) === 1) {
                return ['statements' => [], 'skipped' => 0, 'refusal' => $prefix . 'This statement is not allowed here. Queries run inside your app database only.'];
            }

            if (preg_match('/^SET\b/i', $start) === 1) {
                if (!$is_import) {
                    return ['statements' => [], 'skipped' => 0, 'refusal' => $prefix . 'SET statements are only allowed in SQL imports.'];
                }
                if (preg_match(self::ALLOWED_SET, $unquoted) !== 1 || str_contains($unquoted, ',')) {
                    return ['statements' => [], 'skipped' => 0, 'refusal' => $prefix . 'Only SET NAMES, CHARACTER SET, SQL_MODE, TIME_ZONE, FOREIGN_KEY_CHECKS, UNIQUE_CHECKS, or SQL_NOTES is allowed.'];
                }
                $allowed[] = $stmt;
                continue;
            }

            if (preg_match('/^([A-Za-z]+)/', $start, $kw) !== 1) {
                return ['statements' => [], 'skipped' => 0, 'refusal' => $prefix . 'Unsupported SQL statement.'];
            }
            $keyword = strtoupper($kw[1]);
            if (in_array($keyword, self::READ_KEYWORDS, true)) {
                $allowed[] = $stmt;
                continue;
            }
            if (in_array($keyword, self::WRITE_KEYWORDS, true)) {
                if (!$allow_changes) {
                    return ['statements' => [], 'skipped' => 0, 'refusal' => $prefix . 'Changes are off. Tick "Allow changes" to run statements that create, alter, or modify data.'];
                }
                $allowed[] = $stmt;
                continue;
            }

            return ['statements' => [], 'skipped' => 0, 'refusal' => $prefix . 'Only SELECT, SHOW, DESCRIBE, EXPLAIN, CREATE, ALTER, DROP, TRUNCATE, RENAME, INSERT, UPDATE, DELETE, and REPLACE are supported here.'];
        }

        if ($allowed === []) {
            return ['statements' => [], 'skipped' => $skipped, 'refusal' => 'No runnable SQL statements found.'];
        }

        return ['statements' => $allowed, 'skipped' => $skipped, 'refusal' => null];
    }

    public static function execute(PDO $pdo, string $sql, bool $allow_changes = false, string $mode = 'query'): array {
        $is_import = $mode === 'import';
        $plan = self::validate($sql, $allow_changes || $is_import, $mode);
        if ($plan['refusal'] !== null) {
            return ['ok' => false, 'error' => $plan['refusal']];
        }

        $statements = $plan['statements'];
        if ($is_import) {
            try {
                $pdo->exec('SET SESSION TRANSACTION READ WRITE');
            } catch (PDOException) {
                // Non-MariaDB driver fallback.
            }
            $executed = 0;
            $started = microtime(true);
            foreach ($statements as $idx => $stmt) {
                try {
                    $pdo->exec($stmt);
                    $executed++;
                } catch (PDOException $e) {
                    return [
                        'ok' => false,
                        'error' => 'Statement ' . ($idx + 1) . ' failed: ' . self::clean_pdo_error($e),
                        'executed' => $executed,
                        'skipped' => $plan['skipped'],
                    ];
                }
            }
            return [
                'ok' => true,
                'executed' => $executed,
                'skipped' => $plan['skipped'],
                'ms' => round((microtime(true) - $started) * 1000, 1),
            ];
        }

        $results = [];
        $bytes_left = self::MAX_RESULT_BYTES;
        $committed = [];
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $pdo->exec('SET SESSION TRANSACTION ' . ($allow_changes ? 'READ WRITE' : 'READ ONLY'));
        }
        $pdo->beginTransaction();

        try {
            foreach ($statements as $idx => $stmt) {
                $started = microtime(true);
                $q = $pdo->query($stmt);
                $elapsed = round((microtime(true) - $started) * 1000, 1);

                if ($q->columnCount() === 0) {
                    $results[] = [
                        'statement' => self::summarize_statement($stmt),
                        'columns' => [],
                        'rows' => [],
                        'truncated' => false,
                        'affected' => $q->rowCount(),
                        'ms' => $elapsed,
                    ];
                } else {
                    $cols = [];
                    for ($c = 0; $c < $q->columnCount(); $c++) {
                        $meta = $q->getColumnMeta($c);
                        $cols[] = (string) ($meta['name'] ?? ('col_' . $c));
                    }
                    $rows = [];
                    $truncated = false;
                    while (($row = $q->fetch(PDO::FETCH_NUM)) !== false) {
                        $row_bytes = 0;
                        $formatted = [];
                        foreach ($row as $val) {
                            $row_bytes += strlen((string) $val);
                            $formatted[] = self::format_cell($val, false);
                        }
                        $bytes_left -= $row_bytes;
                        if (count($rows) >= self::MAX_RESULT_ROWS || $bytes_left < 0) {
                            $truncated = true;
                            break;
                        }
                        $rows[] = $formatted;
                    }
                    $q->closeCursor();
                    $results[] = [
                        'statement' => self::summarize_statement($stmt),
                        'columns' => $cols,
                        'rows' => $rows,
                        'truncated' => $truncated,
                        'affected' => null,
                        'ms' => $elapsed,
                    ];
                }

                if (preg_match('/^(CREATE|ALTER|DROP|TRUNCATE|RENAME)\b/i', self::strip_leading_comments($stmt)) === 1) {
                    $committed[] = $idx + 1;
                }
            }

            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $msg = self::clean_pdo_error($e);
            if (count($statements) > 1) {
                $failed = count($results) + 1;
                $msg = 'Statement ' . $failed . ' failed: ' . rtrim($msg, '.') . '. ';
                if ($committed === []) {
                    $msg .= 'Earlier statements were rolled back.';
                } else {
                    $last = max($committed);
                    $msg .= 'Statements 1–' . $last . ' were already committed by DDL.';
                }
            }
            return ['ok' => false, 'error' => $msg];
        }

        return ['ok' => true, 'results' => $results];
    }

    public static function quote_name(string $name): string {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    private static function format_cell(mixed $val, bool $force_binary): ?string {
        if ($val === null) {
            return null;
        }
        $str = (string) $val;
        if ($force_binary || preg_match('//u', $str) !== 1) {
            return '[' . strlen($str) . ' bytes]';
        }
        if (strlen($str) > self::PREVIEW_CHARS) {
            return substr($str, 0, self::PREVIEW_CHARS) . '…';
        }
        return $str;
    }

    private static function strip_leading_comments(string $sql): string {
        $s = ltrim($sql);
        while ($s !== '') {
            if (str_starts_with($s, '--') || str_starts_with($s, '#')) {
                $nl = strpos($s, "\n");
                $s = $nl === false ? '' : ltrim(substr($s, $nl + 1));
                continue;
            }
            if (str_starts_with($s, '/*')) {
                $end = strpos($s, '*/', 2);
                $s = $end === false ? '' : ltrim(substr($s, $end + 2));
                continue;
            }
            break;
        }
        return $s;
    }

    private static function strip_quoted_and_comments(string $sql): string {
        $s = (string) preg_replace('/\/\*.*?\*\//s', ' ', $sql);
        $s = (string) preg_replace('/(--[^\n]*|#[^\n]*)/', ' ', $s);
        $s = (string) preg_replace('/\'(?:[^\'\\\\]|\\\\.|\'\')*\'|"(?:[^"\\\\]|\\\\.|"")*"/s', "''", $s);
        return trim($s);
    }

    private static function summarize_statement(string $stmt): string {
        $one = trim((string) preg_replace('/\s+/', ' ', $stmt));
        return strlen($one) > 160 ? substr($one, 0, 159) . '…' : $one;
    }

    private static function clean_pdo_error(PDOException $e): string {
        return (string) preg_replace('/^SQLSTATE\[\w+\]: [^:]*: (\d+ )?/', '', $e->getMessage());
    }
}
