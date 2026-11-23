<?php
/*
 * Doctor detail (research-doctor.php?id=<user_id>)
 * One oncologist: who they are, their patients, and the feedback they've given the model.
 *
 *   1. Profile          name, speciality, institution, account created
 *   2. Tiles            patients, predictions, reviews done, awaiting review,
 *                       model matched diagnosis %, average rating given
 *   3. How they rated the model   their 1-5 ratings -> #chart-doctor-ratings
 *   4. Patients         de-identified, 20 per page
 *   5. Review history   their feedback, newest first, 20 per page, filter by whether
 *                       the model matched the diagnosis
 *
 * A missing or invalid id, or the id of someone who isn't an oncologist, shows the
 * "not available" state.
 */

require_once __DIR__ . '/../../includes/research.php';

$currentUser = research_require_login();       // also connects: $pdo

$pageTitle  = 'Doctor';
$activePage = 'research-doctors';              // keeps "Doctors" highlighted in the side nav
$pageStyles = ['research-doctor.css'];

const ROWS_PER_PAGE = 20;

$types = cancer_types($pdo);
$latestModel = model_versions($pdo)[0] ?? null;

// ---------------------------------------------------------------------------
// Which doctor? (int) turns anything that isn't a number into 0
// ---------------------------------------------------------------------------
$doctorId = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    "SELECT pr.user_id, pr.first_name, pr.last_name, pr.speciality, pr.institution, u.created_at
     FROM professionals pr JOIN users u ON u.user_id = pr.user_id
     WHERE pr.user_id = ? AND pr.professional_type = 'oncologist'"
);
$stmt->execute([$doctorId]);
$doctor = $stmt->fetch();

// Same message for a malformed id and an unknown one, so the page never reveals which ids exist
if (!$doctor) {
    http_response_code(404);
    $pageTitle = 'Doctor not available';
    require __DIR__ . '/../../includes/header.php';
    ?>
    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="research-doctors.php">Doctors</a></nav>
    <section class="card not-available">
        <h1>This doctor isn't available</h1>
        <p class="muted">The link may be wrong, or the account may no longer exist.</p>
        <a class="btn" href="research-doctors.php">Back to doctors</a>
    </section>
    <?php
    require __DIR__ . '/../../includes/footer.php';
    exit();
}
$doctorName = 'Dr ' . $doctor['first_name'] . ' ' . $doctor['last_name'];
$pageTitle = $doctorName;

// Review history filter: did the model's call match the recorded diagnosis?
$matchOptions = ['yes' => 'Model matched', 'no' => 'Model did not match'];
$match = array_key_exists($_GET['match'] ?? '', $matchOptions) ? $_GET['match'] : '';

// ---------------------------------------------------------------------------
// 2. Tiles
// ---------------------------------------------------------------------------
$stmt = $pdo->prepare(
    "SELECT COUNT(DISTINCT patient_id) AS patients, COUNT(*) AS predictions,
            SUM(reviewed) AS reviews, SUM(1 - reviewed) AS awaiting,
            AVG(CASE WHEN model_used = ? THEN correct END) AS model_acc
     FROM (" . PREDICTION_SQL . " WHERE p.user_id = ?) pv"
);
$stmt->execute([$latestModel, $doctorId]);
$tiles = $stmt->fetch();

// ---------------------------------------------------------------------------
// 3. Their ratings, 1-5
// ---------------------------------------------------------------------------
$stmt = $pdo->prepare('SELECT feedback_rating, COUNT(*) FROM feedback WHERE user_id = ? GROUP BY feedback_rating');
$stmt->execute([$doctorId]);
$ratingCounts = array_replace(array_fill_keys(range(1, 5), 0), array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR)));
$ratingTotal = array_sum($ratingCounts);
$avgRating = $ratingTotal ? array_sum(array_map(fn($r, $n) => $r * $n, array_keys($ratingCounts), $ratingCounts)) / $ratingTotal : null;

// ---------------------------------------------------------------------------
// 4. Patients they've run predictions for
// ---------------------------------------------------------------------------
$stmt = $pdo->prepare('SELECT COUNT(DISTINCT patient_id) FROM predictions WHERE user_id = ?');
$stmt->execute([$doctorId]);
$patientTotal = (int)$stmt->fetchColumn();
$patientPage = page_number('ppage', $patientTotal, ROWS_PER_PAGE);

$stmt = $pdo->prepare(
    "SELECT p.patient_id, p.birthdate, p.sex, dx.cancer_type_id, dx.stage, dx.status,
            (SELECT COUNT(*) FROM samples s WHERE s.patient_id = p.patient_id) AS samples
     FROM (SELECT DISTINCT patient_id FROM predictions WHERE user_id = ?) mine
     JOIN patients p ON p.patient_id = mine.patient_id
     LEFT JOIN (SELECT * FROM (SELECT d.*, ROW_NUMBER() OVER (PARTITION BY d.patient_id
                                                              ORDER BY d.date_of_diagnosis DESC, d.diagnosis_id DESC) AS rn
                               FROM patient_diagnoses d) ranked WHERE rn = 1) dx ON dx.patient_id = p.patient_id
     ORDER BY p.patient_id
     LIMIT " . ROWS_PER_PAGE . ' OFFSET ' . (($patientPage - 1) * ROWS_PER_PAGE)
);
$stmt->execute([$doctorId]);
$patients = $stmt->fetchAll(PDO::FETCH_UNIQUE);

// The latest prediction for each patient on this page
if ($patients) {
    $ids = implode(',', array_map('intval', array_keys($patients)));
    foreach ($pdo->query("SELECT * FROM (SELECT pv.*, ROW_NUMBER() OVER (PARTITION BY pv.patient_id ORDER BY pv.created_at DESC) AS rn
                                       FROM (" . PREDICTION_SQL . " WHERE p.patient_id IN ($ids)) pv) latest
                          WHERE rn = 1") as $row) {
        $patients[$row['patient_id']]['latest'] = $row;
    }
}

// ---------------------------------------------------------------------------
// 5. Review history: this doctor's feedback, newest first
// ---------------------------------------------------------------------------
$historyFrom = "FROM feedback f JOIN (" . PREDICTION_SQL . ") pv ON pv.prediction_id = f.prediction_id
                WHERE f.user_id = ?" . ($match === 'yes' ? ' AND pv.correct = 1' : ($match === 'no' ? ' AND pv.correct = 0' : ''));
$stmt = $pdo->prepare("SELECT COUNT(*) $historyFrom");
$stmt->execute([$doctorId]);
$reviewTotal = (int)$stmt->fetchColumn();
$reviewPage = page_number('rpage', $reviewTotal, ROWS_PER_PAGE);

$stmt = $pdo->prepare(
    "SELECT f.created_at, f.feedback_rating, f.comments, pv.*
     $historyFrom
     ORDER BY f.created_at DESC, f.feedback_id DESC
     LIMIT " . ROWS_PER_PAGE . ' OFFSET ' . (($reviewPage - 1) * ROWS_PER_PAGE)
);
$stmt->execute([$doctorId]);
$reviews = $stmt->fetchAll();

audit($pdo, $currentUser['user_id'], 'view_doctor', 'professionals', $doctorId);

require __DIR__ . '/../../includes/header.php';
?>

<!-- ===================================================================
     1. Profile
     =================================================================== -->
<nav class="breadcrumb" aria-label="Breadcrumb">
    <a href="research-doctors.php">Doctors</a> › <?= e($doctorName) ?>
</nav>

<div class="page-header">
    <div class="profile">
        <h1><?= e($doctorName) ?></h1>
        <p class="profile-meta">
            <span class="chip">Oncologist</span>
            <span>Speciality: <?= e($doctor['speciality'] ?? '—') ?></span>
            <span>Institution: <?= e($doctor['institution'] ?? '—') ?></span>
            <span>Account created <?= e(format_date($doctor['created_at'])) ?></span>
        </p>
    </div>
</div>

<!-- ===================================================================
     2. Tiles. The counted tiles link to where those rows are listed.
     =================================================================== -->
<div class="tiles">
    <a class="tile tile-link" href="#patients-heading">
        <span class="tile-value"><?= number_format($tiles['patients']) ?></span>
        <span class="tile-label">Patients</span>
    </a>
    <a class="tile tile-link" href="research-predictions.php?tab=all&amp;doctor=<?= $doctorId ?>">
        <span class="tile-value"><?= number_format($tiles['predictions']) ?></span>
        <span class="tile-label">Predictions run</span>
    </a>
    <a class="tile tile-link" href="#reviews-heading">
        <span class="tile-value"><?= number_format((int)$tiles['reviews']) ?></span>
        <span class="tile-label">Reviews done</span>
    </a>
    <a class="tile tile-link" href="research-predictions.php?tab=all&amp;status=pending&amp;doctor=<?= $doctorId ?>">
        <span class="tile-value"><?= number_format((int)$tiles['awaiting']) ?></span>
        <span class="tile-label">Awaiting their review</span>
    </a>
    <div class="tile">
        <span class="tile-value"><?= pct($tiles['model_acc']) ?></span>
        <span class="tile-label">Model matched their patients' diagnosis</span>
    </div>
</div>

<!-- ===================================================================
     3. How they rated the model: how many of their reviews gave each rating.

     CHART SLOT CONVENTION (for the visualisation teammate):
       <div id="chart-x">          chart drawn here; holds an HTML fallback
       <script id="chart-x-data">  the chart's data as JSON
     =================================================================== -->
