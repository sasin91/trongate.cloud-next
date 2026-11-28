// Ship's wheel spinner: while a form marked `data-helm` is submitting, its
// button shows a turning helm and the form's `data-helm` text.
(() => {
    const helm = '<svg class="hb-helm" viewBox="0 0 24 24" aria-hidden="true">'
        + '<path d="M12 1.6V22.4M1.6 12H22.4M4.65 4.65l14.7 14.7M19.35 4.65L4.65 19.35" stroke-width="1.9"/>'
        + '<circle cx="12" cy="12" r="6.6" stroke-width="2.1"/>'
        + '<circle class="hb-helm-hub" cx="12" cy="12" r="2.3"/>'
        + '</svg>';

    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-helm')) {
            return;
        }
        const button = event.submitter || form.querySelector('button[type="submit"]');
        // Let confirm dialogs and other handlers cancel first.
        setTimeout(() => {
            if (event.defaultPrevented || !button || button.classList.contains('hb-loading')) {
                return;
            }
            button.dataset.helmLabel = button.innerHTML;
            button.classList.add('hb-loading');
            button.setAttribute('aria-busy', 'true');
            const label = document.createElement('span');
            label.textContent = form.getAttribute('data-helm') || 'Setting sail…';
            button.innerHTML = helm;
            button.appendChild(label);
        });
    });

    // Restore buttons when the browser brings the page back from its back/forward cache.
    window.addEventListener('pageshow', (event) => {
        if (!event.persisted) {
            return;
        }
        document.querySelectorAll('.hb-loading[data-helm-label]').forEach((button) => {
            button.innerHTML = button.dataset.helmLabel;
            button.classList.remove('hb-loading');
            button.removeAttribute('aria-busy');
        });
    });
})();
