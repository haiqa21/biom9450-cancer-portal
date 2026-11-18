<?php
/*
 * Patient record (research-patient.php?id=<patient_id>)
 * De-identification rules:
 *   - no name, birthdate, contact details or sample code (sample codes can
 *     contain the patient code or a full date)
 *   - samples are shown as "Sample 1, Mar 2026", numbered in date order
 *   - every date is month + year only (format_month())
 */

require_once __DIR__ . '/../../includes/helpers.php';   // e(), mutation_impact(), query_with(), hidden_query_inputs()

// ---------------------------------------------------------------------------
// Signed-in user
// ---------------------------------------------------------------------------
// TODO: delete this line and add a researcher-only check from the database.
$currentUser = ['user_id' => 0, 'name' => 'Dr Placeholder', 'role' => 'researcher'];

// ---------------------------------------------------------------------------
// Settings read by includes/header.php
// ---------------------------------------------------------------------------
$pageTitle  = 'Patient record';
$activePage = 'researcher-dashboard';         // no "Patients" nav item, so keep Dashboard highlighted
$pageStyles = ['research-patient.css'];

const MUTATIONS_PER_PAGE = 20;

// ---------------------------------------------------------------------------
// Which patient?
// ---------------------------------------------------------------------------
// (int) turns anything that isn't a number into 0, so "?id=abc" and a
// missing id are both rejected.
$patientId = (int)($_GET['id'] ?? 0);

// TODO: SELECT patient_id, age, sex, country FROM research_patients WHERE patient_id = ?
// research_patients only holds CONSENTING patients, so a patient who has not
// consented (or withdrew) gets no row and lands in "not available", exactly
// like an id that doesn't exist.
$patientFound = $patientId > 0;

$fromDoctor = (int)($_GET['doctor'] ?? 0);

// ---------------------------------------------------------------------------
// Fixed lists
// ---------------------------------------------------------------------------

// Impact levels, most serious first. Keys match mutation_impact() in
// helpers.php and the impact-* chip colours in app.css.
$impactLevels = [
    'high'     => 'High',
    'moderate' => 'Moderate',
    'low'      => 'Low',
    'modifier' => 'Modifier',
];

// Prediction statuses: value in predictions.status => label (same as the dashboard)
$statuses = [
    'awaiting_diagnosis' => 'Awaiting diagnosis',
    'pending_review'     => 'Pending review',
    'reviewed'           => 'Reviewed',
    'report_draft'       => 'Report draft',
    'released'           => 'Released',
];

// Genes this patient has mutations in, for the gene filter.
// TODO: SELECT DISTINCT g.gene_symbol FROM samples s JOIN patient_mutations ...
//       WHERE s.patient_id = ? ORDER BY g.gene_symbol
$patientGenes = [];

// ---------------------------------------------------------------------------
// Read the GET parameters (mutation filters and page)
// ---------------------------------------------------------------------------
// Only values from the lists above are accepted; anything else is ignored.
$impact = array_key_exists($_GET['impact'] ?? '', $impactLevels) ? $_GET['impact'] : '';
$gene   = in_array($_GET['gene'] ?? '', $patientGenes, true) ? $_GET['gene'] : '';
$mutationPage = max(1, (int)($_GET['mpage'] ?? 1));

// ---------------------------------------------------------------------------
// Not available: show a short message instead of the page
// ---------------------------------------------------------------------------
// Same message for "doesn't exist", "not consenting" and "bad id", so the page
// never reveals which patients exist or who declined research use.
if (!$patientFound) {
    http_response_code(404);
    $pageTitle = 'Patient not available';
    require __DIR__ . '/../../includes/header.php';
    ?>
    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="researcher-dashboard.php">Dashboard</a></nav>
    <section class="card not-available">
        <h1>This patient isn't available</h1>
        <p class="muted">The link may be wrong, or the patient's data isn't available for research.</p>
        <a class="btn" href="researcher-dashboard.php#patients-heading">Back to patients</a>
    </section>
    <?php
    require __DIR__ . '/../../includes/footer.php';
    exit();
}

// TODO: record the view: INSERT INTO audit_logs (... 'view_patient_record', 'patients', $patientId ...)

require __DIR__ . '/../../includes/header.php';
?>

<!-- ===================================================================
     1. Header
     =================================================================== -->
<nav class="breadcrumb" aria-label="Breadcrumb">
    <?php if ($fromDoctor): ?>
    <a href="research-doctors.php">Doctors</a> ›
    <a href="research-doctor.php?id=<?= $fromDoctor ?>">Dr —</a> ›
    <?php else: ?>
    <a href="researcher-dashboard.php">Dashboard</a> ›
    <?php endif; ?>
    Patient <?= $patientId ?>
</nav>

