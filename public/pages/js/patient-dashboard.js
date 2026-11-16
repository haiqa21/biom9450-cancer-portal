// Patient dashboard: filter the mutation table and show the first rows until "Show all" is clicked.

const PAGE_SIZE = 15;

document.addEventListener('DOMContentLoaded', () => {
    const explainerToggle = document.querySelector('.explainer-toggle');
    if (explainerToggle) {
        const explainer = document.getElementById(explainerToggle.getAttribute('aria-controls'));
        explainerToggle.addEventListener('click', () => {
            const open = explainerToggle.getAttribute('aria-expanded') === 'true';
            explainerToggle.setAttribute('aria-expanded', String(!open));
            explainer.hidden = open;
            explainerToggle.textContent = open ? 'Show explanation' : 'Hide explanation';
        });
    }

    const table = document.getElementById('mutation-table');
    if (!table) {
        return;   // no samples yet, so there is no table
    }

    const rows = Array.from(table.querySelectorAll('tbody tr'));
    const filter = document.getElementById('impact-filter');
    const showMore = document.getElementById('show-more');
    let showAll = false;

    function update() {
        const proteinOnly = filter.value === 'protein';
        const matching = rows.filter((row) =>
            !proteinOnly || row.dataset.impact === 'high' || row.dataset.impact === 'moderate');

        rows.forEach((row) => { row.hidden = true; });
        matching.forEach((row, i) => { row.hidden = !showAll && i >= PAGE_SIZE; });

        const hiddenCount = matching.length - PAGE_SIZE;
        showMore.hidden = showAll || hiddenCount <= 0;
        showMore.textContent = `Show ${hiddenCount} more`;
    }

    filter.addEventListener('change', update);
    showMore.addEventListener('click', () => {
        showAll = true;
        update();
    });

    update();
});
