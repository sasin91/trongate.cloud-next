<?php
// The public site's top bar (landing page, blog, and news). Anchors resolve against <base>, so they
// scroll on the landing page and lead back to it from elsewhere.
$first_segment = (string) segment(1);
?>
<header class="lp-top">
    <a href="<?= BASE_URL ?>" class="lp-brand">
        <?php require __DIR__ . '/_mark.php'; ?>
        <span>trongate<b>.cloud</b></span>
    </a>
    <nav class="lp-nav" aria-label="Main">
        <a href="#how">How it works</a>
        <a href="#stability">Stability</a>
        <a href="#features">Features</a>
        <a href="#faq">FAQ</a>
        <a href="blog"<?= $first_segment === 'blog' ? ' aria-current="page"' : '' ?>>Blog</a>
        <a href="news"<?= $first_segment === 'news' ? ' aria-current="page"' : '' ?>>News</a>
    </nav>
    <div class="lp-top-actions">
        <a href="login" class="lp-login">Log in</a>
        <a href="apps/create" class="lp-btn">Start free</a>
    </div>
</header>
