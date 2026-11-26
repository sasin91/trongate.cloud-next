<?php
$date = static fn(string $ymd): string => date('F j, Y', (int) strtotime($ymd));
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php
    $page_title = 'Blog - trongate.cloud';
    $page_description = 'Longer writing about building and running Trongate PHP applications.';
    $feed_href = 'blog/feed';
    $feed_title = 'trongate.cloud blog';
    require APPPATH . 'modules/templates/views/_site_head.php';
    ?>
</head>

<body class="lp">
    <?php require APPPATH . 'modules/templates/views/_site_header.php'; ?>

    <main class="lp-wrap lp-news">
        <div class="lp-news-head">
            <span class="lp-kicker">Blog</span>
            <h1 class="lp-h2">Writing from trongate.cloud.</h1>
            <p class="lp-sub">Longer notes on running Trongate apps, security, and platform engineering.</p>
        </div>

        <div class="lp-news-grid">
            <div>
                <?php if (empty($posts)): ?>
                    <p class="lp-news-empty">Nothing here yet.</p>
                <?php else: ?>
                    <ol class="lp-news-list">
                        <?php foreach ($posts as $post): ?>
                            <li>
                                <div class="lp-news-meta">
                                    <time datetime="<?= $post->date ?>"><?= $date($post->date) ?></time>
                                    <span class="lp-news-kind lp-news-kind-<?= $post->kind ?>"><?= $post->kind_label ?></span>
                                </div>
                                <h2><a href="blog/<?= $post->slug ?>"><?= out($post->title) ?></a></h2>
                                <?php if ($post->summary !== ''): ?>
                                    <p><?= out($post->summary) ?></p>
                                <?php endif; ?>
                                <a href="blog/<?= $post->slug ?>" class="lp-link">Read post &rarr;</a>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </div>

            <aside class="lp-news-aside">
                <div>
                    <strong>Follow along</strong>
                    <p>New blog posts show up in any feed reader.</p>
                    <a href="blog/feed" class="lp-link">Blog Atom feed &rarr;</a>
                    <a href="news/changelog" class="lp-link">Product changelog &rarr;</a>
                </div>
                <div>
                    <strong>From the Trongate project</strong>
                    <p>Framework news comes from the people who make it.</p>
                    <a href="https://trongate.io/essays" target="_blank" rel="noopener" class="lp-link">Trongate essays &rarr;</a>
                    <a href="https://github.com/trongate/trongate-framework/releases" target="_blank" rel="noopener" class="lp-link">Framework releases &rarr;</a>
                </div>
            </aside>
        </div>
    </main>

    <?php require APPPATH . 'modules/templates/views/_site_footer.php'; ?>
</body>

</html>
