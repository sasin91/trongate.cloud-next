window.panelMxTimeout = function () {
    const feedback = document.getElementById('mx-feedback');
    feedback.textContent = 'Could not confirm the update. Refresh before retrying.';
    feedback.className = 'notice';
    window.resourceRelease?.();
};

window.timelineRefresh = function () {
    const timeline = document.getElementById('deployment-content');
    const poll = document.getElementById('timeline-poll');
    if (timeline && poll && !['pending', 'running'].includes(timeline.dataset.status)) {
        TrongateMX.stopPolling(poll);
    } else if (poll) {
        TrongateMX.startPolling(poll, '3s');
    }
};

document.addEventListener('click', async event => {
    if (!event.target.closest('[data-copy-diagnostics]')) return;
    const message = document.querySelector('[data-timeline-message]');
    try {
        await navigator.clipboard.writeText(document.getElementById('timeline-diagnostics').textContent);
        message.textContent = 'Copied';
    } catch {
        message.textContent = 'Select the text to copy.';
    }
});
