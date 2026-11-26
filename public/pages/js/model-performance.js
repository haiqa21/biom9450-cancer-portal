//Model performance: drwas the held out test charts with Chart.js.
//The number comes from ml/model mtrices.json which php puts in the page.
document.addEventListener('DOMContentLoaded', () => {

    //find the data block and stop if it isnt there 
    const dataBlock = document.getElementById('chart-test-data');
    if(!dataBlock){
        return;
    }
    const data = JSON.parse(dataBlock.textContent);

    //if chart.js didnt load stop. THe tables on the page still show every number.
    if(typeof Chart === 'undefined')
        console.error('Chart.js did not load');
    return;
}
//Site font and text colour for every chart
Chart.defaults.font.family = "'Inter', system-ui, sans-serif";
Chart.defaults.color = getComputedStyle(document.documentElement)
.getPropertyValue('--text-body').trim() || '#2B3A4A';

//colors for the 3 metrics and 2 models (checked for colour-blind safety)
const BLUE = '#0B7FA8', PURPLE = '#6C4BB4', ORANGE = '#C0601A';

    // A cancer type's colour, read from its CSS variable (e.g. --cancer-glioma)
    function colourFor(type) {
        const colour = getComputedStyle(document.documentElement)
            .getPropertyValue('--cancer-' + type.toLowerCase()).trim();
        return colour || '#9AA5B1';   // grey if the variable is missing
    }

    // Empty a chart slot, give it a height, and put a fresh <canvas> inside
    function makeCanvas(slotId, height) {
        const slot = document.getElementById(slotId);
        if (!slot) {
            return null;
        }
        slot.innerHTML = '';
        slot.style.position = 'relative';
        slot.style.height = height + 'px';
        const canvas = document.createElement('canvas');
        slot.appendChild(canvas);
        return canvas;
    }

    // ---------- Chart A: recall, precision and specificity per cancer type ----------
    const typeCanvas = makeCanvas('chart-test-types', 320);
    if (typeCanvas) {
        const rows = data.per_type;
        new Chart(typeCanvas, {
            type: 'bar',
            data: {
                labels: rows.map(r => r.cancer_type),
                datasets: [
                    { label: 'Recall (finds it)',      data: rows.map(r => r['recall (sensitivity)']), backgroundColor: BLUE },
                    { label: 'Precision (call is right)', data: rows.map(r => r.precision),            backgroundColor: PURPLE },
                    { label: 'Specificity (rules it out)', data: rows.map(r => r.specificity),         backgroundColor: ORANGE }
                ]
            },
            options: {
                maintainAspectRatio: false,
                datasets: { bar: { borderRadius: 4, borderColor: '#FFFFFF', borderWidth: 1 } },
                scales: { y: { min: 0, max: 1, title: { display: true, text: 'Score (0 to 1)' } } },
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: { callbacks: { label: item => item.dataset.label + ': ' + item.raw.toFixed(2) } }
                }
            }
        });
    }

    // ---------- Chart B: cross-validation, one line per model ----------
    const cvCanvas = makeCanvas('chart-test-cv', 260);
    if (cvCanvas) {
        const models = Object.keys(data.cross_validation);
        const colours = [BLUE, ORANGE];
        new Chart(cvCanvas, {
            type: 'line',
            data: {
                labels: data.cross_validation[models[0]].folds.map((_, i) => 'Fold ' + (i + 1)),
                datasets: models.map((name, i) => ({
                    label: name + ' (mean ' + data.cross_validation[name].mean_macro_f1.toFixed(2) + ')',
                    data: data.cross_validation[name].folds,
                    borderColor: colours[i % colours.length],
                    backgroundColor: colours[i % colours.length],
                    borderWidth: 2,
                    pointRadius: 5
                }))
            },
            options: {
                maintainAspectRatio: false,
                scales: { y: { min: 0.5, max: 1, title: { display: true, text: 'Macro F1' } } },
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: { callbacks: { label: item => item.dataset.label.split(' (')[0] + ': ' + item.raw.toFixed(2) } }
                }
            }
        });
    }

    // ---------- Chart C: ROC curve per cancer type ----------
    const rocCanvas = makeCanvas('chart-test-roc', 360);
    if (rocCanvas) {
        const types = Object.keys(data.roc);
        const datasets = types.map(type => ({
            label: type + ' (AUC ' + data.auc[type].toFixed(2) + ')',
            // ROC points: x = false positive rate, y = true positive rate
            data: data.roc[type].fpr.map((x, i) => ({ x: x, y: data.roc[type].tpr[i] })),
            borderColor: colourFor(type),
            backgroundColor: colourFor(type),
            borderWidth: 2,
            pointRadius: 0,
            stepped: true
        }));
        // Grey dashed diagonal = a model that guesses at random (AUC 0.5)
        datasets.push({
            label: 'Random guess (AUC 0.50)',
            data: [{ x: 0, y: 0 }, { x: 1, y: 1 }],
            borderColor: '#9AA5B1',
            borderDash: [6, 4],
            borderWidth: 1,
            pointRadius: 0
        });
        new Chart(rocCanvas, {
            type: 'line',
            data: { datasets: datasets },
            options: {
                maintainAspectRatio: false,
                parsing: false,
                scales: {
                    x: { type: 'linear', min: 0, max: 1, title: { display: true, text: 'False positive rate (1 − specificity)' } },
                    y: { min: 0, max: 1, title: { display: true, text: 'True positive rate (recall)' } }
                },
                plugins: {
                    legend: { position: 'right' },
                    tooltip: { enabled: false }
                }
            }
        });
    }
});
