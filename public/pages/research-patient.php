<?php
/*
 * Patient record (research-patient.php?id=<patient_id>)
 * A read-only, DE-IDENTIFIED view of one patient for researchers.
 *
 *   1. Header             "Patient 48" + chips: age, sex, cancer type, stage, status
 *   2. Care team          oncologists who have run predictions for the patient
 *   3. Journey            diagnosis, predictions and reviews in order -> #chart-patient-timeline
 *   4. Diagnoses          cancer type, diagnosed (month + year), stage, status
 *   5. Mutations summary  counts by impact level                       -> #chart-patient-impact
 *   6. Mutations          20 per page, filter by impact and gene, most serious first
 *   7. Predictions        every model run for the patient, newest first
 *
 * De-identification: no name, birthdate, contact details or sample code (the TCGA
 * sample code identifies the donor). Samples show as "Sample 1", diagnosis dates as
 * month + year, and the journey counts days since the first diagnosis.
 */

require_once __DIR__ . '/../../includes/research.php';

$currentUser = research_require_login();       // also connects: $pdo

$pageTitle  = 'Patient record';
$activePage = 'researcher-dashboard';          // no "Patients" nav item, so keep Dashboard highlighted
$pageStyles = ['research-patient.css'];

const MUTATIONS_PER_PAGE = 20;

$types = cancer_types($pdo);

// Impact levels, most serious first. Keys match mutation_impact() in helpers.php
$impactLevels = ['high' => 'High', 'moderate' => 'Moderate', 'low' => 'Low', 'modifier' => 'Modifier'];

// ---------------------------------------------------------------------------
// Which patient?
// ---------------------------------------------------------------------------
$patientId = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT patient_id, birthdate, sex FROM patients WHERE patient_id = ?');
$stmt->execute([$patientId]);
$patient = $stmt->fetch();

