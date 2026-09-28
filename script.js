const sidebar = document.querySelector('#sidebar');
const backdrop = document.querySelector('#sidebar-backdrop');
const menuButton = document.querySelector('#menu-button');

function refreshIcons() {
    window.lucide?.createIcons();
}

function closeSidebar() {
    sidebar.classList.remove('open');
    backdrop.classList.remove('visible');
    menuButton.setAttribute('aria-expanded', 'false');
}

function openSidebar() {
    sidebar.classList.add('open');
    backdrop.classList.add('visible');
    menuButton.setAttribute('aria-expanded', 'true');
}

menuButton.addEventListener('click', () => sidebar.classList.contains('open') ? closeSidebar() : openSidebar());
backdrop.addEventListener('click', closeSidebar);
document.addEventListener('keydown', event => {
    if (event.key === 'Escape') closeSidebar();
});

document.querySelectorAll('.delete-update').forEach(button => {
    button.addEventListener('click', event => {
        if (!window.confirm(button.dataset.confirm || 'Delete this update permanently?')) event.preventDefault();
    });
});

const catalogueManager = document.querySelector('[data-catalogue-manager]');
let refreshCatalogue = async () => {};
if (catalogueManager) {
    const endpoint = catalogueManager.dataset.endpoint;
    const list = catalogueManager.querySelector('[data-catalogue-list]');
    const refreshState = catalogueManager.querySelector('[data-catalogue-refresh-state]');
    const managerCount = catalogueManager.querySelector('[data-catalogue-manager-count]');
    const brandOptions = document.querySelector('[data-catalogue-brands]');
    const writesEnabled = catalogueManager.dataset.writesEnabled === '1';
    let currentRevision = null;
    let imageBaseUrl = '';

    const makeElement = (tag, className, text) => {
        const element = document.createElement(tag);
        if (className) element.className = className;
        if (text !== undefined) element.textContent = text;
        return element;
    };
    const makeIcon = name => {
        const icon = document.createElement('i');
        icon.dataset.lucide = name;
        return icon;
    };
    const imageUrl = path => imageBaseUrl + path.split('/').map(encodeURIComponent).join('/');
    const makeAccordion = (kind, item, iconName, childLabel, renderChildren) => {
        const details = makeElement('details', `catalogue-accordion catalogue-${kind}`);
        const summary = document.createElement('summary');
        const icon = makeElement('span', 'catalogue-accordion-icon');
        icon.append(makeIcon(iconName));
        const labels = document.createElement('span');
        labels.append(makeElement('strong', '', item.name || ''), makeElement('small', '', childLabel));
        const chevron = makeIcon('chevron-down');
        chevron.className = 'catalogue-chevron';
        summary.append(icon, labels, chevron);
        const content = makeElement('div', kind === 'version' ? 'catalogue-sizes' : 'catalogue-accordion-content');
        details.append(summary, content);
        details.addEventListener('toggle', () => {
            if (!details.open || details.dataset.rendered === '1') return;
            details.dataset.rendered = '1';
            renderChildren(content);
            refreshIcons();
        });
        return details;
    };
    const appendHidden = (form, name, value) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.append(input);
    };
    const makeSize = (brand, range, version, size) => {
        const row = makeElement('div', 'catalogue-size');
        const heading = makeElement('div', 'catalogue-size-heading');
        const labels = document.createElement('div');
        const name = makeElement('strong', '', size.name || '');
        name.dataset.catalogueSizeName = '';
        const dimensions = makeElement('small', '', `${Number(size.width) || 0} × ${Number(size.height) || 0} px`);
        dimensions.dataset.catalogueSizeDimensions = '';
        labels.append(name, dimensions);

        const controls = makeElement('div', 'catalogue-size-controls');
        const imageCount = makeElement('span', '', `${(size.images || []).length} image(s)`);
        imageCount.dataset.catalogueImageCount = '';
        const entry = {
            original_brand: brand.id, original_range: range.id, original_version: version.id, original_size: size.id,
            brand: brand.name, range: range.name, version: version.name, size: size.name,
            width: size.width, height: size.height,
            images: (size.images || []).map(path => ({ name: path.split('/').pop(), url: imageUrl(path) })),
        };
        const edit = makeElement('button', 'icon-button');
        edit.type = 'button';
        edit.dataset.catalogueEdit = JSON.stringify(entry);
        edit.title = 'Edit entry';
        edit.setAttribute('aria-label', `Edit ${size.name || ''}`);
        edit.append(makeIcon('pencil'));

        const deleteForm = document.createElement('form');
        deleteForm.method = 'post';
        deleteForm.dataset.catalogueDelete = '';
        appendHidden(deleteForm, 'csrf_token', catalogueManager.dataset.csrfToken);
        appendHidden(deleteForm, 'action', 'delete-size');
        appendHidden(deleteForm, 'brand', brand.id);
        appendHidden(deleteForm, 'range', range.id);
        appendHidden(deleteForm, 'version', version.id);
        appendHidden(deleteForm, 'size', size.id);
        const remove = makeElement('button', 'icon-button catalogue-delete-button');
        remove.type = 'submit';
        remove.title = 'Delete entry';
        remove.setAttribute('aria-label', `Delete ${size.name || ''}`);
        remove.disabled = !writesEnabled;
        remove.append(makeIcon('trash-2'));
        deleteForm.append(remove);
        controls.append(imageCount, edit, deleteForm);
        heading.append(labels, controls);
        row.append(heading);
        return row;
    };
    const makeVersion = (brand, range, version) => makeAccordion(
        'version', version, 'swatch-book', `${(version.sizes || []).length} size(s)`,
        content => content.append(...(version.sizes || []).map(size => makeSize(brand, range, version, size)))
    );
    const makeRange = (brand, range) => makeAccordion(
        'range', range, 'layers-3', `${(range.versions || []).length} version(s)`,
        content => content.append(...(range.versions || []).map(version => makeVersion(brand, range, version)))
    );
    const makeBrand = brand => makeAccordion(
        'brand', brand, 'building-2', `${(brand.ranges || []).length} range(s)`,
        content => content.append(...(brand.ranges || []).map(range => makeRange(brand, range)))
    );
    const renderCatalogue = result => {
        imageBaseUrl = result.image_base_url;
        Object.entries(result.summary).forEach(([key, total]) => {
            const target = document.querySelector(`[data-catalogue-total="${key}"]`);
            if (target) target.textContent = total.toLocaleString();
        });
        managerCount.textContent = `${result.summary.sizes.toLocaleString()} SIZES`;
        brandOptions.replaceChildren(...result.catalogue.brands.map(brand => {
            const option = document.createElement('option');
            option.value = brand.name;
            return option;
        }));
        list.className = 'catalogue-list';
        list.replaceChildren(...result.catalogue.brands.map(makeBrand));
        if (result.catalogue.brands.length === 0) {
            list.className = 'list-message';
            list.textContent = 'No catalogue sizes have been added.';
        }
        refreshIcons();
    };

    refreshCatalogue = async (force = false) => {
        refreshState.textContent = 'Refreshing…';
        try {
            const response = await fetch(`${endpoint}?view=catalogue`, {
                headers: { 'Accept': 'application/json' },
                cache: force ? 'no-cache' : 'default',
            });
            const result = await response.json().catch(() => ({ error: 'The catalogue response was invalid.' }));
            if (!response.ok || !result.ok) throw new Error(result.error || 'The catalogue could not be loaded.');
            if (result.revision !== currentRevision) {
                renderCatalogue(result);
                currentRevision = result.revision;
            }
            refreshState.textContent = 'Up to date';
        } catch (error) {
            refreshState.textContent = 'Refresh failed';
            if (currentRevision === null) {
                list.className = 'list-message';
                list.textContent = error instanceof Error ? error.message : 'The catalogue could not be loaded.';
            }
        }
    };
    refreshCatalogue();
    window.setInterval(() => {
        if (!document.hidden) refreshCatalogue(true);
    }, 60000);
}

