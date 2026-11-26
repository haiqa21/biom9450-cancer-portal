//Prediction charts: the model's probability for each cancer type, and the 
//genes behind its call.Used on the resercher comparison page and the clinician view.
//The numbers come from data blocks that PHP puts in the page;
//the tables in the same cards show every number if this script can't run.
document.addEventListener('DOMContentLoaded', () => {
    if (typeof Chart === 'undefined'){
        console.error('Chart.js did not load');
        return;
    }
    Chart.defaults.font.family = "Inter', system-ui, sans-serif";
    Chart.defaults.color = getComputedStyle(document.documentElement)
        .getPropertyValue('--text-body').trim() || '#2B3A4A';


        // a cancer type's color from the css variable 
        function colourFor(type) {
        const colour = getComputedStyle(document.documentElement)
            .getPropertyValue('--cancer-' + type.toLowerCase()).trim();
        return colour || '#396797';
    }
    // Read a data block; null if it's missing 
    function readData(id){
        const block = document.getElementById(id);
        return block ? JSON.parse(block.textContent) : null;

    }
    // Empty a chart slot and put a fresh canvas in it
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


    // Chart 1: Probability for each cancer type 
    const probs = readData('chart-probabilities-data');
    if (probs && Object.values(probs.probabilities).some(v => v !==null)){
         // Highest first; add "(Doctor)" / "(Report)" to the matching names
        const types = Object.keys(probs.probabilities)
            .sort((a, b) => probs.probabilities[b] - probs.probabilities[a]);
        const labels = types.map(type => {
            const marks = [];
            if (type === probs.doctor) marks.push('Doctor');
            if (type === probs.report) marks.push('Report');
            return marks.length ? type + ' (' + marks.join(', ') + ')' : type;   
    });
    const threshold = probs.threshold ?? 60;

    //small plugin: a dashed line at the confidence threshold 
     const thresholdLine = {
            id: 'thresholdLine',
            afterDatasetsDraw(chart) {
                const x = chart.scales.x.getPixelForValue(threshold);
                const { top, bottom } = chart.chartArea;
                const ctx = chart.ctx;
                ctx.save();
                ctx.strokeStyle = '#5E6E7E';
                ctx.setLineDash([5, 4]);
                ctx.beginPath();
                ctx.moveTo(x, top);
                ctx.lineTo(x, bottom);
                ctx.stroke();
                ctx.setLineDash([]);
                ctx.fillStyle = '#5E6E7E';
                ctx.font = '12px Inter, system-ui, sans-serif';
                ctx.fillText('Confidence threshold ' + threshold + '%', x + 4, top + 12);
                ctx.restore();
            }
        };

         const canvas = makeCanvas('chart-probabilities', types.length * 40 + 60);
        if (canvas) {
            new Chart(canvas, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Probability (%)',
                        data: types.map(type => probs.probabilities[type]),
                        backgroundColor: types.map(colourFor),
                        borderRadius: 4
                    }]
                     },
                options: {
                    indexAxis: 'y',
                    maintainAspectRatio: false,
                    scales: { x: { min: 0, max: 100, title: { display: true, text: 'Probability (%)' } } },
                    plugins: {
                        legend: { display: false },
                        tooltip: { callbacks: { label: item => item.raw.toFixed(1) + '%' } }
                    }
                },
                plugins: [thresholdLine]
            });
        }
    }
           // Genes Behind the call 
    const genes = readData('chart-prediction-genes-data');
    if(genes && genes.genes.length){
        const rows = genes.genes;
        const canvas = makeCanvas('chart-prediction-genes'. rows.length * 30 + 80);
        if (canvas){
            new Chart(canvas, {
                type: 'bar',
                data: {
                    labels:rows.map(r => r.gene),
                    //Two datasets so the legend explains the two colours;
                    //each gene has a value in only one of them 
                    datasets:[
                        {label: 'Mutated in this patient',
                            data: rows.map(r => r.in_sample ? r.weight : null),
                            backgroundColor: 'blue',
                            borderRadius: 5

                        },
                        {
                            label: 'Not mutated in this patient',
                            data: rows.map(r => r.in_sample ? null : r.weight),
                            backgroundColor: 'green',
                            borderRadius: 4
                        }
                
                    ]
                },
                options: { 
                    indexAxis:'y',
                    maintainAspectRatio: false,
                    scales: {
                        x: {stacked: true, beginAtZero: true, title: {display: true, 
                            text: 'Weight towards' + genes.top_type}},
                            y:{ stacked: true }
                        },
                        plugins: {
                            legend: {position: 'bottom' },
                            tooltip: { callbacks: { label:item => item.datasets.label + '; weight ' + item.raw}}

                        }
                    }
                });
        }
    }
});
        