<section class="card" aria-labelledby="ratings-heading">
    <div class="card-header">
        <h2 id="ratings-heading">How they rated the model</h2>
        <span class="muted small-text">
            <?= $ratingTotal ? 'Average ' . number_format($avgRating, 1) . ' / 5 across ' . number_format($ratingTotal) . ' reviews' : 'No reviews yet' ?>
        </span>
    </div>

    <div id="chart-doctor-ratings" class="chart-slot agreement-slot" role="img"
         aria-label="How many of this doctor's reviews gave each rating from 1 to 5">
        <!-- Fallback: one bar split by rating, then the counts -->
        <div class="agreement-bar rating-bar" aria-hidden="true">
            <?php foreach ($ratingCounts as $rating => $n): ?>
                <?php if ($n): ?>
                <span class="rating-seg rating-<?= $rating ?>" style="flex-grow: <?= $n ?>"></span>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <ul class="agreement-counts">
            <?php foreach ($ratingCounts as $rating => $n): ?>
            <li><?= rating_chip($rating) ?> <span class="num"><?= number_format($n) ?></span></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <!-- Shape: {"ratings":[{"rating":1,"count":12}, ... {"rating":5,"count":40}]} -->
    <script type="application/json" id="chart-doctor-ratings-data"><?= json_encode(
        ['ratings' => array_map(fn($r, $n) => ['rating' => $r, 'count' => $n], array_keys($ratingCounts), $ratingCounts)],
        JSON_HEX_TAG | JSON_HEX_AMP
    ) ?></script>
</section>

<!-- ===================================================================
     4. Patients (de-identified), 20 per page
     =================================================================== -->
<section class="card" aria-labelledby="patients-heading">
    <div class="card-header">
        <h2 id="patients-heading">Patients</h2>
        <span class="muted small-text">Patients they have run predictions for</span>
    </div>

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
                    <th scope="col">Samples</th>
                    <th scope="col">Latest prediction</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($patients as $id => $p): ?>
                <?php $latest = $p['latest'] ?? null; ?>
                <tr>
                    <!-- &doctor= lets the patient page's breadcrumb lead back here -->
                    <td><?= patient_link((int)$id, '&doctor=' . $doctorId) ?></td>
                    <td class="num"><?= e(age_from_birthdate($p['birthdate'])) ?></td>
                    <td><?= e(ucfirst($p['sex'])) ?></td>
                    <td><?= cancer_chip($types[(int)$p['cancer_type_id']] ?? null) ?></td>
                    <td><?= $p['stage'] && $p['stage'] !== 'unknown' ? e($p['stage']) : '<span class="muted">Not recorded</span>' ?></td>
                    <td><?= e(ucfirst($p['status'] ?? '')) ?></td>
                    <td class="num"><?= number_format($p['samples']) ?></td>
                    <td><?= $latest ? status_chip((bool)$latest['reviewed']) : '<span class="muted">None</span>' ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$patients): ?>
                <tr><td colspan="8" class="empty">No patients yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?= pager('ppage', $patientPage, ROWS_PER_PAGE, $patientTotal, 'patients-heading', 'patients') ?>
</section>

<!-- ===================================================================
     5. Review history: every review this doctor has left, newest first
     =================================================================== -->
<section class="card" aria-labelledby="reviews-heading">
    <div class="card-header">
        <h2 id="reviews-heading">Review history</h2>
    </div>

    <!-- Filter by whether the model matched. Keeps the id and the patients page; resets rpage. -->
    <form class="filters review-filters" method="get" action="#reviews-heading">
        <?= hidden_query_inputs(['match', 'rpage']) ?>
        <div class="field">
            <label for="match">Model's call</label>
            <select id="match" name="match">
                <option value="">All</option>
                <?php foreach ($matchOptions as $value => $text): ?>
                <option value="<?= e($value) ?>" <?= $value === $match ? 'selected' : '' ?>><?= e($text) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Filter</button>
        <a class="btn" href="<?= e(query_with(['match' => null, 'rpage' => null])) ?>#reviews-heading">Clear</a>
    </form>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th scope="col">Date</th>
                    <th scope="col">Patient</th>
                    <th scope="col">Model</th>
                    <th scope="col">Model's call</th>
                    <th scope="col">Diagnosis</th>
                    <th scope="col">Matched?</th>
                    <th scope="col">Rating</th>
                    <th scope="col">Comment</th>
                    <th scope="col"><span class="visually-hidden">Open</span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reviews as $r): ?>
                <tr>
                    <td><?= e(format_date($r['created_at'])) ?></td>
                    <td><?= patient_link((int)$r['patient_id'], '&doctor=' . $doctorId) ?></td>
                    <td><?= e(model_label($r['model_used'])) ?></td>
                    <td><?= cancer_chip($types[(int)$r['top_type_id']] ?? null) ?> <span class="muted num"><?= pct($r['top_probability']) ?></span></td>
                    <td><?= cancer_chip($types[(int)$r['dx_type_id']] ?? null) ?></td>
                    <td><?= correct_mark($r['correct'] === null ? null : (bool)$r['correct']) ?></td>
                    <td><?= rating_chip($r['feedback_rating']) ?></td>
                    <td class="comment-cell"><?= $r['comments'] === null || $r['comments'] === '' ? '<span class="muted">—</span>' : e($r['comments']) ?></td>
                    <td><a href="research-prediction.php?id=<?= (int)$r['prediction_id'] ?>">Compare</a></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$reviews): ?>
                <tr><td colspan="9" class="empty"><?= $match ? 'No reviews match this filter.' : 'No reviews yet.' ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?= pager('rpage', $reviewPage, ROWS_PER_PAGE, $reviewTotal, 'reviews-heading', 'reviews') ?>
</section>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