// Breadcrumb: Doctor detail links here with &doctor=<user_id>
$fromDoctor = null;
if (ctype_digit($_GET['doctor'] ?? '')) {
    $stmt = $pdo->prepare("SELECT user_id, first_name, last_name FROM professionals
                           WHERE user_id = ? AND professional_type = 'oncologist'");
    $stmt->execute([(int)$_GET['doctor']]);
    $fromDoctor = $stmt->fetch() ?: null;
}

if (!$patient) {
    http_response_code(404);
    $pageTitle = 'Patient not available';
    require __DIR__ . '/../../includes/header.php';
    ?>
    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="researcher-dashboard.php">Dashboard</a></nav>
    <section class="card not-available">
        <h1>This patient isn't available</h1>
        <p class="muted">The link may be wrong, or the patient's record no longer exists.</p>
        <a class="btn" href="researcher-dashboard.php#patients-heading">Back to patients</a>
    </section>
    <?php
    require __DIR__ . '/../../includes/footer.php';
    exit();
}
$pageTitle = 'Patient ' . $patientId;

// ---------------------------------------------------------------------------
// Diagnoses (newest first) and care team
// ---------------------------------------------------------------------------
$stmt = $pdo->prepare('SELECT diagnosis_id, cancer_type_id, date_of_diagnosis, stage, status
                       FROM patient_diagnoses WHERE patient_id = ?
                       ORDER BY date_of_diagnosis DESC, diagnosis_id DESC');
$stmt->execute([$patientId]);
$diagnoses = $stmt->fetchAll();
$latestDx = $diagnoses[0] ?? null;

$stmt = $pdo->prepare("SELECT pr.user_id, pr.first_name, pr.last_name, COUNT(*) AS runs
                       FROM predictions p JOIN professionals pr ON pr.user_id = p.user_id
                       WHERE p.patient_id = ? GROUP BY pr.user_id, pr.first_name, pr.last_name
                       ORDER BY runs DESC, pr.last_name");
$stmt->execute([$patientId]);
$careTeam = $stmt->fetchAll();

// Samples, numbered in date order ("Sample 1"), never shown by sample_code
$stmt = $pdo->prepare('SELECT sample_id, date_detected FROM samples WHERE patient_id = ?
                       ORDER BY date_detected IS NULL, date_detected, sample_id');
$stmt->execute([$patientId]);
$sampleNumber = [];
foreach ($stmt->fetchAll() as $i => $s) {
    $sampleNumber[(int)$s['sample_id']] = $i + 1;
}
$sampleIds = array_keys($sampleNumber);

// ---------------------------------------------------------------------------
// Predictions and the reviews of them
// ---------------------------------------------------------------------------
$stmt = $pdo->prepare("SELECT pv.*, pr.first_name, pr.last_name
                       FROM (" . PREDICTION_SQL . " WHERE p.patient_id = ?) pv
                       JOIN professionals pr ON pr.user_id = pv.doctor_id
                       ORDER BY pv.created_at DESC");
$stmt->execute([$patientId]);
$predictions = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT f.created_at, f.feedback_rating, p.model_used
                       FROM feedback f JOIN predictions p ON p.prediction_id = f.prediction_id AND p.user_id = f.user_id
                       WHERE p.patient_id = ?");
$stmt->execute([$patientId]);
$reviews = $stmt->fetchAll();

// ---------------------------------------------------------------------------
// 3. Journey: days since the first diagnosis, so no real dates appear in the chart
// ---------------------------------------------------------------------------
$events = [];
foreach ($diagnoses as $d) {
    $events[] = ['date' => $d['date_of_diagnosis'], 'type' => 'diagnosis',
                 'label' => ($types[(int)$d['cancer_type_id']]['code'] ?? '?') . ' diagnosed'];
}
foreach ($predictions as $p) {
    $events[] = ['date' => $p['created_at'], 'type' => 'model', 'label' => model_label($p['model_used']) . ' run'];
}
foreach ($reviews as $r) {
    $events[] = ['date' => $r['created_at'], 'type' => 'review',
                 'label' => 'Reviewed (' . $r['feedback_rating'] . '/5, ' . model_label($r['model_used']) . ')'];
}
usort($events, fn($a, $b) => strcmp($a['date'], $b['date']));
$firstDay = $events ? new DateTime(substr($events[0]['date'], 0, 10)) : null;
foreach ($events as &$event) {
    $event['day'] = $firstDay->diff(new DateTime(substr($event['date'], 0, 10)))->days;
    $event['month'] = format_month($event['date']);
}
unset($event);

// ---------------------------------------------------------------------------
// 5 + 6. Mutations
// ---------------------------------------------------------------------------
$impactCounts = array_fill_keys(array_keys($impactLevels), 0);
$mutationTotal = 0;
$mutations = [];
$impact = array_key_exists($_GET['impact'] ?? '', $impactLevels) ? $_GET['impact'] : '';
$gene = trim($_GET['gene'] ?? '');

if ($sampleIds) {
    $in = implode(',', array_map('intval', $sampleIds));

    // counts by mutation type, folded into the 4 impact levels
    foreach ($pdo->query("SELECT m.mutation_type, COUNT(*) AS n FROM patient_mutations pm
                          JOIN mutations m ON m.mutation_id = pm.mutation_id
                          WHERE pm.sample_id IN ($in) GROUP BY m.mutation_type") as $row) {
        $impactCounts[mutation_impact($row['mutation_type'])] += (int)$row['n'];
    }

    $where = ["pm.sample_id IN ($in)"];
    $params = [];
    if ($impact !== '') {
        // the mutation types in that impact level
        $typesInLevel = array_values(array_filter(MUTATION_TYPES, fn($t) => mutation_impact($t) === $impact));
        $where[] = 'm.mutation_type IN (' . implode(',', array_fill(0, count($typesInLevel), '?')) . ')';
        array_push($params, ...$typesInLevel);
    }
    if ($gene !== '') {
        $where[] = 'g.gene_symbol = ?';
        $params[] = $gene;
    }
    $from = 'FROM patient_mutations pm
             JOIN mutations m ON m.mutation_id = pm.mutation_id
             JOIN genes g ON g.gene_id = m.gene_id
             WHERE ' . implode(' AND ', $where);

    // STRAIGHT_JOIN: start from this patient's links (primary key starts with sample_id).
    // Without it MySQL may scan all 719,000 variants first, which takes seconds for
    // heavily mutated patients.
    $stmt = $pdo->prepare("SELECT STRAIGHT_JOIN COUNT(*) $from");
    $stmt->execute($params);
    $mutationTotal = (int)$stmt->fetchColumn();
    $mutationPage = page_number('mpage', $mutationTotal, MUTATIONS_PER_PAGE);

    // most serious first: the order of MUTATION_TYPES, then gene, then position
    $typeOrder = implode(',', array_map(fn($t) => $pdo->quote($t), MUTATION_TYPES));
    $stmt = $pdo->prepare(
        "SELECT STRAIGHT_JOIN g.gene_symbol, g.chromosome, m.genomic_position, m.original_allele, m.altered_allele,
                m.mutation_type, pm.allele_frequency, pm.sample_id
         $from
         ORDER BY FIELD(m.mutation_type, $typeOrder) = 0, FIELD(m.mutation_type, $typeOrder), g.gene_symbol, m.genomic_position
         LIMIT " . MUTATIONS_PER_PAGE . ' OFFSET ' . (($mutationPage - 1) * MUTATIONS_PER_PAGE)
    );
    $stmt->execute($params);
    $mutations = $stmt->fetchAll();
} else {
    $mutationPage = 1;
}
$maxImpact = max($impactCounts) ?: 1;

// long alleles are shortened so the table stays readable (full text in the title)
$allele = fn(?string $a) => $a === null ? '…' : (strlen($a) > 12 ? substr($a, 0, 10) . '…' : $a);

audit($pdo, $currentUser['user_id'], 'view_patient_record', 'patients', $patientId);

require __DIR__ . '/../../includes/header.php';
?>

<!-- ===================================================================
     1. Header
     =================================================================== -->
<nav class="breadcrumb" aria-label="Breadcrumb">
    <?php if ($fromDoctor): ?>
    <a href="research-doctors.php">Doctors</a> ›
    <a href="research-doctor.php?id=<?= (int)$fromDoctor['user_id'] ?>">Dr <?= e($fromDoctor['first_name'] . ' ' . $fromDoctor['last_name']) ?></a> ›
    <?php else: ?>
    <a href="researcher-dashboard.php">Dashboard</a> ›
    <?php endif; ?>
    Patient <?= $patientId ?>
</nav>

<div class="page-header">
    <div class="patient-heading">
        <!-- The patient ID is the only identifier researchers see -->
        <h1>Patient <?= $patientId ?></h1>
        <div class="patient-chips">
            <span class="chip">Age <?= e(age_from_birthdate($patient['birthdate'])) ?></span>
            <span class="chip">Sex: <?= e($patient['sex']) ?></span>
            <?php if ($latestDx): ?>
            <?= cancer_chip($types[(int)$latestDx['cancer_type_id']] ?? null) ?>
            <span class="chip">Stage <?= e($latestDx['stage'] === 'unknown' ? 'not recorded' : $latestDx['stage']) ?></span>
            <span class="chip"><?= e(ucfirst($latestDx['status'])) ?></span>
            <?php endif; ?>
        </div>
        <?php if ($latestDx): ?>
        <p class="muted small-text"><?= e($types[(int)$latestDx['cancer_type_id']]['name'] ?? '') ?></p>
        <?php endif; ?>
    </div>
</div>

<!-- ===================================================================
     2. Care team: the oncologists who have run predictions for this patient
     =================================================================== -->
<p class="care-team">
    <span class="muted">Care team:</span>
    <?php if ($careTeam): ?>
        <?php foreach ($careTeam as $i => $doc): ?>
        <?= $i ? ', ' : '' ?><a href="research-doctor.php?id=<?= (int)$doc['user_id'] ?>">Dr <?= e($doc['first_name'] . ' ' . $doc['last_name']) ?></a>
        <?php endforeach; ?>
    <?php else: ?>
        <span class="muted">no predictions run yet</span>
    <?php endif; ?>
</p>

<!-- ===================================================================
     3. Journey

     CHART SLOT CONVENTION (for the visualisation teammate):
       <div id="chart-x">          chart drawn here; holds an HTML fallback
       <script id="chart-x-data">  the chart's data as JSON
     =================================================================== -->
<section class="card" aria-labelledby="journey-heading">
    <div class="card-header">
        <h2 id="journey-heading">Journey</h2>
        <span class="muted small-text">Days since the first diagnosis</span>
    </div>

    <div id="chart-patient-timeline" class="chart-slot" role="img"
         aria-label="Timeline of this patient's diagnosis, model runs and reviews">
        <?php if ($events): ?>
        <ol class="journey-list">
            <?php foreach ($events as $event): ?>
            <li><span class="journey-day num">Day <?= $event['day'] ?></span><span><?= e($event['label']) ?></span><span class="muted"><?= e($event['month']) ?></span></li>
            <?php endforeach; ?>
        </ol>
        <?php else: ?>
        <p class="muted">Nothing recorded yet.</p>
        <?php endif; ?>
    </div>
    <!-- Shape: {"events":[{"day":0,"type":"diagnosis","label":"BRCA diagnosed","month":"Mar 2026"}, ...]}
         type is one of: diagnosis, model, review -->
    <script type="application/json" id="chart-patient-timeline-data"><?= json_encode(
        ['events' => array_map(fn($ev) => ['day' => $ev['day'], 'type' => $ev['type'], 'label' => $ev['label'], 'month' => $ev['month']], $events)],
        JSON_HEX_TAG | JSON_HEX_AMP
    ) ?></script>
</section>

<!-- ===================================================================
     4 + 5. Diagnoses and mutation summary side by side
     =================================================================== -->
<div class="grid-2">

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
                    <?php foreach ($diagnoses as $d): ?>
                    <tr>
                        <td><?= cancer_chip($types[(int)$d['cancer_type_id']] ?? null) ?></td>
                        <td><?= e(format_month($d['date_of_diagnosis'])) ?></td>
                        <td><?= $d['stage'] === 'unknown' ? '<span class="muted">Not recorded</span>' : e($d['stage']) ?></td>
                        <td><?= e(ucfirst($d['status'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$diagnoses): ?>
                    <tr><td colspan="4" class="empty">No diagnosis recorded.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card" aria-labelledby="impact-heading">
        <div class="card-header">
            <h2 id="impact-heading">Mutations by impact</h2>
            <span class="muted small-text num"><?= number_format(array_sum($impactCounts)) ?> in total</span>
        </div>

        <div id="chart-patient-impact" class="chart-slot" role="img"
             aria-label="Number of this patient's mutations at each impact level">
            <!-- Fallback: impact chip (links to the filtered table), bar, count -->
            <ul class="impact-bars">
                <?php foreach ($impactLevels as $level => $levelName): ?>
                <li>
                    <a href="<?= e(query_with(['impact' => $level, 'mpage' => null])) ?>#mutations-heading">
                        <span class="chip impact-<?= e($level) ?>"><?= e($levelName) ?></span>
                    </a>
                    <span class="impact-track"><span class="impact-fill impact-fill-<?= e($level) ?>" style="width: <?= round($impactCounts[$level] / $maxImpact * 100, 1) ?>%"></span></span>
                    <span class="num impact-count"><?= number_format($impactCounts[$level]) ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <script type="application/json" id="chart-patient-impact-data"><?= json_encode(
            ['impacts' => array_map(fn($level) => ['level' => $level, 'count' => $impactCounts[$level]], array_keys($impactLevels))],
            JSON_HEX_TAG | JSON_HEX_AMP
        ) ?></script>
    </section>
</div>

<!-- ===================================================================
     6. Mutations, 20 per page, most serious first (positions GRCh37)
     =================================================================== -->
<section class="card" aria-labelledby="mutations-heading">
    <div class="card-header">
        <h2 id="mutations-heading">Mutations</h2>
        <span class="muted small-text num"><?= number_format($mutationTotal) ?> matching · GRCh37</span>
    </div>

    <form class="filters mutation-filters" method="get" action="#mutations-heading">
        <!-- keeps ?id (and ?doctor) so the filter stays on this patient -->
        <?= hidden_query_inputs(['impact', 'gene', 'mpage']) ?>
        <div class="field">
            <label for="impact">Impact</label>
            <select id="impact" name="impact">
                <option value="">All</option>
                <?php foreach ($impactLevels as $level => $levelName): ?>
                <option value="<?= e($level) ?>" <?= $level === $impact ? 'selected' : '' ?>><?= e($levelName) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="gene">Gene</label>
            <input id="gene" name="gene" value="<?= e($gene) ?>" placeholder="e.g. TP53">
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
                <?php foreach ($mutations as $m): ?>
                <tr>
                    <td><span class="gene"><?= e($m['gene_symbol']) ?></span></td>
                    <td><?= e($m['chromosome']) ?></td>
                    <td class="num"><?= e(number_format((float)$m['genomic_position'])) ?></td>
                    <td class="mono change" title="<?= e(($m['original_allele'] ?? 'long deletion') . ' > ' . $m['altered_allele']) ?>">
                        <span class="ref"><?= e($allele($m['original_allele'])) ?></span>
                        <span aria-label="changed to">→</span>
                        <span class="alt"><?= e($allele($m['altered_allele'])) ?></span>
                    </td>
                    <td><span class="chip impact-<?= e(mutation_impact($m['mutation_type'])) ?>"><?= e(mutation_label($m['mutation_type'])) ?></span></td>
                    <td class="num"><?= $m['allele_frequency'] === null ? '—' : e(number_format((float)$m['allele_frequency'], 2)) ?></td>
                    <td>Sample <?= $sampleNumber[(int)$m['sample_id']] ?? '?' ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$mutations): ?>
                <tr><td colspan="7" class="empty"><?= $sampleIds ? 'No mutations match these filters.' : 'No samples have been tested for this patient.' ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?= pager('mpage', $mutationPage, MUTATIONS_PER_PAGE, $mutationTotal, 'mutations-heading', 'mutations') ?>
</section>

<!-- ===================================================================
     7. Predictions, newest first
     =================================================================== -->
<section class="card" aria-labelledby="predictions-heading">
    <div class="card-header">
        <h2 id="predictions-heading">Predictions</h2>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th scope="col">Run</th>
                    <th scope="col">Model</th>
                    <th scope="col">Run by</th>
                    <th scope="col">Model's call</th>
                    <th scope="col">Confidence</th>
                    <th scope="col">Matched diagnosis?</th>
                    <th scope="col">Status</th>
                    <th scope="col">Feedback</th>
                    <th scope="col"><span class="visually-hidden">Open</span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($predictions as $p): ?>
                <tr>
                    <td><?= e(format_date($p['created_at'])) ?></td>
                    <td><?= e(model_label($p['model_used'])) ?></td>
                    <td><a href="research-doctor.php?id=<?= (int)$p['doctor_id'] ?>">Dr <?= e($p['first_name'] . ' ' . $p['last_name']) ?></a></td>
                    <td><?= cancer_chip($types[(int)$p['top_type_id']] ?? null) ?></td>
                    <td class="num"><?= pct($p['top_probability']) ?></td>
                    <td><?= correct_mark($p['correct'] === null ? null : (bool)$p['correct']) ?></td>
                    <td><?= status_chip((bool)$p['reviewed']) ?></td>
                    <td class="num"><?= (int)$p['feedback_count'] ?></td>
                    <td><a href="research-prediction.php?id=<?= (int)$p['prediction_id'] ?>">Compare</a></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$predictions): ?>
                <tr><td colspan="9" class="empty">No predictions yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
