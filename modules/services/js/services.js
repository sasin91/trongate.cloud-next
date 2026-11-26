document.addEventListener('click', event => {
    const button = event.target.closest('[data-service-preset]');
    if (!button) {
        return;
    }
    const form = document.getElementById('add-service-form');
    if (!form) {
        return;
    }
    try {
        const preset = JSON.parse(button.dataset.servicePreset);
        if (form.elements.service_name) form.elements.service_name.value = preset.name || '';
        if (form.elements.service_packages) form.elements.service_packages.value = preset.packages || '';
        if (form.elements.service_setup) form.elements.service_setup.value = preset.setup || '';
        if (form.elements.service_command) form.elements.service_command.value = preset.command || '';
        if (form.elements.service_port) form.elements.service_port.value = String(preset.port || 8080);
        if (form.elements.service_memory_mb) form.elements.service_memory_mb.value = String(preset.memory_mb || 512);
        if (form.elements.service_env) form.elements.service_env.value = preset.env || '';
        form.elements.service_name?.focus();
    } catch {
        // Ignore malformed preset attribute.
    }
});
