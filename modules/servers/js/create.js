const serverChoices = {
    sizes(options, location) {
        return options.server_types.filter(type => !type.deprecated &&
            type.locations?.some(region => region.name === location && region.available) &&
            type.prices?.some(price => price.location === location))
            .sort((a, b) => Number(a.prices.find(p => p.location === location).price_monthly.net) - Number(b.prices.find(p => p.location === location).price_monthly.net));
    },
    images(options, type) {
        return type ? options.images.filter(image => image.architecture === type.architecture &&
            image.status === 'available' && !image.deprecated && Number(image.disk_size || 0) <= Number(type.disk) &&
            (image.type === 'system' || (image.type === 'snapshot' && image.labels?.['tc-nixos'] === 'true'))) : [];
    },
    suggestedSize(rows) {
        return rows.find(type => type.name === 'cax11') || rows.find(type => Number(type.memory) >= 4) || rows[0];
    },
    preferred(rows, saved, key, fallback) {
        return rows.find(row => String(row[key]) === String(saved)) || rows.find(fallback) || rows[0];
    }
};
if (typeof module !== 'undefined') module.exports = serverChoices;
if (typeof document !== 'undefined') document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('[data-server-config]');
    if (!form) return;
    const options = JSON.parse(form.dataset.options), saved = JSON.parse(form.dataset.saved);
    const fields = form.elements;
    const select = (field, rows, key, label, chosen) => {
        field.replaceChildren(...rows.map(row => new Option(label(row), String(row[key]))));
        if (chosen) field.value = String(chosen[key]);
        if (!rows.length) field.add(new Option('No available options', ''));
    };
    const currency = options.pricing.currency;
    const updateImages = () => {
        const type = options.server_types.find(t => t.name === fields.server_type.value);
        const images = serverChoices.images(options, type);
        const previous = fields.image.value || saved.image;
        select(fields.image, images, 'id', image => `${image.description} · ${image.architecture}${image.type === 'snapshot' ? ' · Prepared runtime' : ''}`,
            serverChoices.preferred(images, previous, 'id', image => image.id === (images.find(item => item.type === 'snapshot') || images.find(item => item.name === 'debian-12') || images[0])?.id));
        const price = type?.prices.find(p => p.location === fields.location.value);
        const ip = options.pricing.primary_ips.find(ip => ip.type === 'ipv4')?.prices.find(p => p.location === fields.location.value);
        form.querySelector('[data-size-summary]').textContent = type ? `${type.name.toUpperCase()} · ${type.cores} vCPU · ${type.memory} GB RAM · ${type.disk} GB disk · ${type.architecture}` : 'No sizes available in this region';
        form.querySelector('[data-size-price]').textContent = price && ip ? `${(Number(price.price_monthly.net) + Number(ip.price_monthly.net)).toFixed(2)} ${currency}/month cap · ${(Number(price.price_hourly.net) + Number(ip.price_hourly.net)).toFixed(4)} ${currency}/hour` : 'Pricing unavailable';
        const image = images.find(image => String(image.id) === fields.image.value);
        form.querySelector('[data-size-note]').textContent = `Includes IPv4, excludes tax. ${image?.type === 'snapshot' ? 'Prepared runtime will be verified after connection.' : 'Standard images require runtime preparation before deploying apps.'}`;
        form.querySelector('[data-options-empty]').textContent = !type ? 'Choose another region.' : !images.length ? 'No compatible images. Choose another size.' : !options.ssh_keys.length ? 'Add an SSH public key in Hetzner, then reload the options.' : '';
        form.querySelector('[data-review]').disabled = !type || !images.length || !options.ssh_keys.length || !price || !ip;
    };
    const updateSizes = () => {
        const sizes = serverChoices.sizes(options, fields.location.value);
        const previous = fields.server_type.value || saved.server_type;
        select(fields.server_type, sizes, 'name', type => {
            const price = type.prices.find(p => p.location === fields.location.value);
            return `${type.name.toUpperCase()} · ${type.cores} vCPU / ${type.memory} GB · ${Number(price.price_monthly.net).toFixed(2)} ${currency}/mo + IPv4${type.name === 'cax11' ? ' · Suggested' : ''}`;
        }, serverChoices.preferred(sizes, previous, 'name', type => type === serverChoices.suggestedSize(sizes)));
        updateImages();
    };
    const regions = options.locations.filter(location => serverChoices.sizes(options, location.name).length);
    select(fields.location, regions, 'name', region => `${region.description} (${region.name})`, serverChoices.preferred(regions, saved.location, 'name', region => region.name === 'nbg1'));
    select(fields.ssh_key, options.ssh_keys, 'id', key => key.name, serverChoices.preferred(options.ssh_keys, saved.ssh_key, 'id', () => false));
    fields.location.addEventListener('change', updateSizes);
    fields.server_type.addEventListener('change', updateImages);
    fields.image.addEventListener('change', updateImages);
    updateSizes();
});
