<main class="panel">
    <h1>Jobs</h1>
    <p>Builds, deployments and server provisioning continue in the background.</p>
    <?php if ($message !== null): ?><p role="status"><?= $e($message) ?></p><?php endif ?>
    <?php if (!$jobs): ?><p>No jobs yet.</p><?php endif ?>
    <?php foreach ($jobs as $job): ?>
    <section class="card">
        <h2><?= $e($job['kind']) ?></h2>
        <p><?= $e($job['resource']) ?> · <?= $e($job['status']) ?></p>
        <p><a href="<?= $job['kind'] === 'proxmox-provision' ? '/servers/proxmox' : '/deployments' ?>?operation=<?= $e($job['id']) ?>">View progress</a></p>
        <?php if ($job['status'] === 'uncertain'): ?>
        <p>Check the remote state before reconciling. The job keeps its resource lock until its outcome is verified.</p>
        <form method="post">
            <?= $csrf() ?>
            <input type="hidden" name="action" value="reconcile">
            <input type="hidden" name="job" value="<?= $e($job['id']) ?>">
            <button>Reconcile job</button>
        </form>
        <?php if (in_array($job['kind'], ['host-update', 'host-rollback'], true)): ?>
        <form method="post" action="/deployments/host">
            <?= $csrf() ?>
            <input type="hidden" name="action" value="host-reconcile">
            <input type="hidden" name="operation" value="<?= $e($job['id']) ?>">
            <label><input type="checkbox" name="confirm_workers_stopped" value="1" required> All installation workers have been stopped.</label>
            <button>Recover host update</button>
        </form>
        <?php endif ?>
        <?php endif ?>
    </section>
    <?php endforeach ?>
</main>
