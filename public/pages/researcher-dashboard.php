<?php
/*
 * Landing page for researchers after login. 
 *
 * Researchers only ever see DE-IDENTIFIED data: patient ID, age, sex, country,
 * diagnosis. Never a name, birthdate, contact detail or sample code.
 */

// ---------------------------------------------------------------------------
// Signed-in user
// ---------------------------------------------------------------------------
// TODO: delete this line and add a researcher-only check from database, e.g.
$currentUser = ['user_id' => 0, 'name' => 'Dr Placeholder', 'role' => 'researcher'];

// ---------------------------------------------------------------------------
// Settings read by includes/header.php
// ---------------------------------------------------------------------------
$pageTitle  = 'Dashboard';                     // browser tab: "Dashboard | CancerInsight"
$activePage = 'researcher-dashboard';          // highlights "Dashboard" in the side nav
$pageStyles = ['researcher-dashboard.css'];    // stylesheet

// ---------------------------------------------------------------------------
// Fixed lists
// ---------------------------------------------------------------------------

// The six cancer types in the one order every chart and legend uses.
// TODO: are there more types in the database? If so, add them here and to the legend in the gene chart.
$cancerTypes = ['Breast', 'Brain', 'Liver', 'Prostate', 'Blood', 'Pancreas'];

// The five prediction statuses in workflow order: value stored in
// predictions.status => label shown to people. Used by the pipeline chart and
// by the status chips in the tables.
$statuses = [
    'awaiting_diagnosis' => 'Awaiting diagnosis',
    'pending_review'     => 'Pending review',
    'reviewed'           => 'Reviewed',
    'report_draft'       => 'Report draft',
    'released'           => 'Released',
];

// Genes offered in the gene picker.
// TODO: replace with the most frequently mutated named genes from database
$geneOptions = ['TP53', 'KRAS', 'PIK3CA', 'BRCA1', 'BRCA2', 'EGFR', 'APC', 'PTEN', 'IDH1', 'JAK2'];

// ---------------------------------------------------------------------------
// Read the GET parameters (filters, picked genes, page numbers)
// ---------------------------------------------------------------------------

// Gene picker: keep only genes that are in the option list
// The picker form always sends genes[]="" so that unticking every gene is
// remembered as "none" instead of falling back to the default.
if (isset($_GET['genes'])) {
    $selectedGenes = array_values(array_intersect((array)$_GET['genes'], $geneOptions));
} else {
    $selectedGenes = array_slice($geneOptions, 0, 8);
}

// Patients table filters and page number
$patientSearch = trim($_GET['q'] ?? '');
$patientCancer = in_array($_GET['cancer'] ?? '', $cancerTypes, true) ? $_GET['cancer'] : '';
$patientPage   = max(1, (int)($_GET['ppage'] ?? 1));

// Mutation explorer filters and page number
$explorer = [
    'chromosome' => trim($_GET['chromosome'] ?? ''),
    'gene'       => trim($_GET['gene'] ?? ''),
    'from'       => trim($_GET['from'] ?? ''),
    'to'         => trim($_GET['to'] ?? ''),
    'type'       => trim($_GET['type'] ?? ''),
];
$mutationPage = max(1, (int)($_GET['mpage'] ?? 1));

// The related-patients panel only shows once a mutation row has been clicked
// (?mutation=<id>). Without it the table uses the full width.
$selectedMutation = (int)($_GET['mutation'] ?? 0);

// ---------------------------------------------------------------------------
// Small URL helpers
// ---------------------------------------------------------------------------
// Current URL with some parameters changed; null removes a parameter.
// (includes/dashboard-data.php has url_with(), but including that file also
// loads the data layer, which this placeholder page avoids.)
$urlWith = function (array $changes): string {
    $params = array_merge($_GET, $changes);
    $params = array_filter($params, fn($v) => $v !== null && $v !== '');
    return '?' . http_build_query($params);
};

