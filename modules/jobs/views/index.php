<main class="panel">
    <h1>Jobs</h1>
    <p>Builds, deployments, and application lifecycle jobs continue in the background.</p>
    <?php if ($message !== null): ?><p role="status"><?= $e($message) ?></p><?php endif ?>
    <?php if (!$jobs): ?><p>No jobs yet.</p><?php endif ?>
    <?php foreach ($jobs as $job): ?>
    <section class="card">
        <h2><?= $e($job['kind']) ?></h2>
        <p><?= $e($job['resource']) ?> · <?= $e($job['status']) ?></p>
        <p><a href="/deployments?operation=<?= $e($job['id']) ?>">View progress</a></p>
        <?php if ($job['status'] === 'uncertain'): ?>
        <p>Check the remote state before reconciling. The job keeps its resource lock until its outcome is verified.</p>
        <form method="post">
            <?= $csrf() ?>
            <input type="hidden" name="action" value="reconcile">
            <input type="hidden" name="job" value="<?= $e($job['id']) ?>">
            <button>Reconcile job</button>
        </form>
        <?php endif ?>
    </section>
    <?php endforeach ?>
</main>
