<?php
/*
 * Doctors (research-doctors.php)
 * Every oncologist, the patients they've run predictions for, how far they are with
 * reviewing them, and how often the model's call matched their patients' recorded diagnosis.
 *
 *   1. Header          "Doctors" + count
 *   2. Filters         name search, speciality, institution (GET form)
 *   3. Model accuracy per doctor  -> #chart-doctor-accuracy
 *   4. Doctors table   sortable by clicking a column header, 20 per page
 *
 * A doctor's patients are the patients they have run predictions for (predictions.user_id);
 * the schema has no separate care-team table. Doctors' names are shown (they are staff);
 * patients are always de-identified.
 */

require_once __DIR__ . '/../../includes/research.php';

$currentUser = research_require_login();       // also connects: $pdo

$pageTitle  = 'Doctors';
$activePage = 'research-doctors';
$pageStyles = ['research-doctors.css'];

const DOCTORS_PER_PAGE = 20;

$latestModel = model_versions($pdo)[0] ?? null;

// ---------------------------------------------------------------------------
// Sortable columns: URL key => [label, default direction, value used to sort]
// Text sorts A-Z first, numbers highest first. Only these keys are accepted.
// ---------------------------------------------------------------------------
$columns = [
    'name'        => ['Doctor', 'asc'],
    'speciality'  => ['Speciality', 'asc'],
    'institution' => ['Institution', 'asc'],
    'patients'    => ['Patients', 'desc'],
    'reviews'     => ['Reviews done', 'desc'],
    'awaiting'    => ['Awaiting review', 'desc'],
    'model_pct'   => ['Model matched diagnosis', 'desc'],
    'avg_rating'  => ['Average rating given', 'desc'],
];
$sort = array_key_exists($_GET['sort'] ?? '', $columns) ? $_GET['sort'] : 'name';
$dir  = in_array($_GET['dir'] ?? '', ['asc', 'desc'], true) ? $_GET['dir'] : $columns[$sort][1];