// Hidden inputs that carry every current GET value except the ones a form
// sets itself, so submitting that form keeps the rest of the page's state.
$keepParams = function (array $except): string {
    $html = '';
    foreach ($_GET as $key => $value) {
        if (in_array($key, $except, true)) {
            continue;
        }
        foreach ((array)$value as $v) {
            $name = is_array($value) ? $key . '[]' : $key;
            $html .= '<input type="hidden" name="' . e($name) . '" value="' . e($v) . '">';
        }
    }
    return $html;
};

// Explorer filters that belong to the explorer form
$explorerKeys = ['chromosome', 'gene', 'from', 'to', 'type', 'mpage', 'mutation', 'patient'];

require __DIR__ . '/../../includes/header.php';
?>

<!-- ===================================================================
     1. Header
     =================================================================== -->
<div class="page-header">
    <h1>Welcome back, <?= e($currentUser['name']) ?></h1>
</div>

<!-- ===================================================================
     2. Stat tiles
     TODO: replace each "—" with a COUNT(*) from the matching query.
     =================================================================== -->
<div class="tiles">
    <a class="tile tile-link" href="#patients-heading">
        <span class="tile-value">—</span>
        <span class="tile-label">Consenting patients</span>
    </a>
    <a class="tile tile-link" href="#explorer-heading">
        <span class="tile-value">—</span>
        <span class="tile-label">Samples</span>
    </a>
    <a class="tile tile-link" href="research-predictions.php?status=pending_review">
        <span class="tile-value">—</span>
        <span class="tile-label">Awaiting review</span>
    </a>
    <a class="tile tile-link" href="research-predictions.php?status=released">
        <span class="tile-value">—</span>
        <span class="tile-label">Reports released</span>
    </a>
    <a class="tile tile-link" href="model-performance.php">
        <span class="tile-value">—</span>
        <span class="tile-label">Doctor and model agree</span>
    </a>
</div>

<!-- ===================================================================
     3 + 4. Two charts side by side
     =================================================================== -->
<div class="grid-2">

    <!-- 3. Workflow pipeline: how many predictions sit at each status -->
    <section class="card" aria-labelledby="pipeline-heading">
        <div class="card-header">
            <h2 id="pipeline-heading">Prediction Timeline</h2>
        </div>

        <div id="chart-pipeline" class="chart-slot" role="img"
             aria-label="Number of predictions at each workflow status">
            <!-- Fallback: one row per status, in workflow order -->
            <ol class="pipeline-list">
                <?php foreach ($statuses as $value => $label): ?>
                <li>
                    <!-- Each status links to the predictions list filtered to it -->
                    <a href="research-predictions.php?status=<?= e($value) ?>">
                        <span class="chip status-<?= e($value) ?>"><?= e($label) ?></span>
                    </a>
                    <span class="pipeline-count num">—</span>
                </li>
                <?php endforeach; ?>
            </ol>
        </div>
        <script type="application/json" id="chart-pipeline-data"><?= json_encode(
            ['stages' => array_map(
                fn($value, $label) => ['status' => $value, 'label' => $label, 'count' => 0],
                array_keys($statuses), $statuses
            )],
            JSON_HEX_TAG | JSON_HEX_AMP
        ) ?></script>
    </section>

    <!-- 4. Diagnosis accuracy by cancer type for consenting released reports only. -->
    <section class="card" aria-labelledby="accuracy-heading">
        <div class="card-header">
            <h2 id="accuracy-heading">Prediction Report Agreeance</h2>
            <span class="muted small-text">Released reports only</span>
        </div>

        <div id="chart-type-accuracy" class="chart-slot" role="img"
             aria-label="For each cancer type, how often the doctor's initial diagnosis and the model's top prediction matched the final report">
            <!-- Fallback table -->
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">Cancer type</th>
                            <th scope="col">Doctor</th>
                            <th scope="col">Model</th>
                            <th scope="col">Reports</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cancerTypes as $c): ?>
                        <tr>
                            <td>
                                <span class="cancer-name">
                                    <span class="dot dot-<?= e(strtolower($c)) ?>" aria-hidden="true"></span><?= e($c) ?>
                                </span>
                            </td>
                            <td class="num">—</td>
                            <td class="num">—</td>
                            <td class="num">—</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <script type="application/json" id="chart-type-accuracy-data"><?= json_encode(
            ['types' => array_map(
                fn($c) => ['type' => $c, 'model_pct' => null, 'doctor_pct' => null, 'n' => 0],
                $cancerTypes
            )],
            JSON_HEX_TAG | JSON_HEX_AMP
        ) ?></script>
    </section>
