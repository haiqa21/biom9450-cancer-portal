<?php
/*
 * Predictions still "awaiting_diagnosis" never appear: there is no model
 * result yet, and showing them would invite feedback before the doctor has
 * committed to their own diagnosis.
 */

require_once __DIR__ . '/../../includes/helpers.php';   // e(), query_with(), hidden_query_inputs(), lists

// ---------------------------------------------------------------------------
// Signed-in user
// ---------------------------------------------------------------------------
// TODO: delete this line and add a researcher-only check from the database.
$currentUser = ['user_id' => 0, 'name' => 'Dr Placeholder', 'role' => 'researcher'];

// ---------------------------------------------------------------------------
// Settings read by includes/header.php
// ---------------------------------------------------------------------------
$pageTitle  = 'Predictions';
$activePage = 'research-predictions';
$pageStyles = ['research-predictions.css'];

const PREDICTIONS_PER_PAGE = 10;

// ---------------------------------------------------------------------------
// Quick tabs
// ---------------------------------------------------------------------------
// Each tab is a preset view. tab key => label. The query adds one condition
// per tab:
//   needs     -> no feedback from me yet (NOT EXISTS feedback by :me)
//   all       -> nothing extra
//   disagree  -> model's top call differs from the doctor's initial diagnosis
//   released  -> status = 'released'
$tabs = [
    'needs'    => 'Needs my feedback',
    'all'      => 'All',
    'disagree' => 'Disagreements',
    'released' => 'Released',
];
$tab = array_key_exists($_GET['tab'] ?? '', $tabs) ? $_GET['tab'] : 'needs';

// ---------------------------------------------------------------------------
// Filter options
// ---------------------------------------------------------------------------
// Status: every status except awaiting_diagnosis (those rows never show here).
$statusOptions = array_diff_key(PREDICTION_STATUSES, ['awaiting_diagnosis' => true]);

// TODO: doctors with at least one initial diagnosis: [user_id => 'Dr First Last']
$doctorOptions = [];
// TODO: SELECT DISTINCT model_version FROM predictions ORDER BY model_version DESC
$versionOptions = [];

// Agreement here means "did the model's top call match the doctor's initial diagnosis"
$agreementFilterOptions = ['yes' => 'Agree', 'no' => 'Disagree'];

// ---------------------------------------------------------------------------
// Read and check the filters
// ---------------------------------------------------------------------------
// Anything not in its option list is dropped and noted in $ignored, which is
// shown above the table so the researcher knows a filter was not applied.
$ignored = [];

$pick = function (string $key, array $allowed, string $label) use (&$ignored): string {
    $value = trim((string)($_GET[$key] ?? ''));
    if ($value === '') {
        return '';
    }
    if (!array_key_exists($value, $allowed) && !in_array($value, $allowed, true)) {
        $ignored[] = $label;
        return '';
    }
    return $value;
};

$status    = $pick('status', $statusOptions, 'status');
$cancer    = $pick('cancer', CANCER_TYPES, 'cancer type');
$doctor    = $pick('doctor', $doctorOptions, 'doctor');
$version   = $pick('version', $versionOptions, 'model version');
$agreement = $pick('agreement', $agreementFilterOptions, 'agreement');

// Months come from <input type="month">, which sends "YYYY-MM".
$month = function (string $key, string $label) use (&$ignored): string {
    $value = trim((string)($_GET[$key] ?? ''));
    if ($value !== '' && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)) {
        $ignored[] = $label;
        return '';
    }
    return $value;
};
$from = $month('from', 'from month');
$to   = $month('to', 'to month');

// "From" after "to" can never match anything, so drop both and say so
if ($from !== '' && $to !== '' && $from > $to) {
    $ignored[] = 'date range (from is after to)';
    $from = $to = '';
}

// ---------------------------------------------------------------------------
// Sorting and paging
// ---------------------------------------------------------------------------
// Only two sortable columns, newest / most confident first by default.
// Later each key picks a fixed ORDER BY
$sortable = ['run' => 'Run', 'confidence' => 'Confidence'];
$sort = array_key_exists($_GET['sort'] ?? '', $sortable) ? $_GET['sort'] : 'run';
$dir  = ($_GET['dir'] ?? '') === 'asc' ? 'asc' : 'desc';
$predictionPage = max(1, (int)($_GET['p'] ?? 1));   // not $page: header.php's nav loop overwrites $page

// Header cell for a sortable column: click to sort, click again to reverse
$sortHeader = function (string $key) use ($sortable, $sort, $dir): string {
    $isCurrent = $key === $sort;
    $nextDir   = $isCurrent && $dir === 'desc' ? 'asc' : 'desc';
    $ariaSort  = $isCurrent ? ($dir === 'asc' ? 'ascending' : 'descending') : 'none';
    $arrow     = $isCurrent ? '<span class="sort-arrow" aria-hidden="true">' . ($dir === 'asc' ? '▲' : '▼') . '</span>' : '';
    return '<th scope="col" aria-sort="' . $ariaSort . '">'
         . '<a class="sort-link" href="' . e(query_with(['sort' => $key, 'dir' => $nextDir, 'p' => null])) . '#results-heading">'
         . e($sortable[$key]) . $arrow . '</a></th>';
};

// TODO: record the view: audit_logs action 'view_predictions', detail = the query string

require __DIR__ . '/../../includes/header.php';
?>

<!-- ===================================================================
     1. Header
     =================================================================== -->
<div class="page-header">
    <div>
        <h1>Predictions</h1>
        <!-- TODO: COUNT(*) of rows matching the tab and filters -->
        <p><span class="num">—</span> matching</p>
    </div>
