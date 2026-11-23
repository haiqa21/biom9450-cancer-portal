<?php
/*
 * Model performance (model-performance.php)
 * How well each model version's call matches the recorded diagnosis, overall,
 * by organ group and cancer type, and by how confident the model was. Read only.
 *
 *   1. Filters               model version (default: newest), diagnosis, month from-to
 *   2. Headline tiles        accuracy, balanced accuracy, predictions counted, average rating
 *   3. Confusion             organ group of the diagnosis vs of the call -> #chart-confusion
 *                            + the most common mix-ups between individual types
 *   4. Accuracy by version   -> #chart-version-accuracy
 *   5. Accuracy by cancer type (all 33)  -> #chart-type-accuracy
 *   6. Confidence check      does a higher model % mean it's right more often? -> #chart-calibration
 *   7. Feedback summary      ratings from oncologists vs researchers
 *
 * BALANCED ACCURACY is the average of the accuracy for each cancer type. The 33 types
 * are very uneven (BRCA has 223 patients, CHOL 5), so plain accuracy is mostly about the
 * big types; balanced accuracy weighs every type equally.
 */

require_once __DIR__ . '/../../includes/research.php';

$currentUser = research_require_login();       // also connects: $pdo

$pageTitle  = 'Model performance';
$activePage = 'model-performance';
$pageStyles = ['model-performance.css'];

const FEW_CASES = 10;      // fewer predictions than this for a type: shown, but marked "few cases"
const CONFIDENCE_BANDS = [[0, 50], [50, 60], [60, 70], [70, 80], [80, 90], [90, 101]];
const MIX_UPS_SHOWN = 10;

$types = cancer_types($pdo);
$versions = model_versions($pdo);

// ---------------------------------------------------------------------------
// 1. Filters (values must come from their lists)
// ---------------------------------------------------------------------------
$version = in_array($_GET['version'] ?? '', $versions, true) ? $_GET['version'] : ($versions[0] ?? '');
$dx = isset($types[(int)($_GET['dx'] ?? 0)]) ? (int)$_GET['dx'] : 0;
$monthOrBlank = fn(string $key): string =>
    preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)($_GET[$key] ?? '')) ? $_GET[$key] : '';
$from = $monthOrBlank('from');
$to   = $monthOrBlank('to');
if ($from !== '' && $to !== '' && $from > $to) {
    [$from, $to] = [$to, $from];      // swapped by mistake: swap them back
}

// The filters shared by every query below (diagnosis and dates; version is added per query)
$where = ['pv.dx_type_id IS NOT NULL'];
$params = [];
if ($dx)          { $where[] = 'pv.dx_type_id = ?'; $params[] = $dx; }
if ($from !== '') { $where[] = 'pv.created_at >= ?'; $params[] = $from . '-01'; }
if ($to !== '')   { $where[] = 'pv.created_at < ? + INTERVAL 1 MONTH'; $params[] = $to . '-01'; }
$base = 'FROM (' . PREDICTION_SQL . ') pv WHERE ' . implode(' AND ', $where);

// ---------------------------------------------------------------------------
// Per cancer type for the chosen version (drives tiles, confusion and the type table)
// ---------------------------------------------------------------------------
// the version parameter goes last: $base's own parameters come first in the SQL
$stmt = $pdo->prepare("SELECT pv.dx_type_id, pv.top_type_id, COUNT(*) AS n $base AND pv.model_used = ? GROUP BY pv.dx_type_id, pv.top_type_id");
$stmt->execute(array_merge($params, [$version]));
$pairs = $stmt->fetchAll();

$byType = [];          // dx type id => ['n', 'correct']
$groupKeys = array_keys(CANCER_GROUPS);
$groupCells = array_fill_keys($groupKeys, array_fill_keys($groupKeys, 0));   // [dx group][call group]
$mixUps = [];
foreach ($pairs as $row) {
    $dxType = (int)$row['dx_type_id'];
    $call = (int)$row['top_type_id'];
    $n = (int)$row['n'];
    $byType[$dxType]['n'] = ($byType[$dxType]['n'] ?? 0) + $n;
    $byType[$dxType]['correct'] = ($byType[$dxType]['correct'] ?? 0) + ($dxType === $call ? $n : 0);
    $groupCells[$types[$dxType]['group']][$types[$call]['group']] += $n;
    if ($dxType !== $call) {
        $mixUps[] = ['dx' => $dxType, 'call' => $call, 'n' => $n];
    }
}
usort($mixUps, fn($a, $b) => $b['n'] <=> $a['n']);
$counted = array_sum(array_column($byType, 'n'));
$accuracy = $counted ? array_sum(array_column($byType, 'correct')) / $counted : null;
$balanced = $byType ? array_sum(array_map(fn($t) => $t['correct'] / $t['n'], $byType)) / count($byType) : null;

// ---------------------------------------------------------------------------
// Accuracy by version (same diagnosis/date filters, every version)
// ---------------------------------------------------------------------------
$stmt = $pdo->prepare("SELECT pv.model_used, pv.dx_type_id, COUNT(*) AS n, SUM(pv.correct) AS correct, MIN(pv.created_at) AS first_run
                       $base GROUP BY pv.model_used, pv.dx_type_id");
$stmt->execute($params);
$versionStats = [];
foreach ($stmt as $row) {
    $v = &$versionStats[$row['model_used']];
    $v['n'] = ($v['n'] ?? 0) + (int)$row['n'];
    $v['correct'] = ($v['correct'] ?? 0) + (int)$row['correct'];
    $v['per_type'][] = $row['correct'] / $row['n'];
    $v['first_run'] = min($v['first_run'] ?? $row['first_run'], $row['first_run']);
    unset($v);
}
uasort($versionStats, fn($a, $b) => strcmp($a['first_run'], $b['first_run']));   // release order

// Average rating per version
$stmt = $pdo->prepare("SELECT p.model_used, AVG(f.feedback_rating) FROM feedback f
                       JOIN predictions p ON p.prediction_id = f.prediction_id GROUP BY p.model_used");
$stmt->execute();
$versionRatings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// ---------------------------------------------------------------------------
// Confidence bands for the chosen version
// ---------------------------------------------------------------------------
$stmt = $pdo->prepare("SELECT FLOOR(pv.top_probability * 100) AS pct, pv.correct $base AND pv.model_used = ?");
$stmt->execute(array_merge($params, [$version]));
$bands = array_map(fn($b) => ['from' => $b[0], 'to' => min($b[1], 100), 'n' => 0, 'correct' => 0], CONFIDENCE_BANDS);
foreach ($stmt as $row) {
    foreach (CONFIDENCE_BANDS as $i => [$lo, $hi]) {
        if ($row['pct'] >= $lo && $row['pct'] < $hi) {
            $bands[$i]['n']++;
            $bands[$i]['correct'] += (int)$row['correct'];
            break;
        }
    }
}

// ---------------------------------------------------------------------------
// Feedback summary by role (all feedback on the chosen version)
// ---------------------------------------------------------------------------
$stmt = $pdo->prepare("SELECT pr.professional_type, f.feedback_rating, COUNT(*) AS n
                       FROM feedback f
                       JOIN predictions p    ON p.prediction_id = f.prediction_id
                       JOIN professionals pr ON pr.user_id = f.user_id
                       WHERE p.model_used = ?
                       GROUP BY pr.professional_type, f.feedback_rating");
$stmt->execute([$version]);
$roleRatings = ['oncologist' => array_fill_keys(range(1, 5), 0), 'researcher' => array_fill_keys(range(1, 5), 0)];
foreach ($stmt as $row) {
    $roleRatings[$row['professional_type']][(int)$row['feedback_rating']] = (int)$row['n'];
}
$average = fn(array $counts) => array_sum($counts) ? array_sum(array_map(fn($r, $n) => $r * $n, array_keys($counts), $counts)) / array_sum($counts) : null;

audit($pdo, $currentUser['user_id'], 'view_model_performance', null, null, $_SERVER['QUERY_STRING'] ?? null);

// Link to the matching predictions (for mix-ups)
$predictionsLink = fn(int $dxType, int $call) => 'research-predictions.php?' . http_build_query(
    ['tab' => 'all', 'version' => $version, 'dx' => $dxType, 'cancer' => $call]);

require __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1>Model performance</h1>
        <p>How often the model's call matched the recorded diagnosis</p>
    </div>
</div>

<!-- ===================================================================
     1. Filters
     =================================================================== -->
<form class="filters" method="get" action="">
    <div class="field">
        <label for="version">Model</label>
        <select id="version" name="version">
            <?php foreach ($versions as $i => $v): ?>
            <option value="<?= e($v) ?>" <?= $v === $version ? 'selected' : '' ?>><?= e(model_label($v)) ?><?= $i === 0 ? ' (newest)' : '' ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="dx">Diagnosis</label>
        <select id="dx" name="dx">
            <option value="">All types</option>
            <?= cancer_type_options($pdo, $dx) ?>
        </select>
    </div>
    <div class="field">
        <label for="from">Run from</label>
        <input type="month" id="from" name="from" value="<?= e($from) ?>">
    </div>
    <div class="field">
        <label for="to">to</label>
        <input type="month" id="to" name="to" value="<?= e($to) ?>">
    </div>
    <button type="submit" class="btn btn-primary">Filter</button>
    <a class="btn" href="model-performance.php">Clear</a>
</form>

<?php if (!$counted): ?>
<section class="card not-available">
    <h2>Nothing to measure</h2>
    <p class="muted">No predictions match these filters.</p>
</section>
<?php else: ?>

<!-- ===================================================================
     2. Headline tiles. Accuracy shows its n: "90%" of 10 means much less than of 2,000.
     =================================================================== -->
<div class="tiles">
    <div class="tile">
        <span class="tile-value"><?= pct($accuracy, 1) ?></span>
        <span class="tile-label">Accuracy</span>
        <span class="muted small-text">n = <?= number_format($counted) ?></span>
    </div>
    <div class="tile">
        <span class="tile-value"><?= pct($balanced, 1) ?></span>
        <span class="tile-label">Balanced accuracy</span>
        <span class="muted small-text">average over <?= count($byType) ?> cancer types</span>
    </div>
    <div class="tile">
        <span class="tile-value"><?= number_format($counted) ?></span>
        <span class="tile-label">Predictions counted</span>
    </div>
    <div class="tile">
        <span class="tile-value"><?= isset($versionRatings[$version]) ? number_format((float)$versionRatings[$version], 1) . ' / 5' : '—' ?></span>
        <span class="tile-label">Average rating from reviewers</span>
    </div>
</div>

<!-- ===================================================================
     3. Confusion: organ group of the diagnosis (rows) vs of the model's call (columns)

     CHART SLOT CONVENTION (for the visualisation teammate):
       <div id="chart-x">          chart drawn here; holds an HTML fallback
       <script id="chart-x-data">  the chart's data as JSON
     =================================================================== -->
<section class="card" aria-labelledby="confusion-heading">
    <div class="card-header">
        <h2 id="confusion-heading">What the model called each diagnosis</h2>
        <span class="muted small-text">Rows: diagnosis · Columns: model's call · by organ group</span>
    </div>

    <div id="chart-confusion" class="chart-slot" role="img"
         aria-label="Counts of predictions by the organ group of the diagnosis and of the model's call">
        <div class="table-wrap">
            <table class="confusion-table">
                <thead>
                    <tr>
                        <th scope="col"><span class="visually-hidden">Diagnosis</span></th>
                        <?php foreach (CANCER_GROUPS as $key => [$groupName]): ?>
                        <th scope="col" title="<?= e($groupName) ?>"><span class="dot dot-group-<?= e($key) ?>" aria-hidden="true"></span> <?= e(GROUP_SHORT_NAMES[$key] ?? $groupName) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (CANCER_GROUPS as $rowKey => [$rowName]): ?>
                    <tr>
                        <th scope="row"><span class="cancer-name"><span class="dot dot-group-<?= e($rowKey) ?>" aria-hidden="true"></span><?= e($rowName) ?></span></th>
                        <?php foreach ($groupKeys as $colKey): ?>
                        <td class="num<?= $rowKey === $colKey ? ' diagonal' : '' ?>"><?= $groupCells[$rowKey][$colKey] ? number_format($groupCells[$rowKey][$colKey]) : '' ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <!-- Shape: {"groups":[{"group":"breast_gyn","label":"..."},...],"cells":[[...8 counts...], ...]}
         rows = diagnosis group, columns = call group, same order as groups;
         "types" adds the full 33 x 33 counts for a drill-down: rows = diagnosis, columns = call -->
    <script type="application/json" id="chart-confusion-data"><?= json_encode(
        [
            'groups' => array_map(fn($k) => ['group' => $k, 'label' => CANCER_GROUPS[$k][0]], $groupKeys),
            'cells'  => array_values(array_map('array_values', $groupCells)),
            'types'  => [
                'codes'  => array_values(array_map(fn($t) => $t['code'], $types)),
                'counts' => (function () use ($types, $pairs) {
                    $index = array_flip(array_keys($types));
                    $grid = array_fill(0, count($types), array_fill(0, count($types), 0));
                    foreach ($pairs as $row) {
                        $grid[$index[(int)$row['dx_type_id']]][$index[(int)$row['top_type_id']]] = (int)$row['n'];
                    }
                    return $grid;
                })(),
            ],
        ],
        JSON_HEX_TAG | JSON_HEX_AMP
    ) ?></script>

    <!-- Most common mix-ups between individual cancer types; each opens the matching predictions -->
    <h3 class="mixups-heading">Most common mix-ups</h3>
    <?php if ($mixUps): ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th scope="col">Diagnosis</th>
                    <th scope="col">Model called it</th>
                    <th scope="col">Predictions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array_slice($mixUps, 0, MIX_UPS_SHOWN) as $mix): ?>
                <tr>
                    <td><?= cancer_chip($types[$mix['dx']]) ?> <span class="muted small-text"><?= e($types[$mix['dx']]['name']) ?></span></td>
                    <td><?= cancer_chip($types[$mix['call']]) ?> <span class="muted small-text"><?= e($types[$mix['call']]['name']) ?></span></td>
                    <td class="num"><a href="<?= e($predictionsLink($mix['dx'], $mix['call'])) ?>"><?= number_format($mix['n']) ?></a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <p class="muted">No mix-ups: every call matched the diagnosis.</p>
    <?php endif; ?>
</section>

<!-- ===================================================================
     4. Accuracy by model version (release order)
     =================================================================== -->
<section class="card" aria-labelledby="version-heading">
    <div class="card-header">
        <h2 id="version-heading">Accuracy by model version</h2>
    </div>
    <div id="chart-version-accuracy" class="chart-slot chart-short" role="img"
         aria-label="Accuracy and balanced accuracy for each model version">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th scope="col">Model</th>
                        <th scope="col">Predictions</th>
                        <th scope="col">Accuracy</th>
                        <th scope="col">Balanced accuracy</th>
                        <th scope="col">Average rating</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($versionStats as $model => $v): ?>
                    <tr<?= $model === $version ? ' class="selected"' : '' ?>>
                        <td><a href="<?= e(query_with(['version' => $model])) ?>"><?= e(model_label($model)) ?></a></td>
                        <td class="num"><?= number_format($v['n']) ?></td>
                        <td class="num"><?= pct($v['correct'] / $v['n'], 1) ?></td>
                        <td class="num"><?= pct(array_sum($v['per_type']) / count($v['per_type']), 1) ?></td>
                        <td class="num"><?= isset($versionRatings[$model]) ? number_format((float)$versionRatings[$model], 1) . ' / 5' : '—' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <!-- Shape: {"versions":[{"model":"mutation-v1","label":"...","n":2000,"accuracy_pct":44.0,"balanced_pct":38.2}, ...]} -->
    <script type="application/json" id="chart-version-accuracy-data"><?= json_encode(
        ['versions' => array_values(array_map(fn($model, $v) => [
            'model'        => $model,
            'label'        => model_label($model),
            'n'            => $v['n'],
            'accuracy_pct' => round($v['correct'] / $v['n'] * 100, 1),
            'balanced_pct' => round(array_sum($v['per_type']) / count($v['per_type']) * 100, 1),
        ], array_keys($versionStats), $versionStats))],
        JSON_HEX_TAG | JSON_HEX_AMP
    ) ?></script>
</section>

<div class="grid-2">

    <!-- ===================================================================
         5. Accuracy for each cancer type, chosen version, best first
         =================================================================== -->
    <section class="card" aria-labelledby="type-heading">
        <div class="card-header">
            <h2 id="type-heading">Accuracy by cancer type</h2>
            <span class="muted small-text"><?= e(model_label($version)) ?></span>
        </div>
        <?php
        $typeRows = $byType;
        uksort($typeRows, fn($a, $b) => [$typeRows[$b]['correct'] / $typeRows[$b]['n'], $typeRows[$b]['n']]
                                     <=> [$typeRows[$a]['correct'] / $typeRows[$a]['n'], $typeRows[$a]['n']]);
        ?>
        <div id="chart-type-accuracy" class="chart-slot" role="img"
             aria-label="Accuracy for each cancer type">
            <div class="table-wrap type-table">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">Cancer type</th>
                            <th scope="col">n</th>
                            <th scope="col">Accuracy</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($typeRows as $typeId => $t): ?>
                        <tr>
                            <!-- full name is in the chip's hover text; the card is too narrow for it -->
                            <td><?= cancer_chip($types[$typeId]) ?></td>
                            <td class="num"><?= number_format($t['n']) ?><?= $t['n'] < FEW_CASES ? ' <span class="chip few">few cases</span>' : '' ?></td>
                            <td class="num"><?= pct($t['correct'] / $t['n']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <!-- Shape: {"model":"expression-v2","types":[{"type":"BRCA","name":"...","group":"breast_gyn","n":223,"model_pct":97.3}, ...]} -->
        <script type="application/json" id="chart-type-accuracy-data"><?= json_encode(
            [
                'model' => $version,
                'types' => array_values(array_map(fn($id, $t) => [
                    'type'      => $types[$id]['code'],
                    'name'      => $types[$id]['name'],
                    'group'     => $types[$id]['group'],
                    'n'         => $t['n'],
                    'model_pct' => round($t['correct'] / $t['n'] * 100, 1),
                ], array_keys($typeRows), $typeRows)),
            ],
            JSON_HEX_TAG | JSON_HEX_AMP
        ) ?></script>
    </section>

    <div class="stacked-cards">
        <!-- ===================================================================
             6. Confidence check
             =================================================================== -->
        <section class="card" aria-labelledby="calibration-heading">
            <div class="card-header">
                <h2 id="calibration-heading">Is a confident model right more often?</h2>
            </div>
            <div id="chart-calibration" class="chart-slot chart-short" role="img"
                 aria-label="Share of correct calls in each band of model confidence">
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
                            <?php foreach ($bands as $b): ?>
                            <tr>
                                <td class="num"><?= $b['from'] === 0 ? 'Under ' . $b['to'] : $b['from'] . '–' . $b['to'] ?>%</td>
                                <td class="num"><?= number_format($b['n']) ?><?= $b['n'] && $b['n'] < 5 ? ' <span class="chip few">few cases</span>' : '' ?></td>
                                <td class="num"><?= $b['n'] ? pct($b['correct'] / $b['n']) : '—' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <!-- Shape: {"bands":[{"from":50,"to":60,"n":40,"correct_pct":52.5}, ...]}; n < 5 drawn hatched -->
            <script type="application/json" id="chart-calibration-data"><?= json_encode(
                ['bands' => array_map(fn($b) => [
                    'from' => $b['from'], 'to' => $b['to'], 'n' => $b['n'],
                    'correct_pct' => $b['n'] ? round($b['correct'] / $b['n'] * 100, 1) : null,
                ], $bands)],
                JSON_HEX_TAG | JSON_HEX_AMP
            ) ?></script>
        </section>

        <!-- ===================================================================
             7. Feedback summary by role
             =================================================================== -->
        <section class="card" aria-labelledby="fb-summary-heading">
            <div class="card-header">
                <h2 id="fb-summary-heading">Feedback summary</h2>
                <span class="muted small-text"><?= e(model_label($version)) ?></span>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">Role</th>
                            <?php for ($r = 1; $r <= 5; $r++): ?>
                            <th scope="col"><?= $r ?></th>
                            <?php endfor; ?>
                            <th scope="col">Average</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($roleRatings as $role => $counts): ?>
                        <tr>
                            <th scope="row"><?= e(ucfirst($role)) ?>s</th>
                            <?php foreach ($counts as $n): ?>
                            <td class="num"><?= number_format($n) ?></td>
                            <?php endforeach; ?>
                            <td class="num"><?= $average($counts) === null ? '—' : number_format($average($counts), 1) . ' / 5' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>

<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
