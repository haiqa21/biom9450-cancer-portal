<?php

require_once __DIR__ . '/../../includes/helpers.php';   // e(), query_with(), lists

// ---------------------------------------------------------------------------
// Signed-in user
// ---------------------------------------------------------------------------
// TODO: delete this line and add a researcher-only check from the database.
$currentUser = ['user_id' => 0, 'name' => 'Dr Placeholder', 'role' => 'researcher'];

// ---------------------------------------------------------------------------
// Settings read by includes/header.php
// ---------------------------------------------------------------------------
$pageTitle  = 'Model performance';
$activePage = 'model-performance';
$pageStyles = ['model-performance.css'];

// Fewer released predictions than this and the charts would mislead, so
// they're replaced with a "too few to chart" message (the tables still show).
const MIN_TO_CHART = 10;

// Confidence bands for the calibration check: [from %, to %].
// The top probability of six classes is always at least ~17%, so the first
// band covers everything below 50%.
const CONFIDENCE_BANDS = [[0, 50], [50, 60], [60, 70], [70, 80], [80, 90], [90, 100]];

// ---------------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------------
// TODO: SELECT DISTINCT model_version FROM predictions ORDER BY model_version DESC
// The first one is the latest and the default.
$versionOptions = [];
$version = in_array($_GET['version'] ?? '', $versionOptions, true) ? $_GET['version'] : ($versionOptions[0] ?? '');

$cancer = in_array($_GET['cancer'] ?? '', CANCER_TYPES, true) ? $_GET['cancer'] : '';

// "YYYY-MM" from <input type="month">; anything else is ignored
$monthOrBlank = fn(string $key): string =>
    preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)($_GET[$key] ?? '')) ? $_GET[$key] : '';
$from = $monthOrBlank('from');
$to   = $monthOrBlank('to');
if ($from !== '' && $to !== '' && $from > $to) {
    [$from, $to] = [$to, $from];   // if swapped by mistake: just swap them back
}

// TODO: COUNT(*) FROM released_outcomes with the filters; drives the states below
//   0              -> "No reports have been released yet, so there's nothing to measure against."
//   1 .. MIN_TO_CHART-1 -> tables only, charts say "Too few released reports to chart (n = 6)."
$releasedCount = null;

// TODO: record the view: audit_logs action 'view_model_performance', detail = the query string

require __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1>Model performance</h1>
    </div>
</div>

<!-- ===================================================================
     1. Filters
     =================================================================== -->
<form class="filters" method="get" action="">
    <div class="field">
        <label for="version">Model version</label>
        <select id="version" name="version">
            <?php if (!$versionOptions): ?>
            <option value="">Latest</option>
            <?php endif; ?>
            <?php foreach ($versionOptions as $i => $v): ?>
            <option <?= $v === $version ? 'selected' : '' ?>><?= e($v) ?><?= $i === 0 ? ' (latest)' : '' ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="cancer">Cancer type</label>
        <select id="cancer" name="cancer">
            <option value="">All</option>
            <?php foreach (CANCER_TYPES as $c): ?>
            <option <?= $c === $cancer ? 'selected' : '' ?>><?= e($c) ?></option>
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
    <a class="btn" href="model-performance.php">Clear</a>
</form>

<!-- ===================================================================
     2. Headline tiles. Each accuracy shows its n underneath, because
     "90%" of 10 cases means much less than 90% of 200.
     =================================================================== -->
<div class="tiles">
    <div class="tile">
        <span class="tile-value">—</span>
        <span class="tile-label">Model accuracy</span>
        <span class="muted small-text">n = —</span>
    </div>
    <div class="tile">
        <span class="tile-value">—</span>
        <span class="tile-label">Doctor initial accuracy</span>
        <span class="muted small-text">n = —</span>
    </div>
    <div class="tile">
        <span class="tile-value">—</span>
        <span class="tile-label">Released predictions counted</span>
    </div>
    <div class="tile">
        <span class="tile-value">—</span>
        <span class="tile-label">Feedback marked for retraining</span>
    </div>
</div>

<!-- ===================================================================
     3. Confusion matrix
     =================================================================== -->
<section class="card" aria-labelledby="confusion-heading">
    <div class="card-header">
        <h2 id="confusion-heading">Model Diagnosis Accuracy</h2>
        <span class="muted small-text">Rows: final report · Columns: model's call</span>
    </div>

    <div id="chart-confusion" class="chart-slot" role="img"
         aria-label="Confusion matrix of the final report's cancer type against the model's top prediction">
        <!-- Fallback: the 6 x 6 counts as a table. Correct calls are on the diagonal.
             Each count links to the predictions with that final type and model call.
             TODO: cell counts; the predictions page still needs a "final" filter for these links. -->
        <div class="table-wrap">
            <table class="confusion-table">
                <thead>
                    <tr>
                        <th scope="col"><span class="visually-hidden">Final report</span></th>
                        <?php foreach (CANCER_TYPES as $model): ?>
                        <th scope="col"><?= e($model) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (CANCER_TYPES as $final): ?>
                    <tr>
                        <th scope="row">
                            <span class="cancer-name"><span class="dot dot-<?= e(strtolower($final)) ?>" aria-hidden="true"></span><?= e($final) ?></span>
                        </th>
                        <?php foreach (CANCER_TYPES as $model): ?>
                        <td class="num<?= $model === $final ? ' diagonal' : '' ?>">
                            <a href="research-predictions.php?tab=released&amp;cancer=<?= e($model) ?>&amp;final=<?= e($final) ?>">—</a>
                        </td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <!-- Shape: {"cancer_types":[...],"cells":[[12,1,0,0,2,0], ...]}  rows = final report, columns = model -->
    <script type="application/json" id="chart-confusion-data"><?= json_encode(
        [
            'cancer_types' => CANCER_TYPES,
            'cells'        => array_fill(0, count(CANCER_TYPES), array_fill(0, count(CANCER_TYPES), 0)),
        ],
        JSON_HEX_TAG | JSON_HEX_AMP
    ) ?></script>
