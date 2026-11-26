(function () {
    function applyPreset(button) {
        var group = button.closest('[data-perm-group]');
        if (!group) return;
        var raw = button.getAttribute('data-perm-preset') || '';
        var wanted = raw ? raw.split(',').filter(Boolean) : [];
        var boxes = group.querySelectorAll('input[type="checkbox"][data-perm-key]');
        boxes.forEach(function (box) {
            box.checked = wanted.indexOf(box.getAttribute('data-perm-key')) !== -1;
        });
        syncPresetHighlights(group);
    }

    function syncDependencies(box) {
        var group = box.closest('[data-perm-group]');
        if (!group) return;
        var key = box.getAttribute('data-perm-key');
        var byKey = {};
        group.querySelectorAll('input[type="checkbox"][data-perm-key]').forEach(function (b) {
            byKey[b.getAttribute('data-perm-key')] = b;
        });
        if (box.checked) {
            if (byKey.view) byKey.view.checked = true;
            if (key === 'deploy' && byKey.deployments) byKey.deployments.checked = true;
            if (key === 'database_write' && byKey.database_read) byKey.database_read.checked = true;
            if (key === 'services_manage' && byKey.services_view) byKey.services_view.checked = true;
        } else {
            if (key === 'view') {
                Object.keys(byKey).forEach(function (k) { byKey[k].checked = false; });
            }
            if (key === 'deployments' && byKey.deploy) byKey.deploy.checked = false;
            if (key === 'database_read' && byKey.database_write) byKey.database_write.checked = false;
            if (key === 'services_view' && byKey.services_manage) byKey.services_manage.checked = false;
        }
        syncPresetHighlights(group);
    }

    function syncPresetHighlights(group) {
        var checked = [];
        group.querySelectorAll('input[type="checkbox"][data-perm-key]').forEach(function (b) {
            if (b.checked) checked.push(b.getAttribute('data-perm-key'));
        });
        checked.sort();
        var sig = checked.join(',');
        group.querySelectorAll('[data-perm-preset]').forEach(function (btn) {
            var preset = (btn.getAttribute('data-perm-preset') || '').split(',').filter(Boolean).sort().join(',');
            btn.classList.toggle('is-active', preset !== '' && preset === sig);
        });
    }

    function syncAccessMode(form) {
        var modeInput = form.querySelector('input[name="access_mode"]:checked');
        if (!modeInput) return;
        var mode = modeInput.value;
        form.querySelectorAll('[data-access-section]').forEach(function (sec) {
            var allowed = (sec.getAttribute('data-access-section') || '').split(',');
            sec.hidden = allowed.indexOf(mode) === -1;
        });
    }

    document.addEventListener('click', function (ev) {
        var presetBtn = ev.target.closest('[data-perm-preset]');
        if (presetBtn) {
            ev.preventDefault();
            applyPreset(presetBtn);
            return;
        }
        var copyBtn = ev.target.closest('[data-copy-target]');
        if (copyBtn) {
            ev.preventDefault();
            var id = copyBtn.getAttribute('data-copy-target');
            var input = id ? document.getElementById(id) : null;
            if (!input) return;
            var text = input.value || input.textContent || '';
            var finish = function () {
                var prev = copyBtn.textContent;
                copyBtn.textContent = 'Copied';
                setTimeout(function () { copyBtn.textContent = prev; }, 1800);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(finish, function () {
                    input.select();
                    document.execCommand('copy');
                    finish();
                });
            } else {
                input.select();
                document.execCommand('copy');
                finish();
            }
        }
    });

    document.addEventListener('change', function (ev) {
        var target = ev.target;
        if (target.matches('input[type="checkbox"][data-perm-key]')) {
            syncDependencies(target);
        }
        if (target.matches('input[name="access_mode"]')) {
            var form = target.closest('form');
            if (form) syncAccessMode(form);
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-perm-group]').forEach(syncPresetHighlights);
        document.querySelectorAll('form[data-access-form]').forEach(syncAccessMode);
    });
})();
