<?php
declare(strict_types=1);

/**
 * Minimal PHP-internals-style .phpt test runner.
 *
 * Usage:
 *   php tests/run-tests.php [-j N] [path ...]
 *
 * Supported sections:
 *   --TEST--, --SKIPIF--, --INI--, --ENV--, --FILE--,
 *   --EXPECT--, --EXPECTF--, --CLEAN--
 */

$root = dirname(__DIR__);
$jobs = 4;
$targets = [];

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if ($arg === '-j' && isset($argv[$i + 1])) {
        $jobs = max(1, (int) $argv[++$i]);
    } elseif (preg_match('/^-j(\d+)$/', $arg, $m)) {
        $jobs = max(1, (int) $m[1]);
    } else {
        $targets[] = $arg;
    }
}

if ($targets === []) {
    $targets = ['engine', 'modules', 'infra', 'tests'];
}

$files = [];
foreach ($targets as $target) {
    $path = is_file($target) || is_dir($target) ? $target : $root . DIRECTORY_SEPARATOR . $target;
    if (is_file($path) && str_ends_with($path, '.phpt')) {
        $files[] = realpath($path) ?: $path;
    } elseif (is_dir($path)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $entry) {
            if ($entry->isFile() && str_ends_with($entry->getFilename(), '.phpt')) {
                $files[] = $entry->getRealPath() ?: $entry->getPathname();
            }
        }
    }
}
$files = array_values(array_unique($files));
sort($files, SORT_STRING);

if ($files === []) {
    fwrite(STDERR, "No .phpt tests found.\n");
    exit(1);
}

function parse_phpt(string $path): array {
    $content = str_replace("\r\n", "\n", (string) file_get_contents($path));
    $sections = [];
    $current = null;
    foreach (explode("\n", $content) as $line) {
        if (preg_match('/^--([A-Z0-9_]+)--$/', $line, $m)) {
            $current = $m[1];
            $sections[$current] = '';
        } elseif ($current !== null) {
            $sections[$current] .= $line . "\n";
        }
    }
    return $sections;
}

function expectf_to_regex(string $pattern): string {
    $quoted = preg_quote(trim($pattern), '/');
    $map = [
        '%%' => '%',
        '%e' => preg_quote(DIRECTORY_SEPARATOR, '/'),
        '%s' => '[^\r\n]+',
        '%S' => '[^\r\n]*',
        '%a' => '.+',
        '%A' => '.*',
        '%w' => '\s*',
        '%i' => '[+-]?\d+',
        '%d' => '\d+',
        '%x' => '[0-9a-fA-F]+',
        '%f' => '[+-]?\.?\d+\.?\d*(?:[Ee][+-]?\d+)?',
        '%c' => '.',
    ];
    return '/^' . strtr($quoted, $map) . '$/s';
}

function build_env(array $sections): array {
    $env = getenv();
    if (!is_array($env)) {
        $env = [];
    }
    if (isset($sections['ENV'])) {
        foreach (explode("\n", trim($sections['ENV'])) as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $env[trim($k)] = $v;
        }
    }
    return $env;
}

function build_ini_args(array $sections): array {
    $args = [];
    if (isset($sections['INI'])) {
        foreach (explode("\n", trim($sections['INI'])) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, ';')) {
                continue;
            }
            $args[] = '-d';
            $args[] = $line;
        }
    }
    return $args;
}

function run_sync(array $cmd, array $env, string $cwd): array {
    $out = tmpfile();
    $err = tmpfile();
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => $out, 2 => $err], $pipes, $cwd, $env);
    if (!is_resource($proc)) {
        fclose($out);
        fclose($err);
        return [1, 'Failed to launch PHP subprocess'];
    }
    fclose($pipes[0]);
    $exit = proc_close($proc);
    rewind($out);
    rewind($err);
    $stdout = (string) stream_get_contents($out);
    $stderr = (string) stream_get_contents($err);
    fclose($out);
    fclose($err);
    return [$exit, $stdout . ($stderr !== '' ? "\n" . $stderr : '')];
}

function unified_diff(string $expected, string $actual): string {
    $exp = explode("\n", $expected);
    $act = explode("\n", $actual);
    $out = ["--- expected", "+++ actual"];
    $max = max(count($exp), count($act));
    for ($i = 0; $i < $max; $i++) {
        $e = $exp[$i] ?? null;
        $a = $act[$i] ?? null;
        if ($e === $a) {
            $out[] = ' ' . $e;
        } else {
            if ($e !== null) {
                $out[] = '-' . $e;
            }
            if ($a !== null) {
                $out[] = '+' . $a;
            }
        }
    }
    return implode("\n", $out) . "\n";
}

function rel_path(string $root, string $file): string {
    $prefix = rtrim(str_replace('\\', '/', $root), '/') . '/';
    $norm = str_replace('\\', '/', $file);
    return str_starts_with($norm, $prefix) ? substr($norm, strlen($prefix)) : $norm;
}

$start = microtime(true);
$passed = 0;
$skipped = 0;
$failed = [];
$queue = $files;
$running = [];

