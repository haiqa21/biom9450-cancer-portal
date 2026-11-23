<?php
/*
 * Predictions (research-predictions.php)
 * Every model run, one row each: the researcher's work queue. Opens on "Needs my feedback".
 *
 *   1. Header          "Predictions" + count of matching rows
 *   2. Quick tabs      Needs my feedback · All · Didn't match diagnosis · Reviewed
 *   3. Filters         status, model's call, diagnosis, doctor, model version, matched,
 *                      month from-to (GET form)
 *   4. Summary strip   3 shares of the matching rows
 *   5. Table           10 per page, sortable by run date and confidence
 *
 * Status, doctor and "matched" are defined in includes/research.php.
 */

require_once __DIR__ . '/../../includes/research.php';

$currentUser = research_require_login();       // also connects: $pdo
$me = $currentUser['user_id'];

$pageTitle  = 'Predictions';
$activePage = 'research-predictions';
$pageStyles = ['research-predictions.css'];

const PREDICTIONS_PER_PAGE = 10;

$types = cancer_types($pdo);
$versions = model_versions($pdo);
$doctorOptions = oncologist_names($pdo);

// ---------------------------------------------------------------------------
// Quick tabs: preset views (tab key => label). Each adds one condition.
// ---------------------------------------------------------------------------
$tabs = [
    'needs'    => 'Needs my feedback',
    'all'      => 'All',
    'mismatch' => "Didn't match diagnosis",
    'reviewed' => 'Reviewed',
];
$tab = array_key_exists($_GET['tab'] ?? '', $tabs) ? $_GET['tab'] : 'needs';

// ---------------------------------------------------------------------------
// Filters. A value not in its list is dropped and named in $ignored, which is
// shown above the table so nobody thinks a filter was applied when it wasn't.
// ---------------------------------------------------------------------------
$ignored = [];
$pick = function (string $key, array $allowed, string $name) use (&$ignored): string {
    $value = trim((string)($_GET[$key] ?? ''));
    if ($value === '') {
        return '';
    }
    if (!in_array($value, array_map('strval', $allowed), true)) {
        $ignored[] = $name;
        return '';
    }
    return $value;
};
$status  = $pick('status', array_keys(REVIEW_STATUSES), 'status');
$cancer  = $pick('cancer', array_keys($types), "model's call");
$dx      = $pick('dx', array_keys($types), 'diagnosis');
$doctor  = $pick('doctor', array_keys($doctorOptions), 'doctor');
$version = $pick('version', $versions, 'model version');
$match   = $pick('match', ['yes', 'no'], 'matched');

// Months come from <input type="month">: "YYYY-MM"
$month = function (string $key, string $name) use (&$ignored): string {
    $value = trim((string)($_GET[$key] ?? ''));
    if ($value !== '' && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)) {
        $ignored[] = $name;
        return '';
    }
    return $value;
};
$from = $month('from', 'from month');
$to   = $month('to', 'to month');
if ($from !== '' && $to !== '' && $from > $to) {
    $ignored[] = 'date range (from is after to)';
    $from = $to = '';
}

// ---------------------------------------------------------------------------
// Build the WHERE clause on the PREDICTION_SQL columns
// ---------------------------------------------------------------------------
$where = [];
$params = [];
if ($tab === 'needs') {
    $where[] = 'NOT EXISTS (SELECT 1 FROM feedback mine WHERE mine.prediction_id = pv.prediction_id AND mine.user_id = ?)';
    $params[] = $me;
} elseif ($tab === 'mismatch') {
    $where[] = 'pv.correct = 0';
} elseif ($tab === 'reviewed') {
    $where[] = 'pv.reviewed = 1';
}
if ($status !== '')  { $where[] = 'pv.reviewed = ?';      $params[] = $status === 'reviewed' ? 1 : 0; }
if ($cancer !== '')  { $where[] = 'pv.top_type_id = ?';   $params[] = (int)$cancer; }
if ($dx !== '')      { $where[] = 'pv.dx_type_id = ?';    $params[] = (int)$dx; }
if ($doctor !== '')  { $where[] = 'pv.doctor_id = ?';     $params[] = (int)$doctor; }
if ($version !== '') { $where[] = 'pv.model_used = ?';    $params[] = $version; }
if ($match !== '')   { $where[] = 'pv.correct = ?';       $params[] = $match === 'yes' ? 1 : 0; }
if ($from !== '')    { $where[] = 'pv.created_at >= ?';   $params[] = $from . '-01'; }
if ($to !== '')      { $where[] = 'pv.created_at < ? + INTERVAL 1 MONTH'; $params[] = $to . '-01'; }
$fromSql  = 'FROM (' . PREDICTION_SQL . ') pv';
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