<div class="page-header">
    <div class="patient-heading">
        <h1>Patient <?= $patientId ?></h1>
        <!-- TODO: age from research_patients; cancer type, stage, status from the latest diagnosis.
             Cancer type chip: <span class="chip"><span class="dot dot-breast"></span>Breast</span> -->
        <div class="patient-chips">
            <span class="chip">Age —</span>
            <span class="chip">Sex —</span>
            <span class="chip">Cancer type —</span>
            <span class="chip">Stage —</span>
            <span class="chip">Status —</span>
        </div>
    </div>
</div>

<!-- ===================================================================
     2. Care team
     TODO: care_assignments for this patient where end_date IS NULL,
     primary first. Each name links to Doctor detail.
     =================================================================== -->
<p class="care-team">
    <span class="muted">Care team:</span>
    <a href="research-doctor.php?id=1">Dr —</a> <span class="muted">(primary)</span>,
    <a href="research-doctor.php?id=1">Dr —</a> <span class="muted">(referred)</span>
</p>

<!-- ===================================================================
     3. Journey
     Every event for this patient in time order. The chart places events in
     DAYS SINCE THE FIRST DIAGNOSIS (Day 0, Day 412 ...), so no real dates
     appear; hovering shows month + year.
     =================================================================== -->
<section class="card" aria-labelledby="journey-heading">
    <div class="card-header">
        <h2 id="journey-heading">Journey</h2>
    </div>

    <div id="chart-patient-timeline" class="chart-slot" role="img"
         aria-label="Timeline of this patient's diagnosis, samples, model runs, reviews and reports">
        <!-- Fallback: the events as an ordered list.
             TODO: build from patient_diagnoses, samples, clinical_assessments,
             predictions, feedback (reviews) and reports, sorted by date. -->
        <ol class="journey-list">
            <li><span class="journey-day num">Day 0</span><span>Diagnosis recorded</span><span class="muted">—</span></li>
            <li><span class="journey-day num">Day —</span><span>Sample tested</span><span class="muted">Sample 1 · —</span></li>
            <li><span class="journey-day num">Day —</span><span>Initial diagnosis</span><span class="muted">Dr —</span></li>
            <li><span class="journey-day num">Day —</span><span>Model run</span><span class="muted">—</span></li>
            <li><span class="journey-day num">Day —</span><span>Review</span><span class="muted">Dr —</span></li>
            <li><span class="journey-day num">Day —</span><span>Report released</span><span class="muted">—</span></li>
        </ol>
    </div>
    <!-- Shape: {"events":[{"day":0,"type":"diagnosis","label":"Breast diagnosed","month":"Mar 2026","lane":0}, ...]}
         type is one of: diagnosis, sample, assessment, model, review, report.
         Events that belong to the same prediction share a lane. -->
    <script type="application/json" id="chart-patient-timeline-data"><?= json_encode(
        ['events' => []],
        JSON_HEX_TAG | JSON_HEX_AMP
    ) ?></script>
</section>

<!-- ===================================================================
     4 + 5. Diagnoses and mutation summary side by side
     =================================================================== -->
<div class="grid-2">

    <!-- 4. Diagnoses, newest first -->
    <section class="card" aria-labelledby="diagnoses-heading">
        <div class="card-header">
            <h2 id="diagnoses-heading">Diagnoses</h2>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th scope="col">Cancer type</th>
                        <th scope="col">Diagnosed</th>
                        <th scope="col">Stage</th>
                        <th scope="col">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- TODO: foreach over patient_diagnoses ORDER BY date_of_diagnosis DESC.
                         "Diagnosed" through format_month(), e.g. "Mar 2026". -->
                    <?php for ($i = 0; $i < 2; $i++): ?>
                    <tr>
                        <td>—</td>
                        <td>—</td>
                        <td>—</td>
                        <td>—</td>
                    </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- 5. Mutation counts by impact. Each row/bar links to
         the mutations table filtered to that impact level. -->
    <section class="card" aria-labelledby="impact-heading">
        <div class="card-header">
            <h2 id="impact-heading">Mutations by impact</h2>
        </div>

        <div id="chart-patient-impact" class="chart-slot" role="img"
             aria-label="Number of this patient's mutations at each impact level">
            <!-- Fallback: impact chip, empty bar, count.
                 TODO: bar width = count / largest count. -->
            <ul class="impact-bars">
                <?php foreach ($impactLevels as $level => $label): ?>
                <li>
                    <a href="<?= e(query_with(['impact' => $level, 'mpage' => null])) ?>#mutations-heading">
                        <span class="chip impact-<?= e($level) ?>"><?= e($label) ?></span>
                    </a>
                    <span class="impact-track"></span>
                    <span class="num impact-count">—</span>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <script type="application/json" id="chart-patient-impact-data"><?= json_encode(
            ['impacts' => array_map(
                fn($level) => ['level' => $level, 'count' => 0],
                array_keys($impactLevels)
            )],
            JSON_HEX_TAG | JSON_HEX_AMP
        ) ?></script>
    </section>
</div>

<!-- ===================================================================
     6. Mutations, 20 per page, filter by impact and gene
     =================================================================== -->
