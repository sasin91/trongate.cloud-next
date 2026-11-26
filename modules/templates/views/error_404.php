<!DOCTYPE html>
<html lang="en">

<head>
    <?php
    $page_title = 'Page not found - trongate.cloud';
    $page_description = 'The page you are looking for does not exist or has been moved.';
    require __DIR__ . '/_site_head.php';
    ?>
</head>

<body class="lp">
    <?php require __DIR__ . '/_site_header.php'; ?>

    <main class="lp-wrap lp-news">
        <div class="lp-news-head">
            <span class="lp-kicker">404</span>
            <h1 class="lp-h2">Page not found.</h1>
            <p class="lp-sub">Sorry, the page you're looking for doesn't exist or has been moved.</p>
        </div>
        <div class="lp-post-foot">
            <a href="<?= BASE_URL ?>" class="lp-btn">Go to homepage</a>
            <a href="news" class="lp-more">Read the news <span aria-hidden="true">&rarr;</span></a>
        </div>
    </main>

    <?php require __DIR__ . '/_site_footer.php'; ?>
</body>

</html>
