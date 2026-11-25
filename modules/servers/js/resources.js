(() => {
    let drafts = [];
    let focus = null;
    let pending = false;
    let watchdog;

    function update(form) {
        const fields = [
            form.elements.memory_mb,
            form.elements.cpu_percent,
            form.elements.disk_quota_mb,
        ];
        const reading = document.getElementById(`readings-${form.elements.app.value}`);
        if (reading) {
            if (reading.dataset.maxMemory) {
                fields[0].max = reading.dataset.maxMemory;
            }
            if (reading.dataset.maxCpu) {
                fields[1].max = reading.dataset.maxCpu;
            }
            const maxDisk = Number(reading.dataset.maxDisk || 0);
            if (maxDisk > 0) {
                fields[2].max = String(maxDisk);
            } else {
                fields[2].removeAttribute('max');
            }
        }
        const automatic = form.elements.mode.value === 'automatic';
        fields.forEach(field => {
            field.disabled = automatic;
        });
        const invalid = !automatic && fields.some(field => !field.validity.valid);
        const feedback = form.querySelector('.resource-feedback');
        feedback.textContent = invalid ? 'Outside the available FreeBSD rctl or ZFS budget.' : '';
        feedback.classList.toggle('wz-errors', invalid);
        const diskCeiling = fields[2].max ? ` · ${fields[2].max} MiB ZFS` : '';
        form.querySelector('.resource-ceiling').textContent = `Up to ${fields[0].max} MiB RAM · ${fields[1].max}% CPU${diskCeiling}`;
    }

    window.resourceRefresh = () => document.querySelectorAll('.resource-form').forEach(update);

    window.resourceRelease = () => {
        pending = false;
        clearTimeout(watchdog);
        document.querySelectorAll('.resource-form button').forEach(button => {
            button.disabled = false;
            button.textContent = 'Save allocation';
        });
        const poll = document.getElementById('resource-poll');
        if (poll) {
            TrongateMX.startPolling(poll, '15s');
        }
    };

    window.resourceBeforeSave = event => {
        if (pending) {
            throw new Error('An allocation is already being saved.');
        }
        pending = true;
        const form = event.target.closest('form');
        drafts = Array.from(document.querySelectorAll('.resource-form'))
            .filter(other => other !== form && other.dataset.dirty)
            .map(other => [other.elements.app.value, Object.fromEntries(new FormData(other))]);
        focus = {app: form.elements.app.value, name: document.activeElement?.name};
        const poll = document.getElementById('resource-poll');
        if (poll) {
            TrongateMX.stopPolling(poll);
        }
        form.querySelector('button').textContent = 'Saving…';
        watchdog = setTimeout(window.panelMxTimeout, 65000);
    };

    window.resourceAfterSave = () => {
        document.getElementById('mx-feedback').textContent = '';
        for (const [app, values] of drafts) {
            const form = Array.from(document.querySelectorAll('.resource-form')).find(item => item.elements.app.value === app);
            if (!form) {
                continue;
            }
            for (const name of ['mode', 'memory_mb', 'cpu_percent', 'disk_quota_mb']) {
                if (values[name] !== undefined && form.elements[name]) {
                    form.elements[name].value = values[name];
                }
            }
            form.dataset.dirty = 'true';
        }
        resourceRefresh();
        resourceRelease();
        const form = Array.from(document.querySelectorAll('.resource-form')).find(item => item.elements.app.value === focus?.app);
        (form?.elements[focus?.name] || form?.querySelector('button'))?.focus({preventScroll: true});
    };

    for (const name of ['input', 'change']) {
        document.addEventListener(name, event => {
            const form = event.target.closest('.resource-form');
            if (form) {
                form.dataset.dirty = 'true';
                update(form);
            }
        });
    }

    document.addEventListener('DOMContentLoaded', resourceRefresh);
})();
