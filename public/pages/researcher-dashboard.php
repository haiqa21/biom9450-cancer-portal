<?php
/*
 * Research dashboard (researcher-dashboard.php)
 * Landing page for researchers after login.
 *
 *   1. Header             "Welcome back, Dr <name>"
 *   2. Tiles              patients, samples, awaiting review, reviewed, latest model accuracy
 *   3. Where predictions are   review status per model version   -> #chart-pipeline
 *   4. Accuracy by organ group  latest model vs recorded diagnosis -> #chart-group-accuracy
 *   5. Mutations in selected genes  patients per gene, by organ group -> #chart-genes
 *   6. Recent activity    the last 10 reviews by oncologists
 *   7. Patients           de-identified, 20 per page, filter by ID and cancer type
 *   8. Mutation explorer  filters + table + related-patients panel
 *
 * Researchers only ever see DE-IDENTIFIED data: patient ID, age, sex, diagnosis.
 * Never a name, birthdate, contact detail or sample code.
 * How the schema is used (status, doctor, correct call): see includes/research.php.
 */

require_once __DIR__ . '/../../includes/research.php';

$currentUser = research_require_login();       // also connects: $pdo

// ---------------------------------------------------------------------------
// Settings read by includes/header.php
// ---------------------------------------------------------------------------
$pageTitle  = 'Dashboard';                     // browser tab: "Dashboard | CancerInsight"
$activePage = 'researcher-dashboard';          // highlights "Dashboard" in the side nav
$pageStyles = ['researcher-dashboard.css'];

const PATIENTS_PER_PAGE  = 20;
const MUTATIONS_PER_PAGE = 10;

$types = cancer_types($pdo);                   // cancer_type_id => code, name, group
$versions = model_versions($pdo);              // newest first
$latestModel = $versions[0] ?? null;

// Well-known cancer genes offered in the gene picker. A fixed list on purpose:
// "most often mutated" would be led by very long genes such as TTN and MUC16,
// which collect mutations by size rather than because they drive cancer.
$geneOptions = ['TP53', 'PIK3CA', 'KRAS', 'PTEN', 'APC', 'ARID1A', 'BRAF', 'IDH1', 'NRAS', 'EGFR',
                'CDKN2A', 'ATRX', 'NF1', 'RB1', 'CTNNB1', 'FBXW7', 'SMAD4', 'KMT2D', 'BRCA1', 'BRCA2'];

// ---------------------------------------------------------------------------
// GET parameters (every filter is a GET form, so the view lives in the URL)
// ---------------------------------------------------------------------------

// Gene picker: keep only genes from the list. No "genes" in the URL = first visit,
// so default to the first 8. The form always sends genes[]="" so that unticking
// every gene is remembered as "none".
if (isset($_GET['genes'])) {
    $selectedGenes = array_values(array_intersect($geneOptions, (array)$_GET['genes']));
} else {
    $selectedGenes = array_slice($geneOptions, 0, 8);
}

// Patients table: search by patient ID, filter by cancer type id
$patientSearch = trim($_GET['q'] ?? '');
$patientSearchId = ctype_digit($patientSearch) ? (int)$patientSearch : null;
$patientCancer = isset($types[(int)($_GET['cancer'] ?? 0)]) ? (int)$_GET['cancer'] : 0;

// Mutation explorer filters. Positions must be whole numbers; anything else is ignored.
$explorer = [
    'chromosome' => trim($_GET['chromosome'] ?? ''),
    'gene'       => trim($_GET['gene'] ?? ''),
    'from'       => ctype_digit($_GET['from'] ?? '') ? $_GET['from'] : '',
    'to'         => ctype_digit($_GET['to'] ?? '') ? $_GET['to'] : '',
    'type'       => trim($_GET['type'] ?? ''),
];
$chromosomes = array_merge(array_map('strval', range(1, 22)), ['X', 'Y']);
if (!in_array($explorer['chromosome'], $chromosomes, true)) {
    $explorer['chromosome'] = '';
}
$mutationTypes = MUTATION_TYPES;
if (!in_array($explorer['type'], $mutationTypes, true)) {
    $explorer['type'] = '';
}
$selectedMutation = (int)($_GET['mutation'] ?? 0);
$explorerKeys = ['chromosome', 'gene', 'from', 'to', 'type', 'mpage', 'mutation', 'patient'];

