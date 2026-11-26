// One-question-at-a-time wizard with Trongate MX per-step validation, repo prefill, and live summary.
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('form[data-confirm]').forEach(form => form.addEventListener('submit', event => {
        if (!window.confirm(form.dataset.confirm)) event.preventDefault();
    }));

    const form = document.querySelector('form[data-steps]');
    if (!form) {
        return;
    }

    const steps = [...form.querySelectorAll('.wz-step')];
    const next = form.querySelector('[data-next]');
    const back = form.querySelector('[data-back]');
    const stepInput = form.querySelector('input[name="wizard_step"]');
    const appsDomain = form.dataset.appsDomain || 'trongate.dev';
    const detectedHint = form.querySelector('[data-build-detected]');
    let current = Number(form.dataset.step) || 0;
    let saveTimer;
    let saveQueue = Promise.resolve();

    const edited = {
        branch: false,
        label: Boolean((form.elements.label?.value || '').trim()),
        build_commands: Boolean((form.elements.build_commands?.value || '').trim()),
        php_extensions: Boolean((form.elements.php_extensions?.value || '').trim()),
    };

    const status = document.createElement('span');
    status.className = 'draft-save';
    status.tabIndex = 0;
    status.setAttribute('role', 'status');

    const toolbar = document.createElement('div');
    toolbar.className = 'draft-toolbar';
    back.before(toolbar);
    toolbar.append(back, status);

    const saveStatus = (text, error = false) => {
        status.textContent = error ? '⚠ ' + text : '💾';
        status.title = text;
        status.setAttribute('aria-label', text);
    };

    const setStepLabel = () => {
        next.disabled = false;
        next.textContent = current === steps.length - 1 ? 'Launch your app' : 'OK';
    };

    const save = () => {
        clearTimeout(saveTimer);
        if (stepInput) {
            stepInput.value = String(current);
        }
        const data = new FormData(form);
        data.set('wizard_step', String(current));
        saveQueue = saveQueue.catch(() => {}).then(async () => {
            saveStatus('Saving setup…');
            const response = await fetch('/apps/draft', {method: 'POST', body: data, credentials: 'same-origin'});
            if (response.redirected) {
                throw new Error('Your session expired. Sign in again to continue your last saved setup.');
            }
            const result = await response.json();
            if (!response.ok || !result.saved) {
                throw new Error(result.error || 'Could not save setup.');
            }
            saveStatus('Setup saved to your account.');
        });
        return saveQueue;
    };

    const scheduleSave = () => {
        clearTimeout(saveTimer);
        saveTimer = setTimeout(() => save().catch(error => saveStatus(error.message, true)), 500);
    };

    form.noValidate = true;
    form.classList.add('wz-js');

    const summary = () => {
        const label = (form.elements.label?.value || '').trim();
        const slugPreview = form.querySelector('[data-address-slug]');
        if (slugPreview) {
            slugPreview.textContent = label || 'your-app';
        }
        document.querySelectorAll('[data-show]').forEach(cell => {
            const name = cell.dataset.show;
            if (name === 'address') {
                cell.textContent = label ? `${label}.${appsDomain}` : '';
                return;
            }
            const checkbox = form.querySelector(`input[type="checkbox"][name="${name}"]`);
            if (checkbox) {
                cell.textContent = checkbox.checked ? 'On' : 'Off';
                return;
            }
            const field = form.elements[name];
            cell.textContent = field?.value || '';
        });
    };

    const show = index => {
        current = Math.max(0, Math.min(index, steps.length - 1));
        if (stepInput) {
            stepInput.value = String(current);
        }
        steps.forEach(step => step.classList.toggle('is-active', step === steps[current]));
        const counter = document.getElementById('wz-counter');
        if (counter) {
            counter.textContent = `Question ${current + 1} of ${steps.length}`;
        }
        const progress = document.getElementById('wz-progress');
        if (progress) {
            progress.replaceChildren(...steps.map((step, i) => {
                const node = document.createElement('span');
                node.className = i < current ? 'done' : i === current ? 'active' : '';
                const num = step.querySelector('.wz-num');
                if (num) {
                    num.textContent = `${i + 1} →`;
                }
                return node;
            }));
        }
        back.hidden = current === 0;
        setStepLabel();
        steps[current].querySelector('input:not([type=hidden]):not(:disabled),select:not(:disabled),textarea')?.focus({preventScroll: true});
    };

    window.wizardBeforeValidate = () => {
        if (stepInput) {
            stepInput.value = String(current);
        }
        next.disabled = true;
        next.classList.add('mx-temp-disabled');
        next.textContent = 'Checking…';
    };

    window.wizardAfterValidation = () => {
        const firstError = form.querySelector('.form-field-validation-error');
        if (firstError) {
            const errorStep = firstError.closest('.wz-step');
            const idx = steps.indexOf(errorStep);
            if (idx !== -1 && idx !== current) {
                show(idx);
            }
            firstError.focus({preventScroll: true});
        }
        setStepLabel();
    };

    window.wizardValidateTimeout = () => {
        saveStatus('Validation timed out. Check connectivity and try again.', true);
        setStepLabel();
    };

    window.wizardStepValidated = () => {
        const state = document.getElementById('wz-step-state');
        if (!state || state.dataset.ok !== '1') {
            setStepLabel();
            return;
        }

        if (state.dataset.repository && form.elements.repository) {
            form.elements.repository.value = state.dataset.repository;
        }
        if (state.dataset.branch && form.elements.branch) {
            if (!edited.branch || state.dataset.branchExplicit === '1') {
                form.elements.branch.value = state.dataset.branch;
            }
        }
        if (state.dataset.label && form.elements.label && !edited.label && !form.elements.label.value.trim()) {
            form.elements.label.value = state.dataset.label;
        }
        if (state.dataset.buildCommands && form.elements.build_commands && !edited.build_commands) {
            form.elements.build_commands.value = state.dataset.buildCommands;
        }
        if (state.dataset.phpExtensions && form.elements.php_extensions && !edited.php_extensions) {
            form.elements.php_extensions.value = state.dataset.phpExtensions;
        }
        if (detectedHint && state.dataset.buildFound) {
            detectedHint.textContent = `Prefilled from ${state.dataset.buildFound} (edit or clear if needed).`;
            detectedHint.hidden = false;
        }

        saveStatus('Setup saved to your account.');
        summary();

        if (state.dataset.launch === '1') {
            next.disabled = true;
            next.textContent = 'Launching…';
            form.submit();
            return;
        }

        const nextStep = Number(state.dataset.nextStep);
        show(Number.isFinite(nextStep) ? nextStep : current + 1);
    };

    // Re-enable wizard button if a generic MX error (e.g. 401/500) occurs.
    const prevRelease = window.resourceRelease;
    window.resourceRelease = function (...args) {
        setStepLabel();
        if (typeof prevRelease === 'function') {
            return prevRelease.apply(this, args);
        }
    };

    form.addEventListener('keydown', event => {
        if (event.key === 'Enter' && !['TEXTAREA', 'BUTTON'].includes(event.target.tagName)) {
            event.preventDefault();
            if (!next.disabled) {
                next.click();
            }
        }
    });

    back.addEventListener('click', () => {
        show(current - 1);
        scheduleSave();
    });

    const onFieldChange = event => {
        const name = event.target?.name;
        if (name && Object.prototype.hasOwnProperty.call(edited, name)) {
            edited[name] = true;
        }
        if (name === 'repository') {
            // Changing repository allows fresh defaults from the new repo.
            edited.branch = false;
            if (!form.elements.label?.value.trim()) {
                edited.label = false;
            }
            if (!form.elements.build_commands?.value.trim()) {
                edited.build_commands = false;
            }
            if (!form.elements.php_extensions?.value.trim()) {
                edited.php_extensions = false;
            }
            if (detectedHint) {
                detectedHint.hidden = true;
            }
        }
        summary();
        scheduleSave();
    };

    form.addEventListener('change', onFieldChange);
    form.addEventListener('input', onFieldChange);

    document.querySelectorAll('a[href^="/"]').forEach(link => link.addEventListener('click', async event => {
        if (event.ctrlKey || event.metaKey || event.shiftKey || link.target === '_blank') {
            return;
        }
        event.preventDefault();
        try {
            await save();
            window.location.assign(link.href);
        } catch (error) {
            window.alert(error.message);
        }
    }));

    document.querySelector('form[action="/login/logout"]')?.addEventListener('submit', async event => {
        event.preventDefault();
        try {
            await save();
            event.target.submit();
        } catch (error) {
            saveStatus(error.message, true);
        }
    });

    show(current);
    summary();
});
