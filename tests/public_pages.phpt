--TEST--
Public landing page, dashboard preview, news, blog, Atom feeds, redirects, and static assets
--FILE--
<?php
declare(strict_types=1);

require __DIR__ . '/inc/model_fixture.php';

$dir = sys_get_temp_dir() . '/tc-public-' . bin2hex(random_bytes(6));
mkdir($dir);
putenv('TC_DSN=sqlite:' . $dir . '/state.db');
putenv('TC_STATE=' . $dir);
putenv('TC_BASE_URL=http://127.0.0.1:18782');

$model = new Test_model('jobs');
(new Setup_model('setup', $model->db->connection()))->migrate();
file_put_contents($dir . '/key', Encryptor::generate_key());

$proc = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:18782', '-t', dirname(__DIR__) . '/public', dirname(__DIR__) . '/public/index.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $dir . '/server.log', 'w'], 2 => ['file', $dir . '/server.log', 'a']],
    $pipes
);

function fetch(string $path): array {
    $headers = [];
    $c = curl_init('http://127.0.0.1:18782' . $path);
    curl_setopt_array($c, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($line);
        },
    ]);
    $body = (string) curl_exec($c);
    $code = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);
    return [$code, $body, $headers];
}

try {
    $code = 0;
    $body = '';
    $headers = [];
    for ($i = 0; $i < 30; $i++) {
        usleep(100000);
        [$code, $body, $headers] = fetch('/');
        if ($code === 200) {
            break;
        }
    }

    if (
        $code !== 200
        || !str_contains($body, "Push your Trongate app.")
        || !str_contains($body, 'welcome/preview')
        || !str_contains($body, 'welcome_module/js/landing.js')
        || !str_contains($body, 'css/landing.css')
        || str_contains($body, 'Hetzner')
        || str_contains($body, 'Kubernetes')
        || str_contains($body, 'FrankenPHP')
        || str_contains($body, 'Warning:')
    ) {
        throw new RuntimeException('Landing page failed assertions: ' . file_get_contents($dir . '/server.log'));
    }
    if (!str_contains($headers['content-security-policy'] ?? '', "frame-ancestors 'none'")) {
        throw new RuntimeException('Landing page CSP missing frame-ancestors none');
    }

    // Hero preview iframe page
    [$code, $preview, $pHeaders] = fetch('/welcome/preview');
    if (
        $code !== 200
        || !str_contains($preview, 'Storefront')
        || !str_contains($preview, 'acme/storefront')
        || !str_contains($preview, 'tc_storefront')
        || !str_contains($pHeaders['content-security-policy'] ?? '', "frame-ancestors 'self'")
    ) {
        throw new RuntimeException('Welcome preview page failed assertions');
    }

    // Static assets and module JS
    foreach (['/css/landing.css', '/welcome_module/js/landing.js', '/images/news/2026-10-09/upgrade.webp'] as $asset) {
        [$code, $assetBody] = fetch($asset);
        if ($code !== 200 || strlen($assetBody) < 100) {
            throw new RuntimeException('Static/module asset failed: ' . $asset . ' (status ' . $code . ')');
        }
    }

    // News index and kind filters
    [$code, $news] = fetch('/news');
    if ($code !== 200 || !str_contains($news, 'Changelog, October 26') || !str_contains($news, 'Hello, Trongate community')) {
        throw new RuntimeException('News index missing expected posts');
    }
    [$code, $changelog] = fetch('/news/changelog');
    if ($code !== 200 || !str_contains($changelog, 'Changelog, October 26') || str_contains($changelog, 'Hello, Trongate community')) {
        throw new RuntimeException('News changelog filter failed');
    }
    [$code, $writing] = fetch('/news/writing');
    if ($code !== 200 || !str_contains($writing, 'Hello, Trongate community') || str_contains($writing, 'Changelog, October 26')) {
        throw new RuntimeException('News writing filter failed');
    }

    // Single news post with TOC and sized image
    [$code, $post] = fetch('/news/changelog-october-9');
    if (
        $code !== 200
        || !str_contains($post, 'In this post')
        || !str_contains($post, 'news/changelog-october-9#mail-tab')
        || !str_contains($post, '/images/news/2026-10-09/upgrade.webp')
    ) {
        throw new RuntimeException('News post view failed');
    }

    // Merged news slug 301 redirect
    [$code, , $rHeaders] = fetch('/news/safe-rollouts');
    if ($code !== 301 || ($rHeaders['location'] ?? '') !== 'http://127.0.0.1:18782/news/changelog-october-9#deploys-that-undo-themselves') {
        throw new RuntimeException('Merged news slug redirect failed: ' . $code . ' ' . ($rHeaders['location'] ?? ''));
    }

    // Missing news slug -> 404
    [$code, $missing] = fetch('/news/does-not-exist');
    if ($code !== 404 || !str_contains($missing, 'Page not found')) {
        throw new RuntimeException('Missing news post did not return styled 404');
    }

    // News Atom feed
    [$code, $feed, $fHeaders] = fetch('/news/feed');
    if (
        $code !== 200
        || !str_contains($fHeaders['content-type'] ?? '', 'application/atom+xml')
        || !str_contains($feed, '<title>trongate.cloud news</title>')
        || simplexml_load_string($feed) === false
    ) {
        throw new RuntimeException('News Atom feed invalid');
    }

    // Blog index, post, redirects, 404, and Atom feed
    [$code, $blog] = fetch('/blog');
    if (
        $code !== 200
        || !str_contains($blog, 'Simplicity and efficiency')
        || !str_contains($blog, 'Hello, Trongate community')
        || !str_contains($blog, 'Two critical PHP bugs, and what we did about them')
        || str_contains($blog, 'Changelog, October 26')
    ) {
        throw new RuntimeException('Blog index failed');
    }
    [$code, $simplicityPost] = fetch('/blog/simplicity-and-efficiency');
    if (
        $code !== 200
        || !str_contains($simplicityPost, '&larr; Blog')
        || !str_contains($simplicityPost, 'A complex solution is good until you find a simpler one!')
        || !str_contains($simplicityPost, 'https://www.zenwalk.org/')
    ) {
        throw new RuntimeException('Simplicity and efficiency blog post view failed');
    }
    [$code, $blogPost] = fetch('/blog/two-critical-php-bugs');
    if (
        $code !== 200
        || !str_contains($blogPost, '&larr; Blog')
        || !str_contains($blogPost, 'blog/two-critical-php-bugs#the-two-bugs')
    ) {
        throw new RuntimeException('Blog post view failed');
    }
    [$code, , $bHeaders] = fetch('/blog/changelog-october-9');
    if ($code !== 301 || ($bHeaders['location'] ?? '') !== 'http://127.0.0.1:18782/news/changelog-october-9') {
        throw new RuntimeException('Blog changelog redirect failed');
    }
    [$code, , $bmHeaders] = fetch('/blog/safe-rollouts');
    if ($code !== 301 || ($bmHeaders['location'] ?? '') !== 'http://127.0.0.1:18782/news/changelog-october-9#deploys-that-undo-themselves') {
        throw new RuntimeException('Blog merged slug redirect failed');
    }
    [$code] = fetch('/blog/does-not-exist');
    if ($code !== 404) {
        throw new RuntimeException('Missing blog post did not return 404');
    }
    [$code, $blogFeed, $bfHeaders] = fetch('/blog/feed');
    if (
        $code !== 200
        || !str_contains($bfHeaders['content-type'] ?? '', 'application/atom+xml')
        || !str_contains($blogFeed, '<title>trongate.cloud blog</title>')
        || str_contains($blogFeed, 'Changelog, October 26')
        || simplexml_load_string($blogFeed) === false
    ) {
        throw new RuntimeException('Blog Atom feed invalid');
    }

    // Shortcut routes and blocked colocated tests
    [$code, $loginPage] = fetch('/login');
    if ($code !== 200 || !str_contains($loginPage, 'Welcome back.')) {
        throw new RuntimeException('/login shortcut failed');
    }
    [$code] = fetch('/dashboard');
    if ($code !== 302) {
        throw new RuntimeException('/dashboard shortcut bypassed auth');
    }
    [$code] = fetch('/register');
    if ($code !== 302) {
        throw new RuntimeException('/register shortcut bypassed auth');
    }
    [$code] = fetch('/news_module/tests/news.phpt');
    if (!in_array($code, [403, 404], true)) {
        throw new RuntimeException('Colocated news test exposed');
    }

    echo "Public landing, news, and blog HTTP checks passed\n";
} finally {
    proc_terminate($proc);
    fclose($pipes[0]);
    proc_close($proc);
    unset($model);
    foreach (glob($dir . '/*') ?: [] as $f) {
        unlink($f);
    }
    rmdir($dir);
}
?>
--EXPECT--
Public landing, news, and blog HTTP checks passed
