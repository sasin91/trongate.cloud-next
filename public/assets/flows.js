// Adapted from the original app_steps.js: one question, keyboard navigation, live summary.
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('form[data-confirm]').forEach(form => form.addEventListener('submit', event => {
        if (!window.confirm(form.dataset.confirm)) event.preventDefault();
    }));
    const form = document.querySelector('form[data-steps]');
    if (form) {
        const all = [...form.querySelectorAll('.wz-step')];
        const next = form.querySelector('[data-next]');
        const back = form.querySelector('[data-back]');
        let current = 0;
        let steps = all;
        form.noValidate = true;
        form.classList.add('wz-js');
        const show = index => {
            current = Math.max(0, Math.min(index, steps.length - 1));
            all.forEach(step => step.classList.toggle('is-active', step === steps[current]));
            document.getElementById('wz-counter').textContent = `Question ${current + 1} of ${steps.length}`;
            const progress = document.getElementById('wz-progress');
            progress.replaceChildren(...steps.map((step, i) => {
                const node = document.createElement('span');
                node.className = i < current ? 'done' : i === current ? 'active' : '';
                step.querySelector('.wz-num').textContent = `${i + 1} →`;
                return node;
            }));
            back.hidden = current === 0;
            next.textContent = current === steps.length - 1 ? 'Launch your app' : 'OK';
            steps[current].querySelector('input:not([type=hidden]):not(:disabled),select:not(:disabled),textarea')?.focus({preventScroll: true});
        };
        const applySource = () => {
            const source = form.elements.source.value;
            form.querySelectorAll('[data-source]').forEach(group => {
                group.hidden = group.dataset.source !== source;
                group.querySelectorAll('input,select,textarea').forEach(field => field.disabled = group.hidden);
            });
            steps = all.filter(step => !step.hidden);
            show(current);
        };
        const summary = () => document.querySelectorAll('[data-show]').forEach(cell => {
            const field = form.elements[cell.dataset.show];
            cell.textContent = field?.value || '';
        });
        const valid = step => {
            for (const field of step.querySelectorAll('input,select,textarea')) {
                if (!field.disabled && !field.checkValidity()) {
                    show(steps.indexOf(step)); field.reportValidity(); return false;
                }
            }
            return true;
        };
        form.addEventListener('submit', event => {
            if (current < steps.length - 1) {
                event.preventDefault();
                if (valid(steps[current])) show(current + 1);
                return;
            }
            if (!steps.every(valid)) { event.preventDefault(); return; }
            next.disabled = true; next.textContent = 'Launching…';
        });
        form.addEventListener('keydown', event => {
            if (event.key === 'Enter' && !['TEXTAREA','BUTTON'].includes(event.target.tagName)) {
                event.preventDefault(); form.requestSubmit(next);
            }
        });
        back.addEventListener('click', () => show(current - 1));
        form.addEventListener('change', event => { if (event.target.name === 'source') applySource(); summary(); });
        form.addEventListener('input', summary);
        applySource(); summary();
    }
    const panel = document.querySelector('[data-operation]');
    if (panel) {
        const status = document.getElementById('deployment-status');
        const poll = async () => {
            try {
                const response = await fetch(`/?page=deployment&poll=1&operation=${encodeURIComponent(panel.dataset.operation)}`, {credentials:'same-origin'});
                if (!response.ok) throw new Error(response.status === 401 ? 'Your session expired. Sign in again.' : 'Could not refresh. Reload to retry.');
                const data = await response.json();
                if (!data?.job) throw new Error('Operation not found.');
                const result = JSON.parse(data.job.result);
                status.textContent = data.job.status; status.dataset.status = data.job.status;
                document.getElementById('deployment-result').textContent = JSON.stringify(result, null, 2);
                document.getElementById('logs-content').replaceChildren(...data.steps.map(step => {
                    const line = document.createElement('div'); line.className = 'log-line';
                    line.textContent = `${new Date(Number(step.at)*1000).toLocaleTimeString()}  ${step.step}  ${step.data}`;
                    return line;
                }));
                document.getElementById('uncertain-help').hidden = data.job.status !== 'uncertain';
                if (result.deployment) {
                    const link = document.getElementById('next-deployment'); link.hidden = false;
                    link.href = `/?page=deployment&operation=${encodeURIComponent(result.deployment)}`;
                }
                if (['pending','running'].includes(data.job.status)) setTimeout(poll, 2500);
            } catch (error) { document.getElementById('poll-message').textContent = error.message; }
        };
        if (['pending','running'].includes(status.dataset.status)) setTimeout(poll, 1500);
    }
});