const storageStatus = document.querySelector('[data-catalogue-storage-status]');
if (storageStatus) {
    const formatBytes = bytes => {
        if (!Number.isFinite(bytes) || bytes <= 0) return '0 B';
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        const unit = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
        return `${(bytes / (1024 ** unit)).toFixed(unit > 1 ? 1 : 0)} ${units[unit]}`;
    };
    const pollStorage = async () => {
        try {
            const response = await fetch(`${storageStatus.dataset.endpoint}?view=status`, {
                headers: { 'Accept': 'application/json' }, cache: 'no-store',
            });
            const result = await response.json();
            if (!response.ok || !result.ok) throw new Error(result.error || 'Status unavailable');
            const status = result.storage;
            storageStatus.querySelector('[data-storage-ready]').textContent = status.ready.toLocaleString();
            storageStatus.querySelector('[data-storage-pending]').textContent = status.pending.toLocaleString();
            storageStatus.querySelector('[data-storage-errors]').textContent = status.errors.toLocaleString();
            storageStatus.querySelector('[data-storage-bytes]').textContent = formatBytes(status.ready_bytes);
            const state = status.errors > 0 ? 'Needs attention' : status.pending > 0 ? 'Migrating' : 'Complete';
            const stateElement = storageStatus.querySelector('[data-storage-state]');
            stateElement.textContent = `${state} · ${status.percent.toFixed(1)}%`;
            stateElement.dataset.state = status.errors > 0 ? 'error' : status.pending > 0 ? 'active' : 'complete';
            const progress = storageStatus.querySelector('.catalogue-storage-progress');
            progress.setAttribute('aria-valuenow', status.percent);
            storageStatus.querySelector('[data-storage-progress]').style.width = `${status.percent}%`;
            const updated = status.updated_at ? new Date(`${status.updated_at.replace(' ', 'T')}Z`) : null;
            storageStatus.querySelector('[data-storage-updated]').textContent = updated && !Number.isNaN(updated.valueOf())
                ? `Last migration update ${updated.toLocaleString()}`
                : 'Waiting for migration activity…';
        } catch {
            storageStatus.querySelector('[data-storage-state]').textContent = 'Status unavailable';
        }
    };
    pollStorage();
    window.setInterval(() => {
        if (!document.hidden) pollStorage();
    }, 3000);
}