</section>

<!-- ===================================================================
     4 + 5. Accuracy by version and by cancer type, side by side
     =================================================================== -->
<div class="grid-2">

    <section class="card" aria-labelledby="version-heading">
        <div class="card-header">
            <h2 id="version-heading">Accuracy by model version</h2>
        </div>
        <!-- One line, model accuracy per version in release order; a dashed line
             marks the doctors' initial accuracy over the same predictions. -->
        <div id="chart-version-accuracy" class="chart-slot chart-short" role="img"
             aria-label="Model accuracy for each model version, with doctors' initial accuracy for reference">
            <!-- Placeholder until the chart script draws here; the table below has the numbers -->
            <p class="chart-placeholder">Line chart: model accuracy per version, doctor accuracy as a dashed line</p>
        </div>
        <!-- Shape: {"versions":["v1.0","v1.1"],"model_pct":[...],"n":[...],"doctor_pct":76.2} -->
        <script type="application/json" id="chart-version-accuracy-data"><?= json_encode(
            ['versions' => [], 'model_pct' => [], 'n' => [], 'doctor_pct' => null],
            JSON_HEX_TAG | JSON_HEX_AMP
        ) ?></script>
        <!-- The table always shows; with one version the chart is hidden and the table has one row -->
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th scope="col">Version</th>
                        <th scope="col">Released</th>
                        <th scope="col">Model correct</th>
                        <th scope="col">Doctor correct</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- TODO: one row per version -->
                    <tr><td>—</td><td class="num">—</td><td class="num">—</td><td class="num">—</td></tr>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card" aria-labelledby="type-heading">
        <div class="card-header">
            <h2 id="type-heading">Accuracy by cancer type</h2>
        </div>
        <!-- Dumbbell per cancer type: model % and doctor % joined by a line.
             Same id and shape as on the dashboard, so one chart function draws both. -->
        <div id="chart-type-accuracy" class="chart-slot chart-short" role="img"
             aria-label="For each cancer type, model accuracy and doctor initial accuracy">
            <p class="chart-placeholder">Dumbbell chart: model % and doctor % per cancer type</p>
        </div>
        <script type="application/json" id="chart-type-accuracy-data"><?= json_encode(
            ['types' => array_map(
                fn($c) => ['type' => $c, 'model_pct' => null, 'doctor_pct' => null, 'n' => 0],
                CANCER_TYPES
            )],
            JSON_HEX_TAG | JSON_HEX_AMP
        ) ?></script>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th scope="col">Cancer type</th>
                        <th scope="col">n</th>
                        <th scope="col">Model</th>
                        <th scope="col">Doctor</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (CANCER_TYPES as $c): ?>
                    <tr>
                        <td><span class="cancer-name"><span class="dot dot-<?= e(strtolower($c)) ?>" aria-hidden="true"></span><?= e($c) ?></span></td>
                        <td class="num">—</td>
                        <td class="num">—</td>
                        <td class="num">—</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<!-- ===================================================================
     6 + 7. Confidence check and feedback summary
     =================================================================== -->
<div class="grid-2">

    <section class="card" aria-labelledby="calibration-heading">
        <div class="card-header">
            <h2 id="calibration-heading">Confidence Summary</h2>
        </div>
        <!-- Bars by confidence band showing % correct, with a diagonal where a
             perfectly calibrated model would sit. Bands with fewer than 5
             predictions are drawn hatched and labelled "few cases". -->
        <div id="chart-calibration" class="chart-slot chart-short" role="img"
             aria-label="Share of correct predictions in each model confidence band">
            <p class="chart-placeholder">Bar chart: % correct per confidence band, with a perfect-calibration diagonal</p>
        </div>
        <!-- Shape: {"bands":[{"from":50,"to":60,"n":4,"correct_pct":50.0}, ...]} -->
        <script type="application/json" id="chart-calibration-data"><?= json_encode(
            ['bands' => array_map(
                fn($b) => ['from' => $b[0], 'to' => $b[1], 'n' => 0, 'correct_pct' => null],
                CONFIDENCE_BANDS
            )],
            JSON_HEX_TAG | JSON_HEX_AMP
        ) ?></script>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th scope="col">Model confidence</th>
                        <th scope="col">Predictions</th>
                        <th scope="col">Correct</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (CONFIDENCE_BANDS as [$lo, $hi]): ?>
                    <tr>
                        <td class="num"><?= $lo === 0 ? 'Under ' . $hi : $lo . '–' . $hi ?>%</td>
                        <td class="num">—</td>
                        <td class="num">—</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card" aria-labelledby="fb-summary-heading">
        <div class="card-header">
            <h2 id="fb-summary-heading">Feedback summary</h2>
            <span class="muted small-text">All feedback, any status</span>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th scope="col">Role</th>
                        <?php foreach (AGREEMENT_OPTIONS as $label): ?>
                        <th scope="col"><?= e($label) ?></th>
                        <?php endforeach; ?>
                        <th scope="col">Avg usefulness</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- TODO: SELECT author_role, agreement, COUNT(*), AVG(usefulness) FROM feedback GROUP BY 1, 2 -->
                    <?php foreach (['Oncologists', 'Researchers'] as $role): ?>
                    <tr>
                        <th scope="row"><?= e($role) ?></th>
                        <?php foreach (AGREEMENT_OPTIONS as $label): ?>
                        <td class="num">—</td>
                        <?php endforeach; ?>
                        <td class="num">— / 5</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