</div>

<!-- ===================================================================
     5. Mutations in selected genes
     Pick genes -> "Update chart" reloads the page with ?genes[]=...
     =================================================================== -->
<section class="card" aria-labelledby="genes-heading">
    <div class="card-header">
        <h2 id="genes-heading">Mutations in selected genes</h2>
    </div>

    <form method="get" action="#genes-heading">
        <!-- keep the patients table and explorer as they were -->
        <?= $keepParams(['genes']) ?>
        <!-- always sent, so "no genes ticked" is different from "first visit" -->
        <input type="hidden" name="genes[]" value="">

        <fieldset class="gene-picker">
            <legend>Genes</legend>
            <?php foreach ($geneOptions as $g): ?>
            <label>
                <input type="checkbox" name="genes[]" value="<?= e($g) ?>"
                       <?= in_array($g, $selectedGenes, true) ? 'checked' : '' ?>>
                <?= e($g) ?>
            </label>
            <?php endforeach; ?>
        </fieldset>

        <div class="gene-picker-actions">
            <button type="submit" class="btn btn-primary">Update chart</button>
        </div>
    </form>

    <!-- Legend: decorative because the fallback rows and the chart's own
         tooltips name each cancer type too -->
    <div class="legend" aria-hidden="true">
        <?php foreach ($cancerTypes as $c): ?>
        <span><span class="dot dot-<?= e(strtolower($c)) ?>"></span><?= e($c) ?></span>
        <?php endforeach; ?>
    </div>

    <div id="chart-genes" class="chart-slot" role="img"
         aria-label="Number of patients with a mutation in each selected gene, split by cancer type">
        <?php if ($selectedGenes): ?>
        <!-- Fallback: one empty bar per picked gene.
             TODO: one coloured segment per cancer type, width = count / largest total. -->
        <div class="gene-bars">
            <?php foreach ($selectedGenes as $g): ?>
            <div class="gene-bar">
                <span class="gene"><?= e($g) ?></span>
                <span class="track"></span>
                <span class="total num">—</span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p class="muted">Select at least one gene.</p>
        <?php endif; ?>
    </div>
    <script type="application/json" id="chart-genes-data"><?= json_encode(
        [
            'cancer_types' => $cancerTypes,
            // gene => [cancer type => patient count]
            'genes' => array_fill_keys($selectedGenes, array_fill_keys($cancerTypes, 0)),
        ],
        JSON_HEX_TAG | JSON_HEX_AMP
    ) ?></script>
</section>

<!-- ===================================================================
     6. Recent activity: the last 10 reviews and released reports.
     Every row opens that prediction's comparison page.
     TODO: replace the dummy rows with a foreach over the query,
     newest first, LIMIT 10. Empty state: "No reviews or reports yet."
     =================================================================== -->
<section class="card" aria-labelledby="activity-heading">
    <div class="card-header">
        <h2 id="activity-heading">Recent activity</h2>
        <a class="small-text" href="research-predictions.php">All predictions</a>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th scope="col">Date</th>
                    <th scope="col">Patient</th>
                    <th scope="col">Doctor</th>
                    <th scope="col">Event</th>
                    <th scope="col"><span class="visually-hidden">Open</span></th>
                </tr>
            </thead>
            <tbody>
                <?php for ($i = 0; $i < 3; $i++): ?>
                <tr>
                    <td class="num">—</td>
                    <td><a class="row-link" href="research-patient.php?id=1">Patient —</a></td>
                    <td><a href="research-doctor.php?id=0">Dr —</a></td>
                    <!-- event is either "Reviewed" or "Report released" -->
                    <td><span class="chip status-<?= $i === 1 ? 'released' : 'reviewed' ?>"><?= $i === 1 ? 'Report released' : 'Reviewed' ?></span></td>
                    <td><a href="research-prediction.php?id=0">Compare</a></td>
                </tr>
                <?php endfor; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- ===================================================================
     7. Patients (consenting only, de-identified), 20 per page
     =================================================================== -->