const catalogueModal = document.querySelector('[data-catalogue-modal]');
if (catalogueModal) {
    const editForm = catalogueModal.querySelector('[data-catalogue-edit-form]');
    const imageList = catalogueModal.querySelector('[data-catalogue-edit-images]');
    const formStatus = catalogueModal.querySelector('[data-catalogue-form-status]');
    let activeEditButton = null;

    const renderCatalogueImages = images => {
        imageList.replaceChildren();
        if (images.length === 0) {
            const empty = document.createElement('p');
            empty.textContent = 'No images are currently attached.';
            imageList.append(empty);
        }
        images.forEach(image => {
            const preview = document.createElement('span');
            const thumbnail = document.createElement('img');
            thumbnail.src = image.url;
            thumbnail.alt = '';
            const name = document.createElement('small');
            name.textContent = image.name;
            preview.append(thumbnail, name);
            imageList.append(preview);
        });
    };

    document.addEventListener('click', event => {
        const button = event.target.closest('[data-catalogue-edit]');
        if (!button || !catalogueManager?.contains(button)) return;
        activeEditButton = button;
        const entry = JSON.parse(button.dataset.catalogueEdit);
        editForm.reset();
        formStatus.textContent = '';
        formStatus.className = 'catalogue-form-status';
        ['brand', 'range', 'version', 'size', 'width', 'height'].forEach(field => {
            editForm.elements[field].value = entry[field];
        });
        ['brand', 'range', 'version', 'size'].forEach(field => {
            editForm.elements[`original_${field}`].value = entry[`original_${field}`];
        });

        renderCatalogueImages(entry.images);
        catalogueModal.showModal();
    });

    editForm.addEventListener('submit', async event => {
        event.preventDefault();
        const submitButton = editForm.querySelector('button[type="submit"]');
        submitButton.disabled = true;
        formStatus.textContent = 'Uploading and saving…';
        formStatus.className = 'catalogue-form-status pending';

        try {
            const response = await fetch(editForm.action || location.href, {
                method: 'POST',
                body: new FormData(editForm),
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            });
            const result = await response.json().catch(() => ({ error: 'The server returned an invalid upload response.' }));
            if (!response.ok || !result.ok) throw new Error(result.error || 'The catalogue entry could not be saved.');

            const entry = result.entry;
            renderCatalogueImages(entry.images);
            editForm.elements['images[]'].value = '';
            editForm.elements.replace_images.checked = false;
            ['brand', 'range', 'version', 'size'].forEach(field => {
                editForm.elements[`original_${field}`].value = entry[`original_${field}`];
            });

            if (activeEditButton) {
                activeEditButton.dataset.catalogueEdit = JSON.stringify(entry);
                activeEditButton.setAttribute('aria-label', `Edit ${entry.size}`);
                const sizeRow = activeEditButton.closest('.catalogue-size');
                sizeRow.querySelector('[data-catalogue-size-name]').textContent = entry.size;
                sizeRow.querySelector('[data-catalogue-size-dimensions]').textContent = `${entry.width} × ${entry.height} px`;
                sizeRow.querySelector('[data-catalogue-image-count]').textContent = `${entry.images.length} image(s)`;
                sizeRow.closest('.catalogue-version').querySelector(':scope > summary strong').textContent = entry.version;
                sizeRow.closest('.catalogue-range').querySelector(':scope > summary strong').textContent = entry.range;
                sizeRow.closest('.catalogue-brand').querySelector(':scope > summary strong').textContent = entry.brand;
            }
            document.querySelector('[data-catalogue-total="images"]').textContent = result.total_images;
            formStatus.textContent = result.uploaded > 0 ? `Saved with ${result.uploaded} new image(s).` : 'Catalogue entry saved.';
            formStatus.className = 'catalogue-form-status success';
        } catch (error) {
            formStatus.textContent = error instanceof Error ? error.message : 'The catalogue entry could not be saved.';
            formStatus.className = 'catalogue-form-status error';
        } finally {
            submitButton.disabled = false;
        }
    });

    catalogueModal.querySelectorAll('[data-catalogue-modal-close]').forEach(button => {
        button.addEventListener('click', () => catalogueModal.close());
    });
    catalogueModal.addEventListener('click', event => {
        if (event.target === catalogueModal) catalogueModal.close();
    });
}

