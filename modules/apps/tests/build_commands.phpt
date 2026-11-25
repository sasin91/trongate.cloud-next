--TEST--
Build_commands suggestions, normalisation, tools detection, and Build::plan validation
--FILE--
<?php
require dirname(__DIR__, 3) . '/bootstrap.php';

$with_build = '{"scripts": {"build": "vite build"}}';

// npm with lockfile
$s = Build_commands::suggest(['package.json', 'package-lock.json', 'composer.json'], $with_build, '');
echo "npm:\n", $s['commands'], "\nfound=", implode(',', $s['found']), "\n";

// bun, pnpm, yarn lockfiles
echo "bun=", str_replace("\n", ' && ', Build_commands::suggest(['package.json', 'bun.lock'], $with_build, '')['commands']), "\n";
echo "bunb=", str_replace("\n", ' && ', Build_commands::suggest(['package.json', 'bun.lockb'], $with_build, '')['commands']), "\n";
echo "pnpm=", str_replace("\n", ' && ', Build_commands::suggest(['package.json', 'pnpm-lock.yaml'], $with_build, '')['commands']), "\n";
echo "yarn=", str_replace("\n", ' && ', Build_commands::suggest(['package.json', 'yarn.lock'], $with_build, '')['commands']), "\n";
echo "yarn2=", str_replace("\n", ' && ', Build_commands::suggest(['package.json', 'yarn.lock', '.yarnrc.yml'], $with_build, '')['commands']), "\n";

// packageManager wins over stray lockfile
$pm = Build_commands::suggest(['package.json', 'package-lock.json', 'pnpm-lock.yaml'], '{"packageManager": "pnpm@9.1.0", "scripts": {"build": "x"}}', '');
echo "pm=", str_replace("\n", ' && ', $pm['commands']), " found=", implode(',', $pm['found']), "\n";

// no lockfile / no build script
echo "nolock=", str_replace("\n", ' && ', Build_commands::suggest(['package.json'], $with_build, '')['commands']), "\n";
echo "nobuild=", Build_commands::suggest(['package.json', 'package-lock.json'], '{"scripts": {"dev": "vite"}}', '')['commands'], "\n";
echo "composeronly=", Build_commands::suggest(['composer.json'], '', '{"require": {"php": "^8.4"}}')['commands'], "\n";

// composer.json ext-*
$composer = '{"require": {"php": "^8.4", "ext-imagick": "*", "ext-PDO_MySQL": "*", "ext-mbstring": "*", "ext-gmp": "*", "vendor/pkg": "^1"}, "require-dev": {"ext-xdebug": "*"}}';
$ce = Build_commands::suggest(['composer.json'], '', $composer);
echo "exts=", implode(',', $ce['php_extensions']), " found=", implode(',', $ce['found']), "\n";

// broken JSON ignored
$bad = Build_commands::suggest(['package.json'], '{not json', '[1]');
var_dump($bad === ['commands' => '', 'php_extensions' => [], 'found' => []]);

// normalise, script, error
var_dump(Build_commands::normalise("\r\n  npm ci  \r\nnpm run build\n\n") === "  npm ci\nnpm run build");
var_dump(Build_commands::script("npm ci\r\nnpm run build\n") === "npm ci\nnpm run build");
var_dump(Build_commands::error("npm ci\nnpm run build") === null);
var_dump(Build_commands::error('') === null);
var_dump(is_string(Build_commands::error("npm ci\x1b[0m")));
var_dump(is_string(Build_commands::error(str_repeat("echo\n", Build_commands::MAX_LINES + 1))));
var_dump(is_string(Build_commands::error(str_repeat('a', Build_commands::MAX_LINE_BYTES + 1))));
try {
    Build_commands::script("npm ci\x1b[0m");
    echo "FAIL ctrl accepted\n";
} catch (RuntimeException $e) {
    echo "ctrl_rejected=", (str_contains($e->getMessage(), 'control characters') ? 'yes' : 'no'), "\n";
}

// tools detection
echo "t1=", implode(',', Build_commands::tools('npm ci && npm run build')), "\n";
echo "t2=", implode(',', Build_commands::tools('pnpm install')), "\n";
echo "t3=", implode(',', Build_commands::tools('bunx tailwindcss -o public/app.css')), "\n";
echo "t4=", implode(',', Build_commands::tools("bun install\nnode build.js")), "\n";
echo "t5=", implode(',', Build_commands::tools('php bin/build.php')), "\n";
echo "t6=", implode(',', Build_commands::tools('./npm-run-all')), "\n";

// Build::plan validation
$plan = Build::plan([
    'app' => 'demo',
    'repository' => 'owner/repo',
    'commit' => str_repeat('b', 40),
    'php_version' => '8.4',
    'extensions' => ['imagick', 'gmp', 'imagick'],
    'commands' => "npm ci\r\nnpm run build\n",
]);
echo "plan=", $plan['app'], '|', $plan['repository'], '|', $plan['php_version'], '|', implode(',', $plan['extensions']), '|', implode(',', $plan['tools']), "\n";
try {
    Build::plan(['app' => 'demo', 'repository' => 'owner/repo', 'commit' => str_repeat('b', 40), 'extensions' => ['xdebug']]);
    echo "FAIL xdebug accepted\n";
} catch (RuntimeException $e) {
    echo "xdebug_rejected=", (str_contains($e->getMessage(), 'Unsupported PHP extension') ? 'yes' : 'no'), "\n";
}
?>
--EXPECT--
npm:
npm ci
npm run build
found=package-lock.json
bun=bun install --frozen-lockfile && bun run build
bunb=bun install --frozen-lockfile && bun run build
pnpm=pnpm install --frozen-lockfile && pnpm run build
yarn=yarn install --frozen-lockfile && yarn run build
yarn2=yarn install --immutable && yarn run build
pm=pnpm install --frozen-lockfile && pnpm run build found=pnpm-lock.yaml
nolock=npm install && npm run build
nobuild=
composeronly=
exts=imagick,gmp found=composer.json
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
ctrl_rejected=yes
t1=node
t2=node
t3=bun
t4=node,bun
t5=
t6=
plan=demo|owner/repo|8.4|imagick,gmp|node
xdebug_rejected=yes
