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
    const button = event.target.closest('[data-copy-diagnostics]');
    if (!button) return;
    const source = document.getElementById('timeline-diagnostics');
    const message = document.querySelector('[data-timeline-message]');
    const text = source ? source.textContent : '';
    let copied = false;

    if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
        try {
            await navigator.clipboard.writeText(text);
            copied = true;
        } catch {
            copied = false;
        }
    }

    if (!copied) {
        const helper = document.createElement('textarea');
        helper.value = text;
        helper.setAttribute('readonly', '');
        helper.style.position = 'fixed';
        helper.style.top = '-9999px';
        helper.style.left = '-9999px';
        document.body.appendChild(helper);
        helper.focus();
        helper.select();
        try {
            copied = document.execCommand('copy');
        } catch {
            copied = false;
        }
        helper.remove();
    }

    if (copied) {
        const original = button.dataset.label || button.textContent;
        button.dataset.label = original;
        button.textContent = 'Copied!';
        setTimeout(() => {
            button.textContent = original;
        }, 2000);
        if (message) {
            message.textContent = 'Diagnostics copied.';
        }
    } else if (message) {
        message.textContent = 'Could not copy automatically. Select the text to copy.';
    }
});
