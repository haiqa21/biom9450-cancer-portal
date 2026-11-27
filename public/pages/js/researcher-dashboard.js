

//Resercher dashboard: draws the charts using Chrt.js 
document.addEventListener('DOMContentLoaded', () => {

//Find the charts data on the page and stop if its isnt there 
const dataBlock = document.getElementById('chart-genes-data');
if (!dataBlock){
    return;
}

//Turn the text into a Jaxa script Object
const data = JSON.parse(dataBlock.textContent);


//Stop if no genes are ticked (the page shows "Select at least one gene.")
const genes = Object.keys(data.genes);
if (genes.length === 0) {
    return;
}

// Stop if no patients have mutations in these genes (all counts are 0)
const totalPatients = genes.reduce(
    (sum, gene) => sum + Object.values(data.genes[gene]).reduce((a, b) => a + b, 0), 0);
if (totalPatients === 0) {
    document.getElementById('chart-genes').textContent = 'No mutations are recorded for these genes yet.';
    return;
}

// If Chart.js didn't load, stop here and keep the fallback bars
if (typeof Chart === 'undefined') {
    console.error('Chart.js did not load');
    return;
}

//Swap the fallbacks bars for a canvas 
//height grows with the number of gene, so every gene name has room.
const slot = document.getElementById('chart-genes');
slot.style.position = 'relative';
slot.style.height = (genes.length * 44 + 60) + 'px';


slot.innerHTML = '';// remove the fallback bars
const canvas = document.createElement('canvas');  // make a new <canvas>
slot.appendChild(canvas);// put it inside the chart box

// Use the site's font and text colour for every chart
const textColour = getComputedStyle(document.documentElement).getPropertyValue('--text-body').trim() || '#2B3A4A';
Chart.defaults.font.family = "'Inter', system-ui, sans-serif";
Chart.defaults.color = textColour;

// Keep chart colors in hex so Safari and Chart.js handle them consistently.
//Same values as the cancer colours in app.css; changed both
const cancerColours = {
    breast: '#2A78D6',
    colorectal: '#eb6834',
    melanoma: '#1baf7a',
    glioma: '#eda100',
    thyroid: '#e87ba4',
    kidney: '#008300'
};

// One colored piece per cancer type, matching the site's legend palette.
function colourFor(type) {
    return cancerColours[type.toLowerCase()] || '#9AA5B1';
}

// One coloured piece per cancer type
const datasets = data.cancer_types.map(type => ({
    label: type,
    data: genes.map(gene => data.genes[gene][type]),
    backgroundColor: colourFor(type),
    borderColor: '#FFFFFF',   // thin white gap between the pieces
    borderWidth: 1
}));

const chart = new Chart(canvas, {
    type: 'bar',
    data: { labels: genes, datasets: datasets },
    options: {
        indexAxis: 'y',                 // horizontal bars (gene names are easier to read)
        maintainAspectRatio: false,     // use the height set above instead of a fixed shape
        plugins: {
            legend: { display: false },   // the page already has its own legend
            tooltip: {
                mode: 'index',
                callbacks: {
                    label: (item) => `${item.dataset.label}: ${item.raw} patients`,
                    footer: (items) => `Total: ${items.reduce((sum, item) => sum + item.raw, 0)} patients`
                }
            }
        },
        scales: {
            x: { stacked: true, title: { display: true, text: 'Number of patients' } },
            y: { stacked: true }        // stacked = pieces sit end to end in one bar
        }
    }
});
    // Cancer-type checkboxes: show or hide each type's piece of the bars
const typeBoxes = document.querySelectorAll('.type-toggle');
const emptyMessage = document.getElementById('type-empty');
typeBoxes.forEach(box => {
    box.addEventListener('change', () => {
        const index = data.cancer_types.indexOf(box.value);   // which dataset this box controls
        chart.setDatasetVisibility(index, box.checked);
        chart.update();
        // Show a message if every box is unticked
        const anyTicked = Array.from(typeBoxes).some(b => b.checked);
        emptyMessage.hidden = anyTicked;
    });
});
});