while ($queue !== [] || $running !== []) {
    while (count($running) < $jobs && $queue !== []) {
        $file = array_shift($queue);
        $rel = rel_path($root, $file);
        $sections = parse_phpt($file);
        $title = trim($sections['TEST'] ?? $rel);
        $base = substr($file, 0, -5);
        $cwd = dirname($file);
        $env = build_env($sections);
        $ini = build_ini_args($sections);

        foreach (['.php', '.skip.php', '.clean.php', '.out', '.exp', '.diff'] as $ext) {
            if (is_file($base . $ext)) {
                @unlink($base . $ext);
            }
        }

        if (!isset($sections['FILE']) || (!isset($sections['EXPECT']) && !isset($sections['EXPECTF']))) {
            $failed[] = [$rel, $title, 'Missing --FILE-- or --EXPECT--/--EXPECTF-- section'];
            echo "FAIL {$title} [{$rel}]\n";
            continue;
        }

        if (isset($sections['SKIPIF'])) {
            $skipFile = $base . '.skip.php';
            file_put_contents($skipFile, $sections['SKIPIF']);
            [, $skipOut] = run_sync(array_merge([PHP_BINARY], $ini, [$skipFile]), $env, $cwd);
            @unlink($skipFile);
            if (preg_match('/^\s*skip\b(.*)$/i', trim($skipOut), $m)) {
                $reason = trim($m[1]);
                $skipped++;
                echo "SKIP {$title} [{$rel}]" . ($reason !== '' ? " reason: {$reason}" : '') . "\n";
                continue;
            }
        }

        $testFile = $base . '.php';
        file_put_contents($testFile, $sections['FILE']);
        $outStream = tmpfile();
        $errStream = tmpfile();
        $cmd = array_merge([PHP_BINARY], $ini, [$testFile]);
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => $outStream, 2 => $errStream], $pipes, $cwd, $env);
        if (!is_resource($proc)) {
            @unlink($testFile);
            fclose($outStream);
            fclose($errStream);
            $failed[] = [$rel, $title, 'Could not spawn PHP process'];
            echo "FAIL {$title} [{$rel}]\n";
            continue;
        }
        fclose($pipes[0]);
        $running[] = [
            'file' => $file,
            'rel' => $rel,
            'base' => $base,
            'cwd' => $cwd,
            'title' => $title,
            'sections' => $sections,
            'env' => $env,
            'ini' => $ini,
            'proc' => $proc,
            'out' => $outStream,
            'err' => $errStream,
        ];
    }

    $progressed = false;
    foreach ($running as $idx => $item) {
        $status = proc_get_status($item['proc']);
        if ($status['running']) {
            continue;
        }
        $progressed = true;
        $exit = proc_close($item['proc']);
        rewind($item['out']);
        rewind($item['err']);
        $stdout = str_replace("\r\n", "\n", (string) stream_get_contents($item['out']));
        $stderr = str_replace("\r\n", "\n", (string) stream_get_contents($item['err']));
        fclose($item['out']);
        fclose($item['err']);

        if (isset($item['sections']['CLEAN'])) {
            $cleanFile = $item['base'] . '.clean.php';
            file_put_contents($cleanFile, $item['sections']['CLEAN']);
            run_sync(array_merge([PHP_BINARY], $item['ini'], [$cleanFile]), $item['env'], $item['cwd']);
            @unlink($cleanFile);
        }

        $actual = trim($stdout . ($stderr !== '' ? ($stdout !== '' && !str_ends_with($stdout, "\n") ? "\n" : '') . $stderr : ''));
        $isExpectF = isset($item['sections']['EXPECTF']);
        $expected = trim($isExpectF ? $item['sections']['EXPECTF'] : $item['sections']['EXPECT']);
        $ok = $exit === 0 && ($isExpectF
            ? preg_match(expectf_to_regex($expected), $actual) === 1
            : $actual === $expected);

        if ($ok) {
            @unlink($item['base'] . '.php');
            $passed++;
            echo "PASS {$item['title']} [{$item['rel']}]\n";
        } else {
            $diff = ($exit !== 0 ? "Exit code: {$exit}\n" : '') . unified_diff($expected, $actual);
            file_put_contents($item['base'] . '.exp', $expected . "\n");
            file_put_contents($item['base'] . '.out', $actual . "\n");
            file_put_contents($item['base'] . '.diff', $diff);
            $failed[] = [$item['rel'], $item['title'], $diff];
            echo "FAIL {$item['title']} [{$item['rel']}]\n{$diff}";
        }
        unset($running[$idx]);
    }

    if (!$progressed && $running !== []) {
        usleep(10000);
    }
}

$elapsed = number_format(microtime(true) - $start, 2);
$total = $passed + $skipped + count($failed);
echo "\n=====================================================================\n";
echo sprintf("Total: %d | Passed: %d | Skipped: %d | Failed: %d | Time: %ss\n", $total, $passed, $skipped, count($failed), $elapsed);
echo "=====================================================================\n";

exit($failed === [] ? 0 : 1);
