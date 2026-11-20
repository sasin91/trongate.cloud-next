<fieldset class="release-picker"><legend>Choose a release</legend>
<?php
usort($releases, fn($a, $b) => ($b['created'] ?? 0) <=> ($a['created'] ?? 0));
foreach ($releases as $r):
    $meta = $r['metadata'] ?? [];
    $sha = $meta['commit'] ?? $r['spec']['commit'] ?? '';
    $message = trim($meta['message'] ?? '');
    $title = $meta['pull_request']['title'] ?? (strtok($message, "\n") ?: ($sha ? 'Commit ' . substr($sha, 0, 7) : 'Container release'));
    $repository = $meta['repository'] ?? '';
    $github = preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~D', $repository) ? 'https://github.com/' . $repository : '';
?>
    <section class="release-choice">
        <label class="release-label">
            <input type="radio" name="release" value="<?= $e($r['id']) ?>" required>
            <span><strong><?= $e($title) ?></strong>
            <small><?= $e($meta['branch'] ?? '') ?><?= !empty($meta['branch']) ? ' · ' : '' ?><?= $e($sha ? substr($sha, 0, 7) : substr($r['id'], 0, 7)) ?></small>
            <small><?php if (!empty($r['created'])): ?>Built <time datetime="<?= $e(gmdate('c', $r['created'])) ?>"><?= $e(gmdate('d M Y · H:i', $r['created'])) ?> UTC</time><?php else: ?>Build time unavailable<?php endif ?></small></span>
        </label>
        <details class="release-details"><summary>Release details</summary>
            <?php if ($github && preg_match('/^[a-f0-9]{40}$/D', $sha)): ?><p><a href="<?= $e($github . '/commit/' . $sha) ?>" target="_blank" rel="noopener">View commit ↗</a><?php if (!empty($meta['pull_request']['number'])): ?> · <a href="<?= $e($github . '/pull/' . (int) $meta['pull_request']['number']) ?>" target="_blank" rel="noopener">PR #<?= (int) $meta['pull_request']['number'] ?> ↗</a><?php endif ?></p><?php endif ?>
            <?php if (!empty($meta['author'])): ?><p><?= $e($meta['author']) ?><?= !empty($meta['committed_at']) ? ' · ' . $e($meta['committed_at']) : '' ?></p><?php endif ?>
            <?php if ($message): ?><p class="release-message"><?= $e($message) ?></p><?php endif ?>
            <details><summary>Diagnostics</summary><code><?= $e($r['spec']['image']) ?></code><p>Release <?= $e($r['id']) ?></p></details>
        </details>
    </section>
<?php endforeach ?>
<?php if (!$releases): ?><p>No releases built yet.</p><?php endif ?>
</fieldset>
