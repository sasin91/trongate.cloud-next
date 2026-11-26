<fieldset class="release-picker"><legend>Choose a release</legend>
<?php
usort($releases, fn($a, $b) => ($b['created'] ?? 0) <=> ($a['created'] ?? 0));
foreach ($releases as $r):
    $meta = $r['metadata'] ?? [];
    $spec = $r['spec'] ?? [];
    $sha = (string) ($meta['commit'] ?? $spec['commit'] ?? '');
    $message = trim((string) ($meta['message'] ?? ''));
    $title = $meta['pull_request']['title'] ?? (strtok($message, "\n") ?: ($sha !== '' ? 'Commit ' . substr($sha, 0, 7) : 'Release'));
    $repository = (string) ($meta['repository'] ?? '');
    $github = preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~D', $repository) ? 'https://github.com/' . $repository : '';
    $php_version = (string) ($spec['php_version'] ?? '8.4');
    $extensions = is_array($spec['extensions'] ?? null) ? $spec['extensions'] : [];
    $disk_quota_mb = (int) ($spec['disk_quota_mb'] ?? 1024);
    $artifact = (string) ($spec['artifact'] ?? '');
    $release_path = (string) ($spec['release_path'] ?? '');
?>
    <section class="release-choice">
        <label class="release-label">
            <input type="radio" name="release" value="<?= $e($r['id']) ?>" required>
            <span>
                <strong><?= $e($title) ?></strong>
                <small>
                    <?= $e($meta['branch'] ?? '') ?><?= !empty($meta['branch']) ? ' · ' : '' ?><?= $e($sha !== '' ? substr($sha, 0, 7) : substr($r['id'], 0, 7)) ?>
                    · PHP <?= $e($php_version) ?>
                </small>
                <small>
                    <?php if (!empty($r['created'])): ?>
                        Built <time datetime="<?= $e(gmdate('c', (int) $r['created'])) ?>"><?= $e(gmdate('d M Y · H:i', (int) $r['created'])) ?> UTC</time>
                    <?php else: ?>
                        Build time unavailable
                    <?php endif ?>
                </small>
            </span>
        </label>
        <details class="release-details"><summary>Release details</summary>
            <?php if ($github !== '' && preg_match('/^[a-f0-9]{40}$/D', $sha)): ?>
                <p>
                    <a href="<?= $e($github . '/commit/' . $sha) ?>" target="_blank" rel="noopener">View commit ↗</a>
                    <?php if (!empty($meta['pull_request']['number'])): ?>
                        · <a href="<?= $e($github . '/pull/' . (int) $meta['pull_request']['number']) ?>" target="_blank" rel="noopener">PR #<?= (int) $meta['pull_request']['number'] ?> ↗</a>
                    <?php endif ?>
                </p>
            <?php endif ?>
            <?php if (!empty($meta['author'])): ?>
                <p><?= $e($meta['author']) ?><?= !empty($meta['committed_at']) ? ' · ' . $e($meta['committed_at']) : '' ?></p>
            <?php endif ?>
            <?php if ($message !== ''): ?>
                <p class="release-message"><?= $e($message) ?></p>
            <?php endif ?>
            <p>
                Runtime: PHP <?= $e($php_version) ?>
                <?php if ($extensions !== []): ?>
                    · Extensions: <code><?= $e(implode(', ', $extensions)) ?></code>
                <?php endif ?>
            </p>
            <details><summary>Diagnostics</summary>
                <?php if ($artifact !== ''): ?><p>Artifact <code><?= $e($artifact) ?></code></p><?php endif ?>
                <?php if ($release_path !== ''): ?><p>Path <code><?= $e($release_path) ?></code></p><?php endif ?>
                <p>Release <?= $e($r['id']) ?></p>
            </details>
        </details>
    </section>
<?php endforeach ?>
<?php if (!$releases): ?><p>No releases built yet.</p><?php endif ?>
</fieldset>