// ---------------------------------------------------------------------------
// Totals for the header and the summary strip (same rows as the table)
// ---------------------------------------------------------------------------
$stmt = $pdo->prepare(
    "SELECT COUNT(*) AS total, AVG(pv.correct) AS matched, AVG(pv.reviewed) AS reviewed,
            AVG(EXISTS (SELECT 1 FROM feedback mine WHERE mine.prediction_id = pv.prediction_id AND mine.user_id = ?)) AS mine
     $fromSql $whereSql"
);
$stmt->execute(array_merge([$me], $params));
$summary = $stmt->fetch();
$total = (int)$summary['total'];

// ---------------------------------------------------------------------------
// Sorting (two columns) and paging
// ---------------------------------------------------------------------------
$sortable = ['run' => ['Run', 'pv.created_at'], 'confidence' => ['Confidence', 'pv.top_probability']];
$sort = array_key_exists($_GET['sort'] ?? '', $sortable) ? $_GET['sort'] : 'run';
$dir  = ($_GET['dir'] ?? '') === 'asc' ? 'asc' : 'desc';
$predictionPage = page_number('p', $total, PREDICTIONS_PER_PAGE);   // not $page: header.php's nav loop uses $page

$stmt = $pdo->prepare(
    "SELECT pv.*, pr.first_name, pr.last_name,
            (SELECT MAX(mine.created_at) FROM feedback mine WHERE mine.prediction_id = pv.prediction_id AND mine.user_id = ?) AS my_feedback_at
     $fromSql
     LEFT JOIN professionals pr ON pr.user_id = pv.doctor_id
     $whereSql
     ORDER BY {$sortable[$sort][1]} " . strtoupper($dir) . ", pv.prediction_id DESC
     LIMIT " . PREDICTIONS_PER_PAGE . ' OFFSET ' . (($predictionPage - 1) * PREDICTIONS_PER_PAGE)
);
$stmt->execute(array_merge([$me], $params));
$rows = $stmt->fetchAll();

audit($pdo, $me, 'view_predictions', null, null, $_SERVER['QUERY_STRING'] ?? null);

// Header cell for a sortable column: click to sort, click again to reverse
$sortHeader = function (string $key) use ($sortable, $sort, $dir): string {
    $isCurrent = $key === $sort;
    $nextDir   = $isCurrent && $dir === 'desc' ? 'asc' : 'desc';
    $ariaSort  = $isCurrent ? ($dir === 'asc' ? 'ascending' : 'descending') : 'none';
    $arrow     = $isCurrent ? '<span class="sort-arrow" aria-hidden="true">' . ($dir === 'asc' ? '▲' : '▼') . '</span>' : '';
    return '<th scope="col" aria-sort="' . $ariaSort . '">'
         . '<a class="sort-link" href="' . e(query_with(['sort' => $key, 'dir' => $nextDir, 'p' => null])) . '#results-heading">'
         . e($sortable[$key][0]) . $arrow . '</a></th>';
};

require __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1>Predictions</h1>
        <p><span class="num"><?= number_format($total) ?></span> matching</p>
    </div>
</div>

<!-- ===================================================================
     2. Quick tabs: switching tab keeps the filters and goes back to page 1
     =================================================================== -->
<nav class="tabs" aria-label="Quick views">
    <?php foreach ($tabs as $key => $tabName): ?>
    <a href="<?= e(query_with(['tab' => $key, 'p' => null])) ?>"
       class="tab<?= $key === $tab ? ' active' : '' ?>"
       <?= $key === $tab ? 'aria-current="page"' : '' ?>><?= e($tabName) ?></a>
    <?php endforeach; ?>
</nav>

<!-- ===================================================================
     3. Filters (one GET form; keeps the tab and sort, resets the page)
     =================================================================== -->
