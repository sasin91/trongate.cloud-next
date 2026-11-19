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
        let current = Number(form.dataset.step) || 0;
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
        document.querySelectorAll('a[href^="/"]').forEach(link => link.addEventListener('click', async event => {
            if (event.ctrlKey || event.metaKey || event.shiftKey || link.target === '_blank') return;
            event.preventDefault();
            const data = new FormData(form);
            data.set('wizard_step', String(current));
            try {
                const response = await fetch('/apps/draft', {method: 'POST', body: data, credentials: 'same-origin'});
                const result = await response.json();
                if (!response.ok || !result.saved) throw new Error(result.error || 'Could not save the app draft.');
                window.location.assign(link.href);
            } catch (error) {
                window.alert(error.message);
            }
        }));
        applySource(); summary();
    }
});