// ---------------------------------------------------------------------------
// 2. Tiles
// ---------------------------------------------------------------------------
$stats = $pdo->query(
    "SELECT (SELECT COUNT(*) FROM patients) AS patients,
            (SELECT COUNT(*) FROM samples)  AS samples"
)->fetch();

// Review status per model version (also drives the pipeline chart)
$pipeline = $pdo->query(
    "SELECT model_used, SUM(reviewed = 0) AS pending, SUM(reviewed = 1) AS reviewed,
            AVG(correct) AS accuracy, COUNT(*) AS total
     FROM (" . PREDICTION_SQL . ") pv
     GROUP BY model_used"
)->fetchAll(PDO::FETCH_UNIQUE);
$pendingTotal  = array_sum(array_column($pipeline, 'pending'));
$reviewedTotal = array_sum(array_column($pipeline, 'reviewed'));
$latestAccuracy = $latestModel ? $pipeline[$latestModel]['accuracy'] : null;

// ---------------------------------------------------------------------------
// 4. Accuracy by organ group, latest model version
// ---------------------------------------------------------------------------
$groupAccuracy = array_fill_keys(array_keys(CANCER_GROUPS), ['n' => 0, 'correct' => 0]);
if ($latestModel) {
    $stmt = $pdo->prepare(
        "SELECT dx_type_id, COUNT(*) AS n, SUM(correct) AS correct
         FROM (" . PREDICTION_SQL . ") pv
         WHERE model_used = ? AND dx_type_id IS NOT NULL
         GROUP BY dx_type_id"
    );
    $stmt->execute([$latestModel]);
    foreach ($stmt as $row) {
        $group = $types[(int)$row['dx_type_id']]['group'];
        $groupAccuracy[$group]['n'] += (int)$row['n'];
        $groupAccuracy[$group]['correct'] += (int)$row['correct'];
    }
}

// ---------------------------------------------------------------------------
// 5. Patients with a mutation in each picked gene, by organ group
// ---------------------------------------------------------------------------
$geneCounts = [];          // gene => [group => patients]
foreach ($selectedGenes as $g) {
    $geneCounts[$g] = array_fill_keys(array_keys(CANCER_GROUPS), 0);
}
if ($selectedGenes) {
    $in = implode(',', array_fill(0, count($selectedGenes), '?'));
    $stmt = $pdo->prepare(
        "SELECT g.gene_symbol, d.cancer_type_id, COUNT(DISTINCT s.patient_id) AS patients
         FROM genes g
         JOIN mutations m          ON m.gene_id = g.gene_id
         JOIN patient_mutations pm ON pm.mutation_id = m.mutation_id
         JOIN samples s            ON s.sample_id = pm.sample_id
         JOIN sample_diagnoses sd  ON sd.sample_id = s.sample_id
         JOIN patient_diagnoses d  ON d.diagnosis_id = sd.diagnosis_id
         WHERE g.gene_symbol IN ($in)
         GROUP BY g.gene_symbol, d.cancer_type_id"
    );
    $stmt->execute($selectedGenes);
    foreach ($stmt as $row) {
        $group = $types[(int)$row['cancer_type_id']]['group'];
        $geneCounts[$row['gene_symbol']][$group] += (int)$row['patients'];
    }
    uasort($geneCounts, fn($a, $b) => array_sum($b) <=> array_sum($a));    // most patients first
}
$maxGeneTotal = $geneCounts ? max(array_map('array_sum', $geneCounts)) : 0;

// ---------------------------------------------------------------------------
// 6. Recent activity: the last 10 reviews (feedback by the oncologist who ran the prediction)
// ---------------------------------------------------------------------------
$recent = $pdo->query(
    "SELECT f.created_at, f.feedback_rating, p.prediction_id, p.patient_id, p.model_used,
            pr.user_id AS doctor_id, pr.first_name, pr.last_name
     FROM feedback f
     JOIN predictions p    ON p.prediction_id = f.prediction_id AND p.user_id = f.user_id
     JOIN professionals pr ON pr.user_id = f.user_id
     ORDER BY f.created_at DESC, f.feedback_id DESC
     LIMIT 10"
)->fetchAll();

