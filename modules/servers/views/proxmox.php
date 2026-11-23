<main class="panel server-setup">
    <p><a href="/servers">← Back to servers</a></p>
    <?php if ($proxmox_progress): ?>
    <?php
    $status = $proxmox_progress['status'];
    $phase = $proxmox_progress['phase'] ?? '';
    $description = match ($status) {
        'succeeded' => 'Your server is ready. Create an app or continue your saved app setup.',
        'failed' => 'Server creation failed. Review the operation details before creating another server.',
        'uncertain' => 'Server creation needs review. Check the operation details and the VM in Proxmox before retrying.',
        default => match ($phase) {
            'clone' => 'Creating your server from the prepared template.',
            'configure' => 'Preparing secure access to your server.',
            'start' => 'Starting your server.',
            'enroll' => 'Checking network access and application readiness.',
            default => 'Queued — waiting for server creation to begin.',
        },
    };
    ?>
    <section class="card">
        <h1><?= $e($proxmox_progress['name']) ?></h1>
        <p role="status"><?= $e($description) ?></p>
        <?php if ($status === 'succeeded'): ?>
        <p><a class="wz-ok" href="/apps/create"><?= isset($_SESSION['app_draft']) ? 'Continue app setup' : 'Create an app' ?></a> <a href="/servers">View servers</a></p>
        <?php else: ?>
        <p><a class="wz-ok" href="/servers/proxmox?operation=<?= $e(rawurlencode($proxmox_operation)) ?>">Refresh status</a></p>
        <p><a href="/jobs">Operation details</a></p>
        <?php endif ?>
    </section>
    <?php elseif ($proxmox_operation === ''): ?>
    <h1>Create a server on Proxmox</h1>
    <p>Create an application server in your configured Proxmox environment. It will appear in Servers once its application readiness checks pass.</p>
    <?php if (!$proxmox_connections): ?>
    <p>No Proxmox environment is configured for this installation yet.</p>
    <?php else: ?>
    <form method="post" action="/servers/proxmox" class="card">
        <?= $csrf() ?>
        <input type="hidden" name="request" value="<?= $e($_SESSION['proxmox_request']) ?>">
        <label class="wz-field"><span class="wz-label">Proxmox environment</span>
            <select name="connection" class="wz-select" required>
                <?php foreach ($proxmox_connections as $connection): ?>
                <option value="<?= $e($connection['id']) ?>" <?= ($_POST['connection'] ?? '') === $connection['id'] ? 'selected' : '' ?>><?= $e($connection['label'] ?? $connection['id']) ?><?php if (isset($connection['memory_mb'], $connection['cpus'])): ?> · <?= $e($connection['memory_mb']) ?> MB / <?= $e($connection['cpus']) ?> CPUs<?php endif ?></option>
                <?php endforeach ?>
            </select>
        </label>
        <label class="wz-field"><span class="wz-label">Server name</span><input name="name" required maxlength="40" pattern="[a-z][a-z0-9-]{0,39}" placeholder="web" value="<?= $e($_POST['name'] ?? '') ?>"></label>
        <label class="wz-field"><span class="wz-label">Unused VM ID</span><input type="number" name="vmid" required min="100" max="999999999" step="1" value="<?= $e($_POST['vmid'] ?? '') ?>"></label>
        <p>The server uses the template’s CPU, memory, disk allocation and network. A full clone consumes storage in the selected environment.</p>
        <label><input type="checkbox" name="confirm_capacity" value="1" required <?= ($_POST['confirm_capacity'] ?? '') === '1' ? 'checked' : '' ?>> Create this VM using the selected Proxmox environment’s capacity.</label>
        <p><button class="wz-ok">Create server</button></p>
    </form>
    <?php endif ?>
    <?php endif ?>
</main>
