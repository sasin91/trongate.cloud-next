// Server enrollment happens beside the wizard; the app form never navigates or serializes its secrets.
document.addEventListener('DOMContentLoaded', () => {
    const dialog = document.getElementById('server-dialog');
    if (!dialog) return;
    const app = document.querySelector('form[data-steps]');
    const connect = dialog.querySelector('[data-server-connect]');
    const confirm = dialog.querySelector('[data-server-confirm]');
    const unready = dialog.querySelector('[data-server-unready]');
    const error = dialog.querySelector('[data-server-error]');
    const choice = dialog.querySelector('[data-server-choice]');
    const providerPanel = dialog.querySelector('[data-provider-panel]');
    const pathBack = dialog.querySelector('[data-server-path-back]');
    const choose = mode => {
        choice.hidden = true; connect.hidden = mode !== 'existing'; providerPanel.hidden = mode !== 'provider';
        confirm.hidden = true; unready.hidden = true; error.hidden = true; pathBack.hidden = false;
    };
    dialog.querySelector('[data-choose-existing]').addEventListener('click', () => choose('existing'));
    dialog.querySelector('[data-choose-provider]').addEventListener('click', () => choose('provider'));
    pathBack.addEventListener('click', () => {
        choice.hidden = false; connect.hidden = true; providerPanel.hidden = true; confirm.hidden = true; unready.hidden = true; pathBack.hidden = true;
        connect.elements.provision_operation.value = ''; dialog.querySelector('[data-created-server-note]').hidden = true;
    });
    dialog.addEventListener('provider-created', event => {
        const server = event.detail;
        choose('existing');
        connect.elements.id.value = server.name; connect.elements.host.value = server.host;
        connect.elements.provision_operation.value = server.operation;
        const note = dialog.querySelector('[data-created-server-note]'); note.hidden = false;
        note.textContent = server.preparation_required ? 'Server created. Prepare its application runtime using the instructions above, then connect with the private key matching the public key you selected.' : 'Server created from your prepared snapshot. Connect with the matching SSH private key to verify its identity and readiness.';
    });
    let opener;
    document.querySelectorAll('[data-connect-server],a[href="/?page=connect-server"]').forEach(button => button.addEventListener('click', event => {
        event.preventDefault();
        opener = button;
        dialog.showModal();
    }));
    dialog.querySelectorAll('[data-close-server]').forEach(button => button.addEventListener('click', () => dialog.close()));
    dialog.addEventListener('close', () => opener?.focus());
    dialog.querySelector('[data-retry-server]').addEventListener('click', () => {
        confirm.hidden = true; connect.hidden = false; error.hidden = true;
    });
    [connect, confirm, unready].forEach(form => form.addEventListener('submit', async event => {
        event.preventDefault();
        const button = form.querySelector('button[type=submit],button:not([type])');
        const label = button.textContent;
        button.disabled = true; button.textContent = 'Connecting…'; error.hidden = true;
        try {
            const response = await fetch('/?page=connect-server', {method:'POST', body:new FormData(form), credentials:'same-origin'});
            if (!(response.headers.get('content-type') || '').includes('application/json')) {
                throw new Error('The request could not be verified. Your app draft is still here; sign in again in another tab if your session expired.');
            }
            const result = await response.json();
            if (!response.ok || result.error) throw new Error(result.error || 'Could not connect this server.');
            if (form === connect) {
                connect.elements.private_key.value = '';
                dialog.querySelector('[data-server-fingerprint]').textContent = result.fingerprint;
                confirm.elements.enrollment.value = result.enrollment;
                confirm.elements.fingerprint.value = '';
                connect.hidden = true; confirm.hidden = false;
                if (dialog.open) confirm.elements.fingerprint.focus();
            } else {
                confirm.hidden = true;
                if (!result.application_ready) {
                    unready.elements.server.value = result.id;
                    dialog.querySelector('[data-server-missing]').textContent = (result.missing || []).join(', ');
                    unready.hidden = false;
                    return;
                }
                if (!app) { window.location.assign('/'); return; }
                const select = app.elements.server;
                let option = [...select.options].find(option => option.value === result.id);
                if (!option) { option = new Option(`${result.id} · ${result.host}`,result.id); select.add(option); }
                select.value = result.id;
                select.dispatchEvent(new Event('change',{bubbles:true}));
                const builder = app.elements.builder;
                if (builder && ![...builder.options].some(option => option.value === result.id)) builder.add(new Option(result.id,result.id));
                dialog.close(); connect.hidden = true; unready.hidden = true; choice.hidden = false; pathBack.hidden = true; connect.reset(); confirm.reset();
                select.focus();
            }
        } catch (failure) {
            error.textContent = failure.message; error.hidden = false;
        } finally { button.disabled = false; button.textContent = label; }
    }));
});