document.addEventListener('submit', async event => {
    const form = event.target.closest('[data-catalogue-delete]');
    if (!form) return;
    event.preventDefault();
    const sizeRow = form.closest('.catalogue-size');
    const sizeName = sizeRow.querySelector('[data-catalogue-size-name]').textContent.trim();
    if (!window.confirm(`Delete ${sizeName} and all of its images permanently?`)) return;

    const button = form.querySelector('button[type="submit"]');
    button.disabled = true;
    try {
        const response = await fetch(form.action || location.href, {
            method: 'POST',
            body: new FormData(form),
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
        });
        const result = await response.json().catch(() => ({ error: 'The server returned an invalid delete response.' }));
        if (!response.ok || !result.ok) throw new Error(result.error || 'The catalogue entry could not be deleted.');

        const version = sizeRow.closest('.catalogue-version');
        const range = sizeRow.closest('.catalogue-range');
        const brand = sizeRow.closest('.catalogue-brand');
        sizeRow.remove();

        const versionCount = version.querySelectorAll(':scope > .catalogue-sizes > .catalogue-size').length;
        if (versionCount === 0) {
            version.remove();
            const rangeCount = range.querySelectorAll(':scope > .catalogue-accordion-content > .catalogue-version').length;
            if (rangeCount === 0) {
                range.remove();
                const brandCount = brand.querySelectorAll(':scope > .catalogue-accordion-content > .catalogue-range').length;
                if (brandCount === 0) brand.remove();
                else brand.querySelector(':scope > summary small').textContent = `${brandCount} range(s)`;
            } else {
                range.querySelector(':scope > summary small').textContent = `${rangeCount} version(s)`;
            }
        } else {
            version.querySelector(':scope > summary small').textContent = `${versionCount} size(s)`;
        }

        Object.entries(result.summary).forEach(([key, total]) => {
            const target = document.querySelector(`[data-catalogue-total="${key}"]`);
            if (target) target.textContent = total;
        });
        const managerCount = document.querySelector('.catalogue-manager > .card-heading small');
        if (managerCount) managerCount.textContent = `${result.summary.sizes} SIZES`;
        window.setTimeout(() => refreshCatalogue(true), 500);
    } catch (error) {
        window.alert(error instanceof Error ? error.message : 'The catalogue entry could not be deleted.');
        button.disabled = false;
    }
});