// ---------------------------------------------------------------------------
// Filters (dropdown values must be ones that exist)
// ---------------------------------------------------------------------------
$specialityOptions  = $pdo->query("SELECT DISTINCT speciality FROM professionals
                                   WHERE professional_type = 'oncologist' AND speciality IS NOT NULL ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
$institutionOptions = $pdo->query("SELECT DISTINCT institution FROM professionals
                                   WHERE professional_type = 'oncologist' AND institution IS NOT NULL ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
$search      = trim($_GET['q'] ?? '');
$speciality  = in_array($_GET['speciality'] ?? '', $specialityOptions, true) ? $_GET['speciality'] : '';
$institution = in_array($_GET['institution'] ?? '', $institutionOptions, true) ? $_GET['institution'] : '';

$where = ["pr.professional_type = 'oncologist'"];
$params = [];
if ($search !== '') {
    $where[] = "CONCAT(pr.first_name, ' ', pr.last_name) LIKE ?";
    $params[] = '%' . $search . '%';
}
if ($speciality !== '')  { $where[] = 'pr.speciality = ?';  $params[] = $speciality; }
if ($institution !== '') { $where[] = 'pr.institution = ?'; $params[] = $institution; }

$stmt = $pdo->prepare('SELECT pr.user_id, pr.first_name, pr.last_name, pr.speciality, pr.institution
                       FROM professionals pr WHERE ' . implode(' AND ', $where));
$stmt->execute($params);
$doctors = $stmt->fetchAll(PDO::FETCH_UNIQUE);

// ---------------------------------------------------------------------------
// Figures per doctor (one query over all predictions, one over all feedback)
// ---------------------------------------------------------------------------
$stmt = $pdo->prepare(
    "SELECT doctor_id,
            COUNT(DISTINCT patient_id)            AS patients,
            SUM(reviewed)                         AS reviews,
            SUM(1 - reviewed)                     AS awaiting,
            AVG(CASE WHEN model_used = ? THEN correct END) AS model_acc,
            SUM(model_used = ?)                   AS model_n
     FROM (" . PREDICTION_SQL . ") pv
     GROUP BY doctor_id"
);
$stmt->execute([$latestModel, $latestModel]);
$stats = $stmt->fetchAll(PDO::FETCH_UNIQUE);
$ratings = $pdo->query('SELECT user_id, AVG(feedback_rating) FROM feedback GROUP BY user_id')->fetchAll(PDO::FETCH_KEY_PAIR);

foreach ($doctors as $id => &$d) {
    $s = $stats[$id] ?? [];
    $d['name']       = 'Dr ' . $d['first_name'] . ' ' . $d['last_name'];
    $d['patients']   = (int)($s['patients'] ?? 0);
    $d['reviews']    = (int)($s['reviews'] ?? 0);
    $d['awaiting']   = (int)($s['awaiting'] ?? 0);
    $d['model_pct']  = isset($s['model_acc']) ? (float)$s['model_acc'] : null;
    $d['model_n']    = (int)($s['model_n'] ?? 0);
    $d['avg_rating'] = isset($ratings[$id]) ? (float)$ratings[$id] : null;
    $d['sort_name']  = $d['last_name'] . ' ' . $d['first_name'];
}
unset($d);

// Sort in PHP: there are only a handful of oncologists. Missing values sort last.
uasort($doctors, function ($a, $b) use ($sort, $dir) {
    $key = $sort === 'name' ? 'sort_name' : $sort;
    if ($a[$key] === null || $b[$key] === null) {
        return ($a[$key] === null) <=> ($b[$key] === null);
    }
    $cmp = is_string($a[$key]) ? strcasecmp($a[$key], $b[$key]) : $a[$key] <=> $b[$key];
    return $dir === 'asc' ? $cmp : -$cmp;
});

$doctorTotal = count($doctors);
$doctorPage = page_number('page', $doctorTotal, DOCTORS_PER_PAGE);    // not $page: header.php's nav loop uses $page
$pageDoctors = array_slice($doctors, ($doctorPage - 1) * DOCTORS_PER_PAGE, DOCTORS_PER_PAGE, true);

audit($pdo, $currentUser['user_id'], 'view_doctors', null, null, $_SERVER['QUERY_STRING'] ?? null);

// Header cell for a sortable column: click to sort, click again to reverse
$sortHeader = function (string $key) use ($columns, $sort, $dir): string {
    [$text, $defaultDir] = $columns[$key];
    $isCurrent = $key === $sort;
    $nextDir   = $isCurrent ? ($dir === 'asc' ? 'desc' : 'asc') : $defaultDir;
    $ariaSort  = $isCurrent ? ($dir === 'asc' ? 'ascending' : 'descending') : 'none';
    $arrow     = $isCurrent ? '<span class="sort-arrow" aria-hidden="true">' . ($dir === 'asc' ? '▲' : '▼') . '</span>' : '';
    return '<th scope="col" aria-sort="' . $ariaSort . '">'
         . '<a class="sort-link" href="' . e(query_with(['sort' => $key, 'dir' => $nextDir, 'page' => null])) . '#doctors-table-heading">'
         . e($text) . $arrow . '</a></th>';
};

require __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1>Doctors</h1>
        <p><span class="num"><?= number_format($doctorTotal) ?></span> oncologists</p>
    </div>
</div>

<!-- ===================================================================
     2. Filters (one GET form; keeps the current sort)
     =================================================================== -->
<form class="filters" method="get" action="">
    <?= hidden_query_inputs(['q', 'speciality', 'institution', 'page']) ?>
    <div class="field">
        <label for="q">Search by name</label>
        <input id="q" name="q" value="<?= e($search) ?>" placeholder="e.g. Okafor">
    </div>
    <div class="field">
        <label for="speciality">Speciality</label>
        <select id="speciality" name="speciality">
            <option value="">All</option>
            <?php foreach ($specialityOptions as $s): ?>
            <option <?= $s === $speciality ? 'selected' : '' ?>><?= e($s) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="institution">Institution</label>
        <select id="institution" name="institution">
            <option value="">All</option>
            <?php foreach ($institutionOptions as $i): ?>
            <option <?= $i === $institution ? 'selected' : '' ?>><?= e($i) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn btn-primary">Filter</button>
    <a class="btn" href="<?= e(query_with(['q' => null, 'speciality' => null, 'institution' => null, 'page' => null])) ?>">Clear</a>
</form>

<!-- ===================================================================
     3. Model accuracy on each doctor's patients (latest model version)

     CHART SLOT CONVENTION (for the visualisation teammate):
       <div id="chart-x">          chart drawn here; holds an HTML fallback
       <script id="chart-x-data">  the chart's data as JSON
     =================================================================== -->
<section class="card" aria-labelledby="accuracy-heading">
    <div class="card-header">
        <h2 id="accuracy-heading">How often the model matched each doctor's patients</h2>
        <span class="muted small-text"><?= $latestModel ? e(model_label($latestModel)) : '' ?> vs recorded diagnosis</span>
    </div>

    <div id="chart-doctor-accuracy" class="chart-slot" role="img"
         aria-label="For each doctor, the share of their patients' predictions that matched the recorded diagnosis">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th scope="col">Doctor</th>
                        <th scope="col">Model matched</th>
                        <th scope="col">Predictions</th>
                        <th scope="col">Average rating given</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($doctors as $id => $d): ?>
                    <tr>
                        <td><a href="research-doctor.php?id=<?= (int)$id ?>"><?= e($d['name']) ?></a></td>
                        <td class="num"><?= pct($d['model_pct']) ?></td>
                        <td class="num"><?= number_format($d['model_n']) ?></td>
                        <td class="num"><?= $d['avg_rating'] === null ? '—' : number_format($d['avg_rating'], 1) . ' / 5' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <!-- Shape: {"model":"expression-v2","doctors":[{"id":7,"name":"Dr ...","model_pct":93.4,"n":151,"avg_rating":4.1}, ...]} -->
    <script type="application/json" id="chart-doctor-accuracy-data"><?= json_encode(
        [
            'model'   => $latestModel,
            'doctors' => array_values(array_map(fn($id, $d) => [
                'id'         => (int)$id,
                'name'       => $d['name'],
                'model_pct'  => $d['model_pct'] === null ? null : round($d['model_pct'] * 100, 1),
                'n'          => $d['model_n'],
                'avg_rating' => $d['avg_rating'] === null ? null : round($d['avg_rating'], 2),
            ], array_keys($doctors), $doctors)),
        ],
        JSON_HEX_TAG | JSON_HEX_AMP
    ) ?></script>
    <p class="muted small-text chart-note">Doctors whose patients have different cancer types aren't directly comparable: some types are harder for the model.</p>
</section>

<!-- ===================================================================
     4. Doctors table: click a header to sort, click again to reverse
     =================================================================== -->
<section class="card" aria-labelledby="doctors-table-heading">
    <div class="card-header">
        <h2 id="doctors-table-heading">All doctors</h2>
    </div>

    <div class="table-wrap">
        <table class="doctors-table">
            <thead>
                <tr>
                    <?php foreach (array_keys($columns) as $key): ?>
                    <?= $sortHeader($key) ?>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pageDoctors as $id => $d): ?>
                <tr>
                    <td><a class="row-link" href="research-doctor.php?id=<?= (int)$id ?>"><?= e($d['name']) ?></a></td>
                    <td><?= e($d['speciality'] ?? '—') ?></td>
                    <td><?= e($d['institution'] ?? '—') ?></td>
                    <td class="num"><?= number_format($d['patients']) ?></td>
                    <td class="num"><?= number_format($d['reviews']) ?></td>
                    <td class="num">
                        <?php if ($d['awaiting']): ?>
                        <a href="research-predictions.php?tab=all&amp;status=pending&amp;doctor=<?= (int)$id ?>"><?= number_format($d['awaiting']) ?></a>
                        <?php else: ?>0<?php endif; ?>
                    </td>
                    <td class="num"><?= pct($d['model_pct']) ?></td>
                    <td class="num"><?= $d['avg_rating'] === null ? '—' : number_format($d['avg_rating'], 1) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$pageDoctors): ?>
                <tr><td colspan="8" class="empty">No doctors match these filters.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?= pager('page', $doctorPage, DOCTORS_PER_PAGE, $doctorTotal, 'doctors-table-heading', 'doctors') ?>
</section>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
