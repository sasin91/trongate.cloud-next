--TEST--
News Markdown renderer, image sizing, post parsing, filtering, and merged-post redirects
--FILE--
<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/bootstrap.php';

define('ENV', 'prod');

function assert_same(mixed $expected, mixed $actual, string $label): void {
    if ($expected !== $actual) {
        throw new RuntimeException(
            $label . "\nExpected: " . var_export($expected, true) . "\nActual:   " . var_export($actual, true)
        );
    }
}

// 1. Headings, paragraphs, and lists
assert_same(
    "<h2 id=\"what-changed\">What changed</h2>\n<p>One line and another.</p>\n<ul><li>a <strong>b</strong></li><li>c <em>d</em> continued</li></ul>\n<ol><li>first</li><li>second</li></ol>",
    Markdown::render("## What changed\n\nOne line\nand another.\n\n- a **b**\n- c *d*\n  continued\n\n1. first\n2. second\n"),
    'Markdown headings, paragraphs, and lists'
);

// 2. Code blocks and inline code
assert_same(
    "<pre><code>\$a = &#039;&lt;b&gt;&#039;; // **not bold**</code></pre>",
    Markdown::render("```php\n\$a = '<b>'; // **not bold**\n```"),
    'Markdown code fence'
);
assert_same(
    '<p>Use <code>getenv(&#039;*X*&#039;)</code> here</p>',
    Markdown::render("Use `getenv('*X*')` here"),
    'Markdown inline code'
);

// 3. HTML escaping and unsafe link stripping
assert_same('<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>', Markdown::render('<script>alert(1)</script>'), 'Escape raw HTML');
assert_same('<p>click</p>', Markdown::render('[click](javascript:alert)'), 'Drop javascript: link');
assert_same(
    '<p><a href="/register">Start</a> or <a href="https://trongate.io/?a=1&amp;b=2" target="_blank" rel="noopener">read</a></p>',
    Markdown::render('[Start](/register) or [read](https://trongate.io/?a=1&b=2)'),
    'Safe relative and https links'
);
assert_same(
    '<p><a href="https://x.test/&quot;onmouseover=&quot;" target="_blank" rel="noopener">x</a></p>',
    Markdown::render('[x](https://x.test/"onmouseover=")'),
    'Escape quotes in href'
);

// 4. Blockquotes
assert_same('<blockquote><p>Said once.</p></blockquote>', Markdown::render("> Said\n> once."), 'Blockquotes');

// 5. Images sized from public/
assert_same(
    '<figure class="lp-figure lp-figure-tall"><a href="/images/news/2026-10-09/tabs-phone.webp" target="_blank" rel="noopener"><img src="/images/news/2026-10-09/tabs-phone.webp" alt="Tabs &quot;on&quot; a phone" width="780" height="900" loading="lazy"></a></figure>',
    Markdown::render('![Tabs "on" a phone](/images/news/2026-10-09/tabs-phone.webp)'),
    'Tall image sized from file'
);
assert_same(
    '<figure class="lp-figure"><a href="/missing.png" target="_blank" rel="noopener"><img src="/missing.png" alt="" loading="lazy"></a></figure>',
    Markdown::render('![](/missing.png)'),
    'Missing image falls back without dimensions'
);
assert_same(
    '<p>!<a href="https://evil.test/a.png" target="_blank" rel="noopener">x</a></p>',
    Markdown::render('![x](https://evil.test/a.png)'),
    'External image is not rendered as figure'
);
assert_same('<p>!x</p>', Markdown::render('![x](//evil.test/a.png)'), 'Protocol-relative image is rejected');

// 6. Heading self-links and TOC extraction
assert_same(
    '<h2 id="mail-tab">Mail tab <a class="lp-anchor" href="news/today#mail-tab" aria-label="Link to this section">#</a></h2>',
    Markdown::render('## Mail tab', 'news/today'),
    'Self-linking heading'
);
assert_same(
    ['a-database-tab' => 'A *Database* tab', 'more' => 'More'],
    Markdown::headings("## A *Database* tab\n\n### Not this\n\n```\n## Not code\n```\n## More"),
    'TOC headings skip h3 and fenced blocks'
);

// 7. Every image referenced by a post exists in public/
foreach (glob(Posts::DIRECTORY . '/*.md') ?: [] as $path) {
    preg_match_all('#^!\[[^\]]*\]\((/[^)\s]+)\)$#m', (string) file_get_contents($path), $images);
    foreach ($images[1] as $src) {
        if (!is_file(APPPATH . 'public' . $src)) {
            throw new RuntimeException(basename($path) . " shows {$src}, which is not in public/");
        }
    }
}

// 8. Every post file parses with known kind and summary
$files = glob(Posts::DIRECTORY . '/*.md') ?: [];
$posts = Posts::all();
assert_same(
    count($files),
    count($posts) + count(array_filter($files, static fn(string $f): bool => str_contains((string) file_get_contents($f), "\ndraft: true\n"))),
    'All non-draft post files parse'
);
foreach ($posts as $post) {
    assert_same(true, $post->summary !== '', 'Post has summary: ' . $post->slug);
    assert_same(true, Posts::find($post->slug)?->html !== '', 'Post renders HTML: ' . $post->slug);
    assert_same(false, in_array($post->slug, ['feed', 'index', 'show', ...array_keys(Posts::KINDS)], true), 'No route collision: ' . $post->slug);
}

// 9. Newest-first sorting and filtering by kind
$dates = array_column($posts, 'date');
$sorted = $dates;
rsort($sorted);
assert_same($sorted, $dates, 'Posts sorted newest first');
assert_same(['changelog'], array_values(array_unique(array_column(Posts::all('changelog'), 'kind'))), 'Changelog filter');
assert_same(['writing'], array_values(array_unique(array_column(Posts::all('writing'), 'kind'))), 'Writing filter');

// 10. find() rejects invalid or traversal slugs and respects custom base_path
assert_same(null, Posts::find('../../bootstrap'), 'Reject traversal slug');
assert_same(null, Posts::find(''), 'Reject empty slug');
$blog_post = Posts::find('hello-trongate-community', 'blog');
assert_same(true, $blog_post !== null && str_contains($blog_post->html, 'href="blog/hello-trongate-community#what-it-is"'), 'Blog heading anchor prefix');

// 11. Merged posts lead to existing sections
foreach ($posts as $post) {
    $html = Markdown::render($post->body);
    foreach ($post->replaces as $old => $section) {
        assert_same(null, Posts::find($old), 'Merged slug is not a live post: ' . $old);
        assert_same(
            'news/' . $post->slug . ($section !== '' ? '#' . $section : ''),
            Posts::moved($old),
            'Merged slug resolves to replacement section: ' . $old
        );
        if ($section !== '') {
            assert_same(true, str_contains($html, ' id="' . $section . '"'), 'Target section exists: ' . $section);
        }
    }
}
assert_same(null, Posts::moved('never-existed'), 'Unknown slug returns null');

echo "News Markdown and Posts checks passed\n";
?>
--EXPECT--
News Markdown and Posts checks passed
