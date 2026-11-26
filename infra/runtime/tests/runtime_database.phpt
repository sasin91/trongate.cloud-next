--TEST--
App_database SQL splitting, safety validation, credentials parsing, .sql discovery, and PDO execution
--FILE--
<?php
require dirname(__DIR__) . '/app_database.php';

// 1. Statement splitting respects quotes, escapes, and comments.
$stmts = App_database::split_statements(<<<'SQL'
-- Leading comment
CREATE TABLE `demo_table` (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL -- inline ; comment
);
/* block ; comment */
INSERT INTO `demo_table` (id, name) VALUES (1, 'hello; world'), (2, 'it''s; fine');
# hash ; comment
SELECT id, name FROM `demo_table` ORDER BY id;
SQL);
var_dump(count($stmts) === 3);

// 2. Safety validation: read-only default, allow_changes, forbidden statements, import skips.
$ro = App_database::validate("INSERT INTO demo_table VALUES (1, 'a');", false, 'query');
var_dump($ro['refusal'] !== null && str_contains($ro['refusal'], 'Allow changes'));

$rw = App_database::validate("CREATE TABLE t (id INT); INSERT INTO t VALUES (1);", true, 'query');
var_dump($rw['refusal'] === null && count($rw['statements']) === 2);

foreach ([
    'USE other_db;',
    'DROP DATABASE tc_demo;',
    'GRANT ALL ON *.* TO root;',
    "SELECT * INTO OUTFILE '/tmp/x' FROM demo_table;",
    "SELECT LOAD_FILE('/etc/passwd');",
    'SET GLOBAL max_connections = 10;',
] as $bad) {
    $v = App_database::validate($bad, true, 'import');
    if ($v['refusal'] === null) {
        echo "FAIL allowed forbidden SQL: {$bad}\n";
    }
}
echo "forbidden=rejected\n";

$imp = App_database::validate(<<<'SQL'
SET NAMES utf8mb4;
START TRANSACTION;
LOCK TABLES `demo_table` WRITE;
CREATE TABLE IF NOT EXISTS `demo_table` (id INT PRIMARY KEY);
UNLOCK TABLES;
COMMIT;
SQL, true, 'import');
var_dump($imp['refusal'] === null, count($imp['statements']) === 2, $imp['skipped'] === 4);

// 3. Execute against SQLite PDO in query and import modes.
$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$res_imp = App_database::execute($pdo, "CREATE TABLE demo_table (id INTEGER PRIMARY KEY, name TEXT); INSERT INTO demo_table VALUES (1, 'Alice'), (2, NULL);", true, 'import');
var_dump($res_imp['ok'] === true, $res_imp['executed'] === 2);

$res_q = App_database::execute($pdo, 'SELECT id, name FROM demo_table ORDER BY id;', false, 'query');
var_dump(
    $res_q['ok'] === true,
    count($res_q['results']) === 1,
    $res_q['results'][0]['columns'] === ['id', 'name'],
    count($res_q['results'][0]['rows']) === 2,
    $res_q['results'][0]['rows'][0][1] === 'Alice',
    $res_q['results'][0]['rows'][1][1] === null
);

// 4. Credentials parsing from both v2 and v1 overlay formats + SQL file discovery.
$tmp = sys_get_temp_dir() . '/tc-appdb-' . bin2hex(random_bytes(6));
mkdir($tmp . '/modules/demo/sql', 0755, true);
mkdir($tmp . '/node_modules/pkg', 0755, true);
mkdir($tmp . '/migrations', 0755, true);
file_put_contents($tmp . '/schema.sql', 'CREATE TABLE a (id INT);');
file_put_contents($tmp . '/modules/demo/sql/setup.sql', 'CREATE TABLE b (id INT);');
file_put_contents($tmp . '/migrations/001_init.sql', 'CREATE TABLE c (id INT);');
file_put_contents($tmp . '/node_modules/pkg/ignored.sql', 'SELECT 1;');

$discovered = array_column(App_database::discover_sql_files($tmp), 'path');
var_dump($discovered === ['schema.sql', 'modules/demo/sql/setup.sql', 'migrations/001_init.sql']);

$db_v2 = $tmp . '/database_v2.php';
file_put_contents($db_v2, "<?php\n\$databases = ['default' => ['host' => 'localhost', 'port' => '3306', 'user' => 'tc_x', 'password' => 's\\'ecret', 'database' => 'tc_x']];\n");
$c2 = App_database::credentials_from_overlay($db_v2);
var_dump($c2 === ['database' => 'tc_x', 'user' => 'tc_x', 'password' => "s'ecret"]);

$db_v1 = $tmp . '/database_v1.php';
file_put_contents($db_v1, "<?php\ndefine('USER', 'tc_y');\ndefine('PASSWORD', 'pw123');\ndefine('DATABASE', 'tc_y');\n");
$c1 = App_database::credentials_from_overlay($db_v1);
var_dump($c1 === ['database' => 'tc_y', 'user' => 'tc_y', 'password' => 'pw123']);

foreach (['schema.sql', 'modules/demo/sql/setup.sql', 'migrations/001_init.sql', 'node_modules/pkg/ignored.sql', 'database_v2.php', 'database_v1.php'] as $f) {
    unlink($tmp . '/' . $f);
}
rmdir($tmp . '/modules/demo/sql');
rmdir($tmp . '/modules/demo');
rmdir($tmp . '/modules');
rmdir($tmp . '/node_modules/pkg');
rmdir($tmp . '/node_modules');
rmdir($tmp . '/migrations');
rmdir($tmp);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
forbidden=rejected
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