// ---------------------------------------------------------------------------
// 7. Patients
// ---------------------------------------------------------------------------
$where = [];
$params = [];
if ($patientSearchId !== null) {
    $where[] = 'p.patient_id = ?';
    $params[] = $patientSearchId;
}
if ($patientCancer) {
    $where[] = 'dx.cancer_type_id = ?';
    $params[] = $patientCancer;
}
// Each patient's most recent diagnosis
$patientFrom = "FROM patients p
    LEFT JOIN (SELECT * FROM (SELECT d.*, ROW_NUMBER() OVER (PARTITION BY d.patient_id
                                                             ORDER BY d.date_of_diagnosis DESC, d.diagnosis_id DESC) AS rn
                              FROM patient_diagnoses d) ranked WHERE rn = 1) dx ON dx.patient_id = p.patient_id"
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '');

$stmt = $pdo->prepare("SELECT COUNT(*) $patientFrom");
$stmt->execute($params);
$patientTotal = (int)$stmt->fetchColumn();
$patientPage = page_number('ppage', $patientTotal, PATIENTS_PER_PAGE);

$stmt = $pdo->prepare(
    "SELECT p.patient_id, p.birthdate, p.sex, dx.cancer_type_id, dx.stage, dx.status
     $patientFrom
     ORDER BY p.patient_id
     LIMIT " . PATIENTS_PER_PAGE . ' OFFSET ' . (($patientPage - 1) * PATIENTS_PER_PAGE)
);
$stmt->execute($params);
$patients = $stmt->fetchAll(PDO::FETCH_UNIQUE);

// Mutation counts and latest prediction, for just the patients on this page
if ($patients) {
    $ids = implode(',', array_map('intval', array_keys($patients)));
    foreach ($pdo->query("SELECT s.patient_id, COUNT(*) AS n FROM samples s
                          JOIN patient_mutations pm ON pm.sample_id = s.sample_id
                          WHERE s.patient_id IN ($ids) GROUP BY s.patient_id") as $row) {
        $patients[$row['patient_id']]['mutations'] = (int)$row['n'];
    }
    foreach ($pdo->query("SELECT * FROM (SELECT pv.*, ROW_NUMBER() OVER (PARTITION BY pv.patient_id
                                                                       ORDER BY pv.created_at DESC) AS rn
                                       FROM (" . PREDICTION_SQL . " WHERE p.patient_id IN ($ids)) pv) latest
                          WHERE rn = 1") as $row) {
        $patients[$row['patient_id']]['latest'] = $row;
    }
}

// ---------------------------------------------------------------------------
// 8. Mutation explorer
// ---------------------------------------------------------------------------
// Two steps, so each stays fast on 744,000 sample-mutation links:
//  1. find the 10 (sample, mutation) keys for this page, joining only the tables the
//     filters need: none, mutations (type / position), or genes too (gene / chromosome).
//     With no gene filter the query starts from mutations (STRAIGHT_JOIN), because
//     mutation_type has no index and the default join order scans every link first.
//  2. fetch gene, position and patient for just those 10 keys.
$where = [];
$params = [];
if ($explorer['chromosome'] !== '') { $where[] = 'g.chromosome = ?';        $params[] = $explorer['chromosome']; }
if ($explorer['gene'] !== '')       { $where[] = 'g.gene_symbol = ?';       $params[] = $explorer['gene']; }
if ($explorer['from'] !== '')       { $where[] = 'm.genomic_position >= ?'; $params[] = (int)$explorer['from']; }
if ($explorer['to'] !== '')         { $where[] = 'm.genomic_position <= ?'; $params[] = (int)$explorer['to']; }
if ($explorer['type'] !== '')       { $where[] = 'm.mutation_type = ?';     $params[] = $explorer['type']; }

if (!$where) {
    $keysFrom = 'FROM patient_mutations pm';
    $straight = '';
} elseif ($explorer['gene'] !== '' || $explorer['chromosome'] !== '') {
    $keysFrom = 'FROM genes g JOIN mutations m ON m.gene_id = g.gene_id
                 JOIN patient_mutations pm ON pm.mutation_id = m.mutation_id WHERE ' . implode(' AND ', $where);
    $straight = '';
} else {
    $keysFrom = 'FROM mutations m JOIN patient_mutations pm ON pm.mutation_id = m.mutation_id WHERE ' . implode(' AND ', $where);
    $straight = 'STRAIGHT_JOIN ';
}

$stmt = $pdo->prepare("SELECT {$straight}COUNT(*) $keysFrom");
$stmt->execute($params);
$mutationTotal = (int)$stmt->fetchColumn();
$mutationPage = page_number('mpage', $mutationTotal, MUTATIONS_PER_PAGE);

$stmt = $pdo->prepare(
    "SELECT {$straight}pm.sample_id, pm.mutation_id $keysFrom
     ORDER BY pm.sample_id, pm.mutation_id
     LIMIT " . MUTATIONS_PER_PAGE . ' OFFSET ' . (($mutationPage - 1) * MUTATIONS_PER_PAGE)
);
$stmt->execute($params);
$keys = $stmt->fetchAll(PDO::FETCH_NUM);

$explorerRows = [];
if ($keys) {
    $pairs = implode(' OR ', array_map(fn($k) => '(pm.sample_id = ' . (int)$k[0] . ' AND pm.mutation_id = ' . (int)$k[1] . ')', $keys));
    $explorerRows = $pdo->query(
        "SELECT m.mutation_id, m.genomic_position, m.original_allele, m.altered_allele, m.mutation_type,
                g.gene_symbol, g.chromosome, pm.allele_frequency, s.patient_id
         FROM patient_mutations pm
         JOIN mutations m ON m.mutation_id = pm.mutation_id
         JOIN genes g     ON g.gene_id = m.gene_id
         JOIN samples s   ON s.sample_id = pm.sample_id
         WHERE $pairs
         ORDER BY pm.sample_id, pm.mutation_id"
    )->fetchAll();
}

