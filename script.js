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

    document.querySelectorAll('[data-catalogue-edit]').forEach(button => {
        button.addEventListener('click', () => {
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

document.querySelectorAll('[data-catalogue-delete]').forEach(form => {
    form.addEventListener('submit', async event => {
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
        } catch (error) {
            window.alert(error instanceof Error ? error.message : 'The catalogue entry could not be deleted.');
            button.disabled = false;
        }
    });
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