</div>

<!-- ===================================================================
     2. Quick tabs
     =================================================================== -->
<nav class="tabs" aria-label="Quick views">
    <?php foreach ($tabs as $key => $label): ?>
    <a href="<?= e(query_with(['tab' => $key, 'p' => null])) ?>"
       class="tab<?= $key === $tab ? ' active' : '' ?>"
       <?= $key === $tab ? 'aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>

<!-- ===================================================================
     3. Filters (one GET form; keeps the tab and sort, resets the page)
     =================================================================== -->
<form class="filters" method="get" action="#results-heading">
    <?= hidden_query_inputs(['status', 'cancer', 'doctor', 'version', 'agreement', 'from', 'to', 'p']) ?>
    <div class="field">
        <label for="status">Status</label>
        <select id="status" name="status">
            <option value="">Any</option>
            <?php foreach ($statusOptions as $value => $label): ?>
            <option value="<?= e($value) ?>" <?= $value === $status ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="cancer">Model's call</label>
        <select id="cancer" name="cancer">
            <option value="">Any type</option>
            <?php foreach (CANCER_TYPES as $c): ?>
            <option <?= $c === $cancer ? 'selected' : '' ?>><?= e($c) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="doctor">Doctor</label>
        <select id="doctor" name="doctor">
            <option value="">Any</option>
            <?php foreach ($doctorOptions as $id => $name): ?>
            <option value="<?= e($id) ?>" <?= (string)$id === $doctor ? 'selected' : '' ?>><?= e($name) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="version">Model version</label>
        <select id="version" name="version">
            <option value="">Any</option>
            <?php foreach ($versionOptions as $v): ?>
            <option <?= $v === $version ? 'selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="agreement">Model vs doctor</label>
        <select id="agreement" name="agreement">
            <option value="">Any</option>
            <?php foreach ($agreementFilterOptions as $value => $label): ?>
            <option value="<?= e($value) ?>" <?= $value === $agreement ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
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
    <a class="btn" href="<?= e(query_with(['status' => null, 'cancer' => null, 'doctor' => null, 'version' => null, 'agreement' => null, 'from' => null, 'to' => null, 'p' => null])) ?>">Clear</a>
</form>

<?php if ($ignored): ?>
<!-- A filter value from the URL wasn't valid, so it wasn't applied -->
<p class="filter-notice" role="status">Ignored an invalid <?= e(implode(', ', $ignored)) ?>.</p>
<?php endif; ?>

<!-- ===================================================================
     4. Summary strip: three shares of the matching rows
     TODO: compute over the same WHERE as the table (no LIMIT).
     =================================================================== -->
<div class="tiles summary-tiles">
    <div class="tile">
        <span class="tile-value">—</span>
        <span class="tile-label">Model and doctor agreed</span>
    </div>
    <div class="tile">
        <span class="tile-value">—</span>
        <span class="tile-label">Report released</span>
    </div>
    <div class="tile">
        <span class="tile-value">—</span>
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
                    <th scope="col">Sample</th>
                    <?= $sortHeader('run') ?>
                    <th scope="col">Doctor</th>
                    <th scope="col">Initial diagnosis</th>
                    <th scope="col">Model's top prediction</th>
                    <?= $sortHeader('confidence') ?>
                    <th scope="col">Agree?</th>
                    <th scope="col">Status</th>
                    <th scope="col">Feedback</th>
                    <th scope="col">My feedback</th>
                </tr>
            </thead>
            <tbody>
                <!-- TODO: foreach over PREDICTIONS_PER_PAGE rows.
                     Run = format_month(created_at). Agree? = ✓ / ✗ with visually-hidden "yes"/"no".
                     My feedback = "Submitted <format_date(updated_at)>" or a "Give feedback" link.
                     Empty states:
                       needs tab:  "You've given feedback on every available prediction." + link to All
                       filters:    "No predictions match these filters."
                       none at all: "No predictions have been run yet." -->
                <?php foreach (['pending_review', 'reviewed', 'released'] as $i => $placeholderStatus): ?>
                <tr>
                    <td><a class="row-link" href="research-patient.php?id=1">Patient —</a></td>
                    <td>Sample —</td>
                    <td>—</td>
                    <td><a href="research-doctor.php?id=1">Dr —</a></td>
                    <td>—</td>
                    <td>—</td>
                    <td class="num">—</td>
                    <td>
                        <?php if ($i === 0): ?>
                        <span aria-hidden="true" class="mark-no">✗</span><span class="visually-hidden">No</span>
                        <?php else: ?>
                        <span aria-hidden="true" class="mark-yes">✓</span><span class="visually-hidden">Yes</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="chip status-<?= $placeholderStatus ?>"><?= e(PREDICTION_STATUSES[$placeholderStatus]) ?></span></td>
                    <td class="num">—</td>
                    <td><a href="research-prediction.php?id=1#feedback-form">Give feedback</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- TODO: real totals; show Next only when p < ceil(total / PREDICTIONS_PER_PAGE) -->
    <nav class="pager" aria-label="Prediction pages">
        <span class="muted num">— of — predictions</span>
        <span class="pager-links">
            <?php if ($predictionPage > 1): ?>
            <a class="btn" href="<?= e(query_with(['p' => $predictionPage - 1])) ?>#results-heading">Previous</a>
            <?php endif; ?>
            <a class="btn" href="<?= e(query_with(['p' => $predictionPage + 1])) ?>#results-heading">Next</a>
        </span>
    </nav>
</section>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
