<!DOCTYPE html>
<html lang="en">

<head>
    <?php
    require_once APPPATH . 'modules/news/services/Markdown.php';

    $page_title = $post->title . ' - Blog - trongate.cloud';
    $page_description = $post->summary;
    $feed_href = 'blog/feed';
    $feed_title = 'trongate.cloud blog';
    require APPPATH . 'modules/templates/views/_site_head.php';
    ?>
</head>

<body class="lp">
    <?php require APPPATH . 'modules/templates/views/_site_header.php'; ?>

    <main class="lp-wrap lp-news">
        <article class="lp-post">
            <a href="blog" class="lp-post-back">&larr; Blog</a>
            <div class="lp-news-meta">
                <time datetime="<?= $post->date ?>"><?= date('F j, Y', (int) strtotime($post->date)) ?></time>
                <span class="lp-news-kind lp-news-kind-<?= $post->kind ?>"><?= $post->kind_label ?></span>
            </div>
            <h1 class="lp-h2"><?= out($post->title) ?></h1>
            <?php if ($post->summary !== ''): ?>
                <p class="lp-sub"><?= out($post->summary) ?></p>
            <?php endif; ?>
            <?php if (count($post->sections) >= 3): ?>
                <nav class="lp-toc" aria-label="In this post">
                    <p class="lp-toc-title">In this post</p>
                    <ol>
                        <?php foreach ($post->sections as $id => $heading): ?>
                            <li><a href="blog/<?= $post->slug ?>#<?= $id ?>"><?= Markdown::inline($heading) ?></a></li>
                        <?php endforeach; ?>
                    </ol>
                </nav>
            <?php endif; ?>
            <div class="lp-prose">
                <?= $post->html ?>
            </div>
            <div class="lp-post-foot">
                <a href="apps/create" class="lp-btn">Deploy your Trongate app</a>
                <a href="blog" class="lp-more">More posts <span aria-hidden="true">&rarr;</span></a>
            </div>
        </article>
    </main>

    <?php require APPPATH . 'modules/templates/views/_site_footer.php'; ?>
</body>

</html>
