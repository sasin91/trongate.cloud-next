document.addEventListener('DOMContentLoaded', () => {
    const panel = document.querySelector('[data-provision]');
    if (!panel) return;
    const status = document.getElementById('provision-status');
    const updates = document.getElementById('provision-updates');
    const logs = document.getElementById('provision-logs');
    let active = ['pending', 'running'].includes(status.dataset.status);
    const elapsed = document.getElementById('provision-elapsed');
    const tick = () => {
        const seconds = Math.max(0, Math.floor(Date.now() / 1000) - Number(elapsed.dataset.started));
        elapsed.textContent = `Started ${Math.floor(seconds / 60)}m ${seconds % 60}s ago`;
    };
    tick();
    const clock = setInterval(tick, 1000);
    const poll = async () => {
        try {
            const response = await fetch(`/servers/provision?poll=1&operation=${encodeURIComponent(panel.dataset.provision)}`, {credentials: 'same-origin'});
            if (!response.ok) throw new Error(response.status === 401 ? 'Session expired. Sign in again to resume progress.' : 'Could not refresh progress. Use Refresh status to retry.');
            const data = await response.json();
            status.textContent = data.status;
            status.dataset.status = data.status;
            document.getElementById('provision-description').textContent = data.description;
            panel.querySelectorAll('[data-stage]').forEach(item => item.classList.toggle('reached', Number(item.dataset.stage) <= data.stage));
            const bottom = logs.scrollHeight - logs.scrollTop - logs.clientHeight < 40;
            logs.replaceChildren(...data.logs.map(entry => {
                const line = document.createElement('div');
                line.className = 'log-line';
                line.textContent = `${new Date(entry.at * 1000).toLocaleTimeString()}  ${entry.message}`;
                return line;
            }));
            if (bottom) logs.scrollTop = logs.scrollHeight;
            document.getElementById('provision-address').textContent = data.host ? `Server address: ${data.host}` : '';
            document.getElementById('provision-connect').hidden = data.status !== 'succeeded';
            active = ['pending', 'running'].includes(data.status);
            updates.textContent = active ? 'Live · updated just now' : 'Activity complete';
            if (active) setTimeout(poll, 2500);
            else clearInterval(clock);
        } catch (error) {
            updates.textContent = error.message;
            clearInterval(clock);
        }
    };
    if (active) setTimeout(poll, 1000);
    else { updates.textContent = 'Activity complete'; clearInterval(clock); }
});
