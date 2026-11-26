<?php
// The public site's <head> contents (landing page, blog, and news).
// Expects $page_title and $page_description; optional $feed_href and $feed_title.
$feed_href = $feed_href ?? 'news/feed';
$feed_title = $feed_title ?? 'trongate.cloud news';
?>
<base href="<?= BASE_URL ?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= out($page_title) ?></title>
    <meta name="description" content="<?= out($page_description) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wght@800;900&family=Geist:wght@400;500;600&family=Geist+Mono:wght@400;500&display=swap">
    <link rel="stylesheet" href="<?= BASE_URL ?>css/landing.css">
    <link rel="alternate" type="application/atom+xml" title="<?= out($feed_title) ?>" href="<?= BASE_URL . $feed_href ?>">
