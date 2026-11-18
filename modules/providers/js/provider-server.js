document.addEventListener('DOMContentLoaded', () => {
    const dialog = document.getElementById('server-dialog');
    if (!dialog) return;
    const panel = dialog.querySelector('[data-provider-panel]');
    const project = panel.querySelector('[data-provider-options]');
    const connect = panel.querySelector('[data-provider-connect]');
    const config = panel.querySelector('[data-provider-config]');
    const review = panel.querySelector('[data-provider-review]');
    const progress = panel.querySelector('[data-provider-progress]');
    const error = dialog.querySelector('[data-server-error]');
    const back = dialog.querySelector('[data-server-path-back]');
    let options, operation, timer;
    const request = async data => {
        const response = await fetch('/servers',{method:'POST',body:data,credentials:'same-origin'});
        if (!(response.headers.get('content-type') || '').includes('application/json')) throw new Error('Request could not be verified. Your app draft is still here; sign in again in another tab if needed.');
        const result = await response.json();
        if (!response.ok || result.error) throw new Error(result.error || 'Provider request failed.');
        return result;
    };
    const populate = (select, rows, label, value) => {
        select.replaceChildren(new Option('Choose an option',''),...rows.map(row => new Option(label(row),String(value(row)))));
    };
    const images = () => {
        const type = options.server_types.find(type => type.name === config.elements.server_type.value);
        populate(config.elements.image,options.images.filter(image => type && image.architecture === type.architecture && image.status === 'available' && !image.deprecated && (!image.disk_size || image.disk_size <= type.disk)),
            image => `${image.description} · ${image.type === 'snapshot' ? 'Prepared Trongate snapshot' : 'Requires runtime preparation'}`,image => image.id);
    };
    const types = () => {
        const location = config.elements.location.value;
        populate(config.elements.server_type,options.server_types.filter(type => !type.deprecated && type.locations?.some(item => item.name === location && item.available)),type => {
            const price = type.prices.find(price => price.location === location);
            return `${type.name} · ${type.cores} CPU / ${type.memory} GB · ${price?.price_monthly.net || '?'} ${options.pricing.currency}/month + IPv4, before tax`;
        },type => type.name);
        images();
    };
    config.elements.location.addEventListener('change',types);
    config.elements.server_type.addEventListener('change',images);
    const loadOptions = async () => {
        options = await request(new FormData(project));
        config.elements.provider.value = project.elements.provider.value;
        populate(config.elements.location,options.locations,location => location.description || location.name,location => location.name);
        populate(config.elements.ssh_key,options.ssh_keys,key => `${key.name} · ${key.fingerprint}`,key => key.id);
        config.hidden = false; review.hidden = true; types();
    };
    const poll = async () => {
        clearTimeout(timer);
        if (!operation) return;
        try {
            const data = new FormData(); data.set('csrf',project.elements.csrf.value); data.set('inline','server'); data.set('command','provider-server-status'); data.set('operation',operation);
            const result = await request(data);
            panel.querySelector('[data-provider-status]').textContent = result.message || `Server creation: ${result.status}`;
            if (result.status === 'succeeded') {
                progress.hidden = true; project.hidden = false; connect.closest('details').hidden = false;
                dialog.dispatchEvent(new CustomEvent('provider-created',{detail:result})); operation = null;
            } else if (['pending','running'].includes(result.status)) timer = setTimeout(poll,3000);
        } catch (failure) { error.textContent = failure.message; error.hidden = false; }
    };
    panel.querySelector('[data-provider-check]').addEventListener('click',poll);
    panel.querySelector('[data-provider-edit]').addEventListener('click',() => { review.hidden = true; config.hidden = false; project.hidden = false; connect.closest('details').hidden = false; });
    [project,connect,config,review].forEach(form => form.addEventListener('submit',async event => {
        event.preventDefault(); error.hidden = true;
        const button = form.querySelector('button:not([type]),button[type=submit]');
        const original = button.textContent; button.disabled = true; button.textContent = 'Working…';
        try {
            if (form === project) await loadOptions();
            else if (form === connect) {
                const result = await request(new FormData(connect));
                connect.elements.token.value = '';
                project.elements.provider.add(new Option(result.id,result.id)); project.elements.provider.value = result.id;
                connect.closest('details').open = false; await loadOptions();
            } else if (form === config) {
                const result = await request(new FormData(config)); review.elements.quote.value = result.quote; review.elements.confirm_cost.checked = false;
                const summary = panel.querySelector('[data-provider-summary]'); summary.replaceChildren();
                const rows = {Name:result.name,Project:result.provider,Region:result.location,Size:result.type,Image:result.image,'SSH key':result.ssh_key,'Monthly cap':`${result.monthly_net} ${result.currency} before tax`,'Hourly rate':`${result.hourly_net} ${result.currency} before tax`};
                Object.entries(rows).forEach(([key,value]) => { const dt=document.createElement('dt'),dd=document.createElement('dd'); dt.textContent=key; dd.textContent=value; summary.append(dt,dd); });
                panel.querySelector('[data-provider-preparation]').textContent = result.preparation_required ? 'This standard Linux image needs application runtime preparation after creation. It will not be marked ready until inspection passes.' : 'This prepared snapshot will be checked for SSH identity and application readiness after creation.';
                config.hidden = true; project.hidden = true; connect.closest('details').hidden = true; review.hidden = false;
            } else {
                const result = await request(new FormData(review)); operation = result.operation;
                review.hidden = true; project.hidden = true; connect.closest('details').hidden = true; progress.hidden = false; back.hidden = true;
                panel.querySelector('[data-provider-operation]').href = `/deployments?operation=${encodeURIComponent(operation)}`;
                await poll();
            }
        } catch (failure) { error.textContent = failure.message; error.hidden = false; }
        finally { button.disabled = false; button.textContent = original; }
    }));
});