<section class="card" aria-labelledby="patients-heading">
    <div class="card-header">
        <h2 id="patients-heading">Patients</h2>
        <span class="muted small-text num">— consenting patients</span>
    </div>

    <form class="filters patient-filters" method="get" action="#patients-heading">
        <?= $keepParams(['q', 'cancer', 'ppage']) ?>
        <div class="field">
            <label for="q">Patient ID</label>
            <input id="q" name="q" inputmode="numeric" value="<?= e($patientSearch) ?>" placeholder="e.g. 48">
        </div>
        <div class="field">
            <label for="cancer">Cancer type</label>
            <select id="cancer" name="cancer">
                <option value="">All</option>
                <?php foreach ($cancerTypes as $c): ?>
                <option <?= $c === $patientCancer ? 'selected' : '' ?>><?= e($c) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Filter</button>
        <a class="btn" href="<?= e($urlWith(['q' => null, 'cancer' => null, 'ppage' => null])) ?>#patients-heading">Clear</a>
    </form>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th scope="col">Patient</th>
                    <th scope="col">Age</th>
                    <th scope="col">Sex</th>
                    <th scope="col">Cancer type</th>
                    <th scope="col">Stage</th>
                    <th scope="col">Status</th>
                    <th scope="col">Mutations</th>
                    <th scope="col">Latest prediction</th>
                </tr>
            </thead>
            <tbody>
                <!-- TODO: foreach over 20 rows from research_patients (consenting only).
                     Empty state: <tr><td colspan="8" class="empty">No patients match these filters.</td></tr> -->
                <?php for ($i = 0; $i < 4; $i++): ?>
                <tr>
                    <td><a class="row-link" href="research-patient.php?id=1">Patient —</a></td>
                    <td class="num">—</td>
                    <td>—</td>
                    <td>—</td>
                    <td>—</td>
                    <td>—</td>
                    <td class="num">—</td>
                    <td>—</td>
                </tr>
                <?php endfor; ?>
            </tbody>
        </table>
    </div>

    <!-- Pager: Previous/Next change ?ppage and keep every other filter -->
    <nav class="pager" aria-label="Patient pages">
        <span class="muted num">— of — patients</span>
        <span class="pager-links">
            <?php if ($patientPage > 1): ?>
            <a class="btn" href="<?= e($urlWith(['ppage' => $patientPage - 1])) ?>#patients-heading">Previous</a>
            <?php endif; ?>
            <a class="btn" href="<?= e($urlWith(['ppage' => $patientPage + 1])) ?>#patients-heading">Next</a>
        </span>
    </nav>
</section>

<!-- ===================================================================
     8. Mutation explorer
     Filter patient mutations; click one to see which other patients share
     that exact mutation, or any mutation in the same gene.
     =================================================================== -->
