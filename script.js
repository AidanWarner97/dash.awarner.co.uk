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
        if (!window.confirm('Delete this update permanently?')) event.preventDefault();
    });
});

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