<form class="filters" method="get" action="#results-heading">
    <?= hidden_query_inputs(['status', 'cancer', 'dx', 'doctor', 'version', 'match', 'from', 'to', 'p']) ?>
    <div class="field">
        <label for="status">Status</label>
        <select id="status" name="status">
            <option value="">Any</option>
            <?php foreach (REVIEW_STATUSES as $value => $text): ?>
            <option value="<?= e($value) ?>" <?= $value === $status ? 'selected' : '' ?>><?= e($text) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="cancer">Model's call</label>
        <select id="cancer" name="cancer">
            <option value="">Any type</option>
            <?= cancer_type_options($pdo, $cancer) ?>
        </select>
    </div>
    <div class="field">
        <label for="dx">Diagnosis</label>
        <select id="dx" name="dx">
            <option value="">Any type</option>
            <?= cancer_type_options($pdo, $dx) ?>
        </select>
    </div>
    <div class="field">
        <label for="doctor">Doctor</label>
        <select id="doctor" name="doctor">
            <option value="">Any</option>
            <?php foreach ($doctorOptions as $id => $name): ?>
            <option value="<?= (int)$id ?>" <?= (string)$id === $doctor ? 'selected' : '' ?>><?= e($name) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="version">Model</label>
        <select id="version" name="version">
            <option value="">Any</option>
            <?php foreach ($versions as $v): ?>
            <option value="<?= e($v) ?>" <?= $v === $version ? 'selected' : '' ?>><?= e(model_label($v)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="match">Matched diagnosis</label>
        <select id="match" name="match">
            <option value="">Any</option>
            <option value="yes" <?= $match === 'yes' ? 'selected' : '' ?>>Yes</option>
            <option value="no" <?= $match === 'no' ? 'selected' : '' ?>>No</option>
        </select>
    </div>
    <div class="field">
        <label for="from">From</label>
        <input type="month" id="from" name="from" value="<?= e($from) ?>">
    </div>
    <div class="field">
        <label for="to">To</label>
        <input type="month" id="to" name="to" value="<?= e($to) ?>">
    </div>
    <button type="submit" class="btn btn-primary">Filter</button>
    <a class="btn" href="<?= e(query_with(array_fill_keys(['status', 'cancer', 'dx', 'doctor', 'version', 'match', 'from', 'to', 'p'], null))) ?>">Clear</a>
</form>

<?php if ($ignored): ?>
<p class="filter-notice" role="status">Ignored an invalid <?= e(implode(', ', $ignored)) ?>.</p>
<?php endif; ?>

<!-- ===================================================================
     4. Summary strip: shares of the matching rows
     =================================================================== -->
<div class="tiles summary-tiles">
    <div class="tile">
        <span class="tile-value"><?= pct($total ? $summary['matched'] : null) ?></span>
        <span class="tile-label">Model's call matched the diagnosis</span>
    </div>
    <div class="tile">
        <span class="tile-value"><?= pct($total ? $summary['reviewed'] : null) ?></span>
        <span class="tile-label">Reviewed by their oncologist</span>
    </div>
    <div class="tile">
        <span class="tile-value"><?= pct($total ? $summary['mine'] : null) ?></span>
        <span class="tile-label">Have my feedback</span>
    </div>
</div>

<!-- ===================================================================
     5. Table, 10 per page
     =================================================================== -->
<section class="card" aria-labelledby="results-heading">
    <h2 id="results-heading" class="visually-hidden">Matching predictions</h2>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th scope="col">Patient</th>
                    <?= $sortHeader('run') ?>
                    <th scope="col">Model</th>
                    <th scope="col">Doctor</th>
                    <th scope="col">Model's call</th>
                    <?= $sortHeader('confidence') ?>
                    <th scope="col">Diagnosis</th>
                    <th scope="col">Matched?</th>
                    <th scope="col">Status</th>
                    <th scope="col">Feedback</th>
                    <th scope="col">My feedback</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= patient_link((int)$r['patient_id']) ?></td>
                    <td><?= e(format_date($r['created_at'])) ?></td>
                    <td><?= e(model_label($r['model_used'])) ?></td>
                    <td><?= $r['first_name'] ? '<a href="research-doctor.php?id=' . (int)$r['doctor_id'] . '">Dr ' . e($r['first_name'] . ' ' . $r['last_name']) . '</a>' : '—' ?></td>
                    <td><?= cancer_chip($types[(int)$r['top_type_id']] ?? null) ?></td>
                    <td class="num"><?= pct($r['top_probability']) ?></td>
                    <td><?= cancer_chip($types[(int)$r['dx_type_id']] ?? null) ?></td>
                    <td><?= correct_mark($r['correct'] === null ? null : (bool)$r['correct']) ?></td>
                    <td><?= status_chip((bool)$r['reviewed']) ?></td>
                    <td class="num"><?= (int)$r['feedback_count'] ?></td>
                    <td>
                        <?php if ($r['my_feedback_at']): ?>
                        <a href="research-prediction.php?id=<?= (int)$r['prediction_id'] ?>#feedback-form">Submitted <?= e(format_date($r['my_feedback_at'])) ?></a>
                        <?php else: ?>
                        <a href="research-prediction.php?id=<?= (int)$r['prediction_id'] ?>#feedback-form">Give feedback</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                <tr><td colspan="11" class="empty">
                    <?php if ($tab === 'needs' && count($where) === 1): /* only the tab's own condition, no filters */ ?>
                    You've given feedback on every prediction here. <a href="<?= e(query_with(['tab' => 'all', 'p' => null])) ?>">See all predictions</a>
                    <?php else: ?>
                    No predictions match these filters.
                    <?php endif; ?>
                </td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?= pager('p', $predictionPage, PREDICTIONS_PER_PAGE, $total, 'results-heading', 'predictions') ?>
</section>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