<section class="card" aria-labelledby="explorer-heading">
    <div class="card-header">
        <h2 id="explorer-heading">Mutation explorer</h2>
        <span class="muted small-text num">— matching patient mutations</span>
    </div>

    <form class="filters explorer-filters" method="get" action="#explorer-heading">
        <?= $keepParams($explorerKeys) ?>
        <div class="field">
            <label for="chromosome">Chromosome</label>
            <select id="chromosome" name="chromosome">
                <option value="">All</option>
                <!-- 1-22, then X, Y, MT (the order chromosomes are read in) -->
                <?php foreach (array_merge(array_map('strval', range(1, 22)), ['X', 'Y', 'MT']) as $c): ?>
                <option <?= $c === $explorer['chromosome'] ? 'selected' : '' ?>><?= e($c) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="gene">Gene</label>
            <input id="gene" name="gene" value="<?= e($explorer['gene']) ?>" placeholder="e.g. TP53">
        </div>
        <div class="field">
            <label for="from">Position from</label>
            <input id="from" name="from" inputmode="numeric" value="<?= e($explorer['from']) ?>">
        </div>
        <div class="field">
            <label for="to">to</label>
            <input id="to" name="to" inputmode="numeric" value="<?= e($explorer['to']) ?>">
        </div>
        <div class="field">
            <label for="type">Mutation type</label>
            <select id="type" name="type">
                <option value="">Any</option>
                <!-- TODO: SELECT DISTINCT mutation_type FROM mutations -->
                <?php foreach (['missense', 'nonsense', 'frameshift', 'synonymous', 'intron'] as $t): ?>
                <option value="<?= e($t) ?>" <?= $t === $explorer['type'] ? 'selected' : '' ?>><?= e(ucfirst($t)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Filter</button>
        <a class="btn" href="<?= e($urlWith(array_fill_keys($explorerKeys, null))) ?>#explorer-heading">Clear</a>
    </form>

    <!-- Two columns when a mutation is selected (table + related panel),
         one full-width column otherwise -->
    <div class="explorer<?= $selectedMutation ? '' : ' explorer-full' ?>">
        <div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">Mutation</th>
                            <th scope="col">Gene</th>
                            <th scope="col">Type</th>
                            <th scope="col">Allele frequency</th>
                            <th scope="col">Patient</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- TODO: foreach over 10 rows. Mutation text is "chr17:7,577,120 C>T".
                             Clicking it sets ?mutation=<mutation_id>&patient=<patient_id>.
                             Empty state: <tr><td colspan="5" class="empty">No mutations match these filters.</td></tr> -->
                        <?php for ($i = 1; $i <= 4; $i++): ?>
                        <tr class="<?= $i === $selectedMutation ? 'selected' : '' ?>">
                            <td>
                                <a class="mono row-link" href="<?= e($urlWith(['mutation' => $i, 'patient' => 0])) ?>#explorer-heading"
                                   <?= $i === $selectedMutation ? 'aria-current="true"' : '' ?>>chr— : — &gt; —</a>
                            </td>
                            <td><span class="gene">—</span></td>
                            <td>—</td>
                            <td class="num">—</td>
                            <td><a href="research-patient.php?id=1">Patient —</a></td>
                        </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>

            <nav class="pager" aria-label="Mutation pages">
                <span class="muted num">— of — mutations</span>
                <span class="pager-links">
                    <?php if ($mutationPage > 1): ?>
                    <a class="btn" href="<?= e($urlWith(['mpage' => $mutationPage - 1])) ?>#explorer-heading">Previous</a>
                    <?php endif; ?>
                    <a class="btn" href="<?= e($urlWith(['mpage' => $mutationPage + 1])) ?>#explorer-heading">Next</a>
                </span>
            </nav>
        </div>

        <?php if ($selectedMutation): ?>
        <!-- Related patients for the clicked mutation. Shows patient IDs only,
             never names (researchers see de-identified data). -->
        <aside class="related" aria-label="Related patients">
            <h3 class="mono">chr— : — &gt; —</h3>
            <p class="muted small-text"><span class="gene">—</span> · —</p>

            <h3>Same mutation (—)</h3>
            <ul class="people">
                <!-- TODO: other patients with this exact mutation, first 8 -->
                <li>
                    <a href="research-patient.php?id=1">Patient —</a>
                    <span class="muted small-text">— · —</span>
                    <span class="chip">—</span>
                </li>
            </ul>

            <h3>Same gene (—)</h3>
            <ul class="people">
                <!-- TODO: other patients with any mutation in this gene, first 8 -->
                <li>
                    <a href="research-patient.php?id=1">Patient —</a>
                    <span class="muted small-text">— · —</span>
                    <span class="chip">—</span>
                </li>
            </ul>

            <a class="btn" href="<?= e($urlWith(['mutation' => null, 'patient' => null])) ?>#explorer-heading">Close</a>
        </aside>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
