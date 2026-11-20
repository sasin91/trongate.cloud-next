document.querySelectorAll('.resource-form').forEach(form => {
    const mode = form.elements.mode;
    const fields = [form.elements.memory_mb, form.elements.cpus];
    const feedback = form.querySelector('.resource-feedback');
    function update() {
        const automatic = mode.value === 'automatic';
        fields.forEach(field => { field.disabled = automatic; });
        const invalid = !automatic && fields.some(field => !field.validity.valid);
        feedback.textContent = invalid
            ? 'Outside the available budget. Use the displayed range or switch to automatic allocation.'
            : automatic
                ? 'The server will calculate a fresh fair share when you apply.'
                : `Reserves ${Number(fields[0].value) * 2} MiB for two versions. The server checks current usage before applying.`;
        feedback.classList.toggle('wz-errors', invalid);
    }
    form.addEventListener('input', update);
    form.addEventListener('change', update);
    update();
});