<section class="card" aria-labelledby="mutations-heading">
    <div class="card-header">
        <h2 id="mutations-heading">Mutations</h2>
        <span class="muted small-text num">— mutations</span>
    </div>

    <form class="filters mutation-filters" method="get" action="#mutations-heading">
        <!-- keeps ?id (and ?doctor) so the filter stays on this patient -->
        <?= hidden_query_inputs(['impact', 'gene', 'mpage']) ?>
        <div class="field">
            <label for="impact">Impact</label>
            <select id="impact" name="impact">
                <option value="">All</option>
                <?php foreach ($impactLevels as $level => $label): ?>
                <option value="<?= e($level) ?>" <?= $level === $impact ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="gene">Gene</label>
            <select id="gene" name="gene">
                <option value="">All</option>
                <?php foreach ($patientGenes as $g): ?>
                <option <?= $g === $gene ? 'selected' : '' ?>><?= e($g) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Filter</button>
        <a class="btn" href="<?= e(query_with(['impact' => null, 'gene' => null, 'mpage' => null])) ?>#mutations-heading">Clear</a>
    </form>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th scope="col">Gene</th>
                    <th scope="col">Chromosome</th>
                    <th scope="col">Position</th>
                    <th scope="col">Change</th>
                    <th scope="col">Type</th>
                    <th scope="col">Allele frequency</th>
                    <th scope="col">Sample</th>
                </tr>
            </thead>
            <tbody>
                <!-- TODO: foreach over MUTATIONS_PER_PAGE rows, most serious impact first.
                     Gene: named genes in <span class="gene">, Ensembl IDs in <span class="mono"> (is_named_gene()).
                     Type: <span class="chip impact-{mutation_impact(type)}">Missense</span>.
                     Sample: "Sample 1 · Mar 2026", never sample_code.
                     Empty state: <tr><td colspan="7" class="empty">No mutations match these filters.</td></tr>
                     No samples at all: "No samples have been tested for this patient." -->
                <?php for ($i = 0; $i < 4; $i++): ?>
                <tr>
                    <td><span class="gene">—</span></td>
                    <td>—</td>
                    <td class="num">—</td>
                    <td class="mono change"><span class="ref">—</span> <span aria-label="changed to">→</span> <span class="alt">—</span></td>
                    <td>—</td>
                    <td class="num">—</td>
                    <td>Sample — · —</td>
                </tr>
                <?php endfor; ?>
            </tbody>
        </table>
    </div>

    <!-- TODO: real totals; show Next only when mpage < ceil(total / MUTATIONS_PER_PAGE) -->
    <nav class="pager" aria-label="Mutation pages">
        <span class="muted num">— of — mutations</span>
        <span class="pager-links">
            <?php if ($mutationPage > 1): ?>
            <a class="btn" href="<?= e(query_with(['mpage' => $mutationPage - 1])) ?>#mutations-heading">Previous</a>
            <?php endif; ?>
            <a class="btn" href="<?= e(query_with(['mpage' => $mutationPage + 1])) ?>#mutations-heading">Next</a>
        </span>
    </nav>
</section>

<!-- ===================================================================
     7. Predictions, newest first
     A prediction still awaiting the oncologist's diagnosis has no model
     result to compare yet, so its row says so and has no "Compare" link.
     =================================================================== -->
<section class="card" aria-labelledby="predictions-heading">
    <div class="card-header">
        <h2 id="predictions-heading">Predictions</h2>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th scope="col">Sample</th>
                    <th scope="col">Run</th>
                    <th scope="col">Initial diagnosis</th>
                    <th scope="col">Model's prediction</th>
                    <th scope="col">Confidence</th>
                    <th scope="col">Status</th>
                    <th scope="col">Final report</th>
                    <th scope="col"><span class="visually-hidden">Open</span></th>
                </tr>
            </thead>
            <tbody>
                <!-- TODO: foreach over this patient's predictions ORDER BY created_at DESC.
                     "Final report" stays "—" until the report is released (drafts are never shown).
                     Empty state: <tr><td colspan="8" class="empty">No predictions yet.</td></tr> -->
                <?php foreach (['released', 'pending_review'] as $placeholderStatus): ?>
                <tr>
                    <td>Sample —</td>
                    <td>—</td>
                    <td>—</td>
                    <td>—</td>
                    <td class="num">—</td>
                    <td><span class="chip status-<?= $placeholderStatus ?>"><?= e($statuses[$placeholderStatus]) ?></span></td>
                    <td>—</td>
                    <td><a href="research-prediction.php?id=0">Compare</a></td>
                </tr>
                <?php endforeach; ?>
                <!-- Awaiting diagnosis: no model result yet, so nothing to compare -->
                <tr>
                    <td>Sample —</td>
                    <td>—</td>
                    <td>—</td>
                    <td colspan="2" class="muted">Waiting for the oncologist's diagnosis</td>
                    <td><span class="chip status-awaiting_diagnosis"><?= e($statuses['awaiting_diagnosis']) ?></span></td>
                    <td>—</td>
                    <td></td>
                </tr>
            </tbody>
        </table>
    </div>
</section>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