const catalogueImportModal = document.querySelector('[data-catalogue-import-modal]');
if (catalogueImportModal) {
    document.querySelector('[data-catalogue-import-open]')?.addEventListener('click', () => catalogueImportModal.showModal());
    catalogueImportModal.querySelectorAll('[data-catalogue-import-close]').forEach(button => {
        button.addEventListener('click', () => catalogueImportModal.close());
    });
    catalogueImportModal.addEventListener('click', event => {
        if (event.target === catalogueImportModal) catalogueImportModal.close();
    });
}

const updateState = document.querySelector('#update-state');
if (updateState) {
    const publishAtField = document.querySelector('#publish-at-field');
    const refreshPublicationField = () => {
        publishAtField.querySelector('input').required = updateState.value === 'scheduled';
        publishAtField.style.opacity = updateState.value === 'draft' ? '0.65' : '1';
    };
    updateState.addEventListener('change', refreshPublicationField);
    refreshPublicationField();
}

const updateEditor = document.querySelector('[data-update-editor]');
if (updateEditor) {
    updateEditor.addEventListener('submit', () => {
        const fields = {};
        updateEditor.querySelectorAll('[data-update-field]').forEach(field => {
            fields[field.dataset.updateField] = field.value;
        });
        const bytes = new TextEncoder().encode(JSON.stringify(fields));
        let binary = '';
        const chunkSize = 0x8000;
        for (let offset = 0; offset < bytes.length; offset += chunkSize) {
            binary += String.fromCharCode(...bytes.subarray(offset, offset + chunkSize));
        }
        updateEditor.elements.payload.value = btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
    });
}

const chartData = document.querySelector('#downloads-chart-data');
const chartCanvas = document.querySelector('#downloads-chart');
if (chartData && chartCanvas && window.Chart) {
    const series = JSON.parse(chartData.textContent);
    const current = series.current;
    const previous = series.previous;
    new Chart(chartCanvas, {
        type: 'line',
        data: {
            labels: current.map(point => point.label),
            datasets: [
                {
                    label: 'Current period',
                    data: current.map(point => point.downloads),
                    borderColor: '#89b4fa',
                    backgroundColor: 'rgba(137, 180, 250, 0.12)',
                    borderWidth: 3,
                    pointRadius: current.length <= 7 ? 3 : 0,
                    pointHoverRadius: 5,
                    tension: 0.38,
                    fill: true,
                },
                {
                    label: 'Previous period',
                    data: previous.map(point => point.downloads),
                    borderColor: '#bac2de',
                    borderWidth: 2,
                    borderDash: [6, 5],
                    pointRadius: 0,
                    pointHoverRadius: 4,
                    tension: 0.38,
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { intersect: false, mode: 'index' },
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { color: '#bac2de', usePointStyle: true, boxWidth: 7, boxHeight: 7, padding: 18 },
                },
                tooltip: {
                    callbacks: {
                        title(items) {
                            const index = items[0].dataIndex;
                            return `${current[index].date} · previous ${previous[index].date}`;
                        },
                    },
                },
            },
            scales: {
                x: { grid: { display: false }, ticks: { color: '#bac2de', maxTicksLimit: 8, maxRotation: 0 } },
                y: { beginAtZero: true, grid: { color: 'rgba(108, 112, 134, 0.35)' }, ticks: { color: '#bac2de', precision: 0 } },
            },
        },
    });
}

refreshIcons();