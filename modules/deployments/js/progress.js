document.addEventListener('DOMContentLoaded', () => {
    const panel = document.querySelector('[data-operation]');
    if (panel) {
        const status = document.getElementById('deployment-status');
        const poll = async () => {
            try {
                const response = await fetch(`/deployments?poll=1&operation=${encodeURIComponent(panel.dataset.operation)}`, {credentials:'same-origin'});
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
                    link.href = `/deployments?operation=${encodeURIComponent(result.deployment)}`;
                }
                if (['pending','running'].includes(data.job.status)) setTimeout(poll, 2500);
            } catch (error) { document.getElementById('poll-message').textContent = error.message; }
        };
        if (['pending','running'].includes(status.dataset.status)) setTimeout(poll, 1500);
    }
});