// Related patients: who else has this exact variant, and who else has any mutation in its gene
$related = null;
if ($selectedMutation) {
    $stmt = $pdo->prepare('SELECT m.*, g.gene_symbol, g.chromosome FROM mutations m
                           JOIN genes g ON g.gene_id = m.gene_id WHERE m.mutation_id = ?');
    $stmt->execute([$selectedMutation]);
    $relatedMutation = $stmt->fetch();
    if ($relatedMutation) {
        $selectedPatient = (int)($_GET['patient'] ?? 0);
        $patientInfo = "SELECT DISTINCT p.patient_id, p.birthdate, d.cancer_type_id
                        FROM samples s
                        JOIN patients p          ON p.patient_id = s.patient_id
                        LEFT JOIN sample_diagnoses sd ON sd.sample_id = s.sample_id
                        LEFT JOIN patient_diagnoses d ON d.diagnosis_id = sd.diagnosis_id";
        $same = $pdo->prepare("$patientInfo JOIN patient_mutations pm ON pm.sample_id = s.sample_id
                               WHERE pm.mutation_id = ? AND p.patient_id <> ? ORDER BY p.patient_id");
        $same->execute([$selectedMutation, $selectedPatient]);
        $gene = $pdo->prepare("$patientInfo JOIN patient_mutations pm ON pm.sample_id = s.sample_id
                               JOIN mutations m ON m.mutation_id = pm.mutation_id
                               WHERE m.gene_id = ? AND pm.mutation_id <> ? AND p.patient_id <> ? ORDER BY p.patient_id");
        $gene->execute([$relatedMutation['gene_id'], $selectedMutation, $selectedPatient]);
        $related = ['mutation' => $relatedMutation, 'same_mutation' => $same->fetchAll(), 'same_gene' => $gene->fetchAll()];
    }
}

audit($pdo, $currentUser['user_id'], 'view_research_dashboard', null, null, 'genes=' . implode(',', $selectedGenes));

// List of related patients (first 8 + "and N more")
$relatedList = function (array $people) use ($types): string {
    if (!$people) {
        return '<p class="muted small-text">No other patients.</p>';
    }
    $html = '<ul class="people">';
    foreach (array_slice($people, 0, 8) as $p) {
        $html .= '<li>' . patient_link((int)$p['patient_id'])
               . '<span class="muted small-text">Age ' . e(age_from_birthdate($p['birthdate'])) . '</span>'
               . cancer_chip($types[(int)$p['cancer_type_id']] ?? null) . '</li>';
    }
    $html .= '</ul>';
    if (count($people) > 8) {
        $html .= '<p class="muted small-text">and ' . number_format(count($people) - 8) . ' more</p>';
    }
    return $html;
};

require __DIR__ . '/../../includes/header.php';
?>

<!-- ===================================================================
     1. Header
     =================================================================== -->
<div class="page-header">
    <h1>Welcome back, <?= e($currentUser['name']) ?></h1>
</div>

<!-- ===================================================================
     2. Stat tiles. Each links to the page that lists what it counts.
     =================================================================== -->
<div class="tiles">
    <a class="tile tile-link" href="#patients-heading">
        <span class="tile-value"><?= number_format($stats['patients']) ?></span>
        <span class="tile-label">Patients</span>
    </a>
    <a class="tile tile-link" href="#explorer-heading">
        <span class="tile-value"><?= number_format($stats['samples']) ?></span>
        <span class="tile-label">Samples</span>
    </a>
    <a class="tile tile-link" href="research-predictions.php?tab=all&amp;status=pending">
        <span class="tile-value"><?= number_format($pendingTotal) ?></span>
        <span class="tile-label">Predictions awaiting review</span>
    </a>
    <a class="tile tile-link" href="research-predictions.php?tab=all&amp;status=reviewed">
        <span class="tile-value"><?= number_format($reviewedTotal) ?></span>
        <span class="tile-label">Reviewed by their oncologist</span>
    </a>
    <a class="tile tile-link" href="model-performance.php">
        <span class="tile-value"><?= pct($latestAccuracy) ?></span>
        <span class="tile-label"><?= $latestModel ? e(model_label($latestModel)) . ' matched the diagnosis' : 'No predictions yet' ?></span>
    </a>
</div>

<!-- ===================================================================
     3 + 4. Two charts side by side (.grid-2 stacks them on narrow screens)

     CHART SLOT CONVENTION (for the visualisation teammate):
       <div id="chart-x">           where the chart is drawn; holds a plain
                                    HTML fallback until the chart script
                                    replaces it, so the page is never empty
       <script id="chart-x-data">   the chart's data as JSON
     =================================================================== -->
<div class="grid-2">

    <!-- 3. Review status for each model version -->
    <section class="card" aria-labelledby="pipeline-heading">
        <div class="card-header">
            <h2 id="pipeline-heading">Where predictions are</h2>
        </div>

        <div id="chart-pipeline" class="chart-slot" role="img"
             aria-label="Predictions awaiting review and reviewed, for each model version">
            <ol class="pipeline-list">
                <?php foreach ($versions as $model): ?>
                <li>
                    <strong><?= e(model_label($model)) ?></strong>
                    <span class="pipeline-counts">
                        <a href="research-predictions.php?tab=all&amp;status=pending&amp;version=<?= e(urlencode($model)) ?>"><?= status_chip(false) ?></a>
                        <span class="pipeline-count num"><?= number_format($pipeline[$model]['pending']) ?></span>
                        <a href="research-predictions.php?tab=all&amp;status=reviewed&amp;version=<?= e(urlencode($model)) ?>"><?= status_chip(true) ?></a>
                        <span class="pipeline-count num"><?= number_format($pipeline[$model]['reviewed']) ?></span>
                    </span>
                </li>
                <?php endforeach; ?>
                <?php if (!$versions): ?>
                <li class="muted">No predictions have been run yet.</li>
                <?php endif; ?>
            </ol>
        </div>
        <!-- Shape: {"models":[{"model":"expression-v2","label":"Expression model v2","pending":587,"reviewed":1413}, ...]} -->
        <script type="application/json" id="chart-pipeline-data"><?= json_encode(
            ['models' => array_map(fn($model) => [
                'model'    => $model,
                'label'    => model_label($model),
                'pending'  => (int)$pipeline[$model]['pending'],
                'reviewed' => (int)$pipeline[$model]['reviewed'],
            ], $versions)],
            JSON_HEX_TAG | JSON_HEX_AMP
        ) ?></script>
    </section>

    <!-- 4. How often the latest model's call matched the recorded diagnosis, by organ group -->
    <section class="card" aria-labelledby="accuracy-heading">
        <div class="card-header">
            <h2 id="accuracy-heading">Matched the diagnosis</h2>
            <a class="small-text" href="model-performance.php">All 33 types</a>
        </div>
        <p class="muted small-text"><?= $latestModel ? e(model_label($latestModel)) . ', by organ group' : '' ?></p>

        <div id="chart-group-accuracy" class="chart-slot" role="img"
             aria-label="Share of predictions matching the recorded diagnosis, for each organ group">
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">Organ group</th>
                            <th scope="col">Patients</th>
                            <th scope="col">Matched</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (CANCER_GROUPS as $key => [$groupName]): ?>
                        <tr>
                            <td><span class="cancer-name"><span class="dot dot-group-<?= e($key) ?>" aria-hidden="true"></span><?= e($groupName) ?></span></td>
                            <td class="num"><?= number_format($groupAccuracy[$key]['n']) ?></td>
                            <td class="num"><?= $groupAccuracy[$key]['n'] ? pct($groupAccuracy[$key]['correct'] / $groupAccuracy[$key]['n']) : '—' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <!-- Shape: {"model":"expression-v2","groups":[{"group":"breast_gyn","label":"Breast and gynaecological","n":465,"model_pct":96.1}, ...]} -->
        <script type="application/json" id="chart-group-accuracy-data"><?= json_encode(
            [
                'model'  => $latestModel,
                'groups' => array_map(fn($key) => [
                    'group'     => $key,
                    'label'     => CANCER_GROUPS[$key][0],
                    'n'         => $groupAccuracy[$key]['n'],
                    'model_pct' => $groupAccuracy[$key]['n'] ? round($groupAccuracy[$key]['correct'] / $groupAccuracy[$key]['n'] * 100, 1) : null,
                ], array_keys(CANCER_GROUPS)),
            ],
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
        <span class="muted small-text">Patients with at least one mutation in each gene</span>
    </div>

    <form method="get" action="#genes-heading">
        <!-- keep the patients table and explorer as they were -->
        <?= hidden_query_inputs(['genes']) ?>
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

    <!-- Legend: organ group colours (the fallback rows and chart tooltips name them too) -->
    <div class="legend" aria-hidden="true">
        <?php foreach (CANCER_GROUPS as $key => [$groupName]): ?>
        <span><span class="dot dot-group-<?= e($key) ?>"></span><?= e($groupName) ?></span>
        <?php endforeach; ?>
    </div>

    <div id="chart-genes" class="chart-slot" role="img"
         aria-label="Number of patients with a mutation in each selected gene, split by organ group">
        <?php if ($geneCounts): ?>
        <!-- Fallback: one stacked bar per gene, a segment per organ group -->
        <div class="gene-bars">
            <?php foreach ($geneCounts as $gene => $byGroup): ?>
            <?php $total = array_sum($byGroup); ?>
            <div class="gene-bar">
                <span class="gene"><?= e($gene) ?></span>
                <span class="track">
                    <?php foreach ($byGroup as $group => $n): ?>
                        <?php if ($n && $maxGeneTotal): ?>
                        <span class="seg seg-group-<?= e($group) ?>"
                              style="width: calc(<?= round($n / $maxGeneTotal * 100, 2) ?>% - 2px)"
                              title="<?= e($gene) ?>, <?= e(CANCER_GROUPS[$group][0]) ?>: <?= $n ?> patients"></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </span>
                <span class="total num"><?= number_format($total) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p class="muted">Select at least one gene.</p>
        <?php endif; ?>
    </div>
    <!-- Shape: {"groups":[{"group":"breast_gyn","label":"..."}...],"genes":{"TP53":{"breast_gyn":120,...}}} -->
    <script type="application/json" id="chart-genes-data"><?= json_encode(
        [
            'groups' => array_map(fn($key) => ['group' => $key, 'label' => CANCER_GROUPS[$key][0]], array_keys(CANCER_GROUPS)),
            'genes'  => $geneCounts,
        ],
        JSON_HEX_TAG | JSON_HEX_AMP
    ) ?></script>
</section>

<!-- ===================================================================
     6. Recent activity: the last 10 reviews. Each row opens that prediction.
     =================================================================== -->
<section class="card" aria-labelledby="activity-heading">
    <div class="card-header">
        <h2 id="activity-heading">Recent reviews</h2>
        <a class="small-text" href="research-predictions.php?tab=all">All predictions</a>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th scope="col">Date</th>
                    <th scope="col">Patient</th>
                    <th scope="col">Doctor</th>
                    <th scope="col">Model</th>
                    <th scope="col">Rating</th>
                    <th scope="col"><span class="visually-hidden">Open</span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent as $r): ?>
                <tr>
                    <td><?= e(format_date($r['created_at'])) ?></td>
                    <td><?= patient_link((int)$r['patient_id']) ?></td>
                    <td><a href="research-doctor.php?id=<?= (int)$r['doctor_id'] ?>">Dr <?= e($r['first_name'] . ' ' . $r['last_name']) ?></a></td>
                    <td><?= e(model_label($r['model_used'])) ?></td>
                    <td><?= rating_chip($r['feedback_rating']) ?></td>
                    <td><a href="research-prediction.php?id=<?= (int)$r['prediction_id'] ?>">Compare</a></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$recent): ?>
                <tr><td colspan="6" class="empty">No reviews yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- ===================================================================
     7. Patients (de-identified), 20 per page
     =================================================================== -->
<section class="card" aria-labelledby="patients-heading">
    <div class="card-header">
        <h2 id="patients-heading">Patients</h2>
        <span class="muted small-text num"><?= number_format($patientTotal) ?> matching</span>
    </div>

    <form class="filters patient-filters" method="get" action="#patients-heading">
        <?= hidden_query_inputs(['q', 'cancer', 'ppage']) ?>
        <div class="field">
            <label for="q">Patient ID</label>
            <input id="q" name="q" inputmode="numeric" value="<?= e($patientSearch) ?>" placeholder="e.g. 48">
        </div>
        <div class="field">
            <label for="cancer">Cancer type</label>
            <select id="cancer" name="cancer">
                <option value="">All</option>
                <?= cancer_type_options($pdo, $patientCancer) ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Filter</button>
        <a class="btn" href="<?= e(query_with(['q' => null, 'cancer' => null, 'ppage' => null])) ?>#patients-heading">Clear</a>
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
                <?php foreach ($patients as $id => $p): ?>
                <?php $latest = $p['latest'] ?? null; ?>
                <tr>
                    <td><?= patient_link((int)$id) ?></td>
                    <td class="num"><?= e(age_from_birthdate($p['birthdate'])) ?></td>
                    <td><?= e(ucfirst($p['sex'])) ?></td>
                    <td><?= cancer_chip($types[(int)$p['cancer_type_id']] ?? null) ?></td>
                    <td><?= $p['stage'] && $p['stage'] !== 'unknown' ? e($p['stage']) : '<span class="muted">Not recorded</span>' ?></td>
                    <td><?= e(ucfirst($p['status'] ?? '')) ?></td>
                    <td class="num"><?= number_format($p['mutations'] ?? 0) ?></td>
                    <td>
                        <?php if ($latest): ?>
                        <a href="research-prediction.php?id=<?= (int)$latest['prediction_id'] ?>" class="prediction-link">
                            <?= cancer_chip($types[(int)$latest['top_type_id']] ?? null) ?>
                            <span class="num"><?= pct($latest['top_probability']) ?></span>
                        </a>
                        <?= correct_mark($latest['correct'] === null ? null : (bool)$latest['correct']) ?>
                        <?php else: ?>
                        <span class="muted">None</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$patients): ?>
                <tr><td colspan="8" class="empty">No patients match these filters.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?= pager('ppage', $patientPage, PATIENTS_PER_PAGE, $patientTotal, 'patients-heading', 'patients') ?>
</section>

<!-- ===================================================================
     8. Mutation explorer
     Filter patient mutations; click one to see which other patients share
     that exact variant, or any mutation in the same gene. Positions are GRCh37.
     =================================================================== -->
<section class="card" aria-labelledby="explorer-heading">
    <div class="card-header">
        <h2 id="explorer-heading">Mutation explorer</h2>
        <span class="muted small-text num"><?= number_format($mutationTotal) ?> matching patient mutations · GRCh37</span>
    </div>

    <form class="filters explorer-filters" method="get" action="#explorer-heading">
        <?= hidden_query_inputs($explorerKeys) ?>
        <div class="field">
            <label for="chromosome">Chromosome</label>
            <select id="chromosome" name="chromosome">
                <option value="">All</option>
                <?php foreach ($chromosomes as $c): ?>
                <option <?= $c === $explorer['chromosome'] ? 'selected' : '' ?>><?= e($c) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="gene">Gene</label>
            <input id="gene" name="gene" list="gene-options" value="<?= e($explorer['gene']) ?>" placeholder="e.g. TP53">
            <datalist id="gene-options">
                <?php foreach ($geneOptions as $g): ?>
                <option value="<?= e($g) ?>"></option>
                <?php endforeach; ?>
            </datalist>
        </div>
        <div class="field">
            <label for="from">Position from</label>
            <input id="from" name="from" inputmode="numeric" value="<?= e($explorer['from']) ?>" placeholder="7570000">
        </div>
        <div class="field">
            <label for="to">to</label>
            <input id="to" name="to" inputmode="numeric" value="<?= e($explorer['to']) ?>" placeholder="7590000">
        </div>
        <div class="field">
            <label for="type">Mutation type</label>
            <select id="type" name="type">
                <option value="">Any</option>
                <?php foreach ($mutationTypes as $t): ?>
                <option value="<?= e($t) ?>" <?= $t === $explorer['type'] ? 'selected' : '' ?>><?= e(mutation_label($t)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Filter</button>
        <a class="btn" href="<?= e(query_with(array_fill_keys($explorerKeys, null))) ?>#explorer-heading">Clear</a>
    </form>

    <!-- Two columns when a mutation is selected (table + related panel), one otherwise -->
    <div class="explorer<?= $related ? '' : ' explorer-full' ?>">
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
                        <?php foreach ($explorerRows as $m): ?>
                        <?php $isSelected = (int)$m['mutation_id'] === $selectedMutation; ?>
                        <tr class="<?= $isSelected ? 'selected' : '' ?>">
                            <td>
                                <a class="mono row-link" href="<?= e(query_with(['mutation' => $m['mutation_id'], 'patient' => $m['patient_id']])) ?>#explorer-heading"
                                   <?= $isSelected ? 'aria-current="true"' : '' ?>><?= e(variant_name($m)) ?></a>
                            </td>
                            <td><span class="gene"><?= e($m['gene_symbol']) ?></span></td>
                            <td><span class="chip impact-<?= e(mutation_impact($m['mutation_type'])) ?>"><?= e(mutation_label($m['mutation_type'])) ?></span></td>
                            <td class="num"><?= $m['allele_frequency'] === null ? '—' : e(number_format((float)$m['allele_frequency'], 2)) ?></td>
                            <td><?= patient_link((int)$m['patient_id']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$explorerRows): ?>
                        <tr><td colspan="5" class="empty">No mutations match these filters.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?= pager('mpage', $mutationPage, MUTATIONS_PER_PAGE, $mutationTotal, 'explorer-heading', 'mutations') ?>
        </div>

        <?php if ($related): ?>
        <!-- Related patients for the clicked mutation: patient IDs only, never names -->
        <aside class="related" aria-label="Related patients">
            <h3 class="mono"><?= e(variant_name($related['mutation'])) ?></h3>
            <p class="muted small-text"><span class="gene"><?= e($related['mutation']['gene_symbol']) ?></span>
                · <?= e(mutation_label($related['mutation']['mutation_type'])) ?></p>

            <h3>Same mutation (<?= number_format(count($related['same_mutation'])) ?>)</h3>
            <?= $relatedList($related['same_mutation']) ?>

            <h3>Other mutations in <?= e($related['mutation']['gene_symbol']) ?> (<?= number_format(count($related['same_gene'])) ?>)</h3>
            <?= $relatedList($related['same_gene']) ?>

            <a class="btn" href="<?= e(query_with(['mutation' => null, 'patient' => null])) ?>#explorer-heading">Close</a>
        </aside>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
