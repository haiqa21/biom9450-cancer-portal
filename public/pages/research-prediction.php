<?php
/*
 * Prediction comparison (research-prediction.php?id=<prediction_id>)
 * One model run with everything about it side by side, the feedback already given,
 * and the researcher's own feedback form.
 *
 *   1. Header          "Patient 48 · Expression model v2" + status chip
 *   2. Stage cards     1 Recorded diagnosis · 2 Model's call · 3 Oncologist's review ·
 *                      4 The other model version's call for the same patient
 *   3. Probabilities   all 33 types, top 5 shown, diagnosis marked -> #chart-probabilities
 *   4. Mutations used  for the mutation model: the patient's mutations in the model's genes
 *                      (prediction_mutations); the expression model uses none
 *   5. Feedback        every entry, newest first, with the spread of ratings
 *   6. My feedback     rating 1-5 + comment (#feedback-form), posted to feedback-save.php
 */

require_once __DIR__ . '/../../includes/research.php';

$currentUser = research_require_login();       // also connects: $pdo
$me = $currentUser['user_id'];

$pageTitle   = 'Prediction';
$activePage  = 'research-predictions';         // keeps "Predictions" highlighted in the side nav
$pageStyles  = ['research-prediction.css'];
$pageScripts = ['research-prediction.js'];    // form niceties; the form works without it

const TOP_PROBABILITIES = 5;                   // rows shown before "other types"
const MUTATIONS_SHOWN   = 15;

$types = cancer_types($pdo);

// ---------------------------------------------------------------------------
// Which prediction?
// ---------------------------------------------------------------------------
$predictionId = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT pv.*, pr.first_name, pr.last_name
                       FROM (" . PREDICTION_SQL . " WHERE p.prediction_id = ?) pv
                       LEFT JOIN professionals pr ON pr.user_id = pv.doctor_id");
$stmt->execute([$predictionId]);
$prediction = $stmt->fetch();

// ---------------------------------------------------------------------------
// Messages handed back by feedback-save.php (post/redirect/get), each read once
// ---------------------------------------------------------------------------
$token  = csrf_token();
$flash  = $_SESSION['flash'] ?? null;
$errors = $_SESSION['form_errors'] ?? [];
$old    = $_SESSION['form_old'] ?? [];
unset($_SESSION['flash'], $_SESSION['form_errors'], $_SESSION['form_old']);

if (!$prediction) {
    http_response_code(404);
    $pageTitle = 'Prediction not available';
    require __DIR__ . '/../../includes/header.php';
    ?>
    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="research-predictions.php">Predictions</a></nav>
    <section class="card not-available">
        <h1>This prediction isn't available</h1>
        <p class="muted">The link may be wrong, or the prediction no longer exists.</p>
        <a class="btn" href="research-predictions.php">Back to predictions</a>
    </section>
    <?php
    require __DIR__ . '/../../includes/footer.php';
    exit();
}
$patientId = (int)$prediction['patient_id'];
$pageTitle = 'Patient ' . $patientId . ' · ' . model_label($prediction['model_used']);

// ---------------------------------------------------------------------------
// 2. The four stages
// ---------------------------------------------------------------------------
// Recorded diagnosis (most recent)
$stmt = $pdo->prepare('SELECT cancer_type_id, date_of_diagnosis, stage FROM patient_diagnoses
                       WHERE patient_id = ? ORDER BY date_of_diagnosis DESC, diagnosis_id DESC LIMIT 1');
$stmt->execute([$patientId]);
$diagnosis = $stmt->fetch();

// The oncologist's review: feedback from the doctor who ran the prediction
$stmt = $pdo->prepare('SELECT feedback_rating, comments, created_at FROM feedback
                       WHERE prediction_id = ? AND user_id = ? ORDER BY created_at DESC LIMIT 1');
$stmt->execute([$predictionId, $prediction['doctor_id']]);
$review = $stmt->fetch();

// The other model version's latest run for the same patient
$stmt = $pdo->prepare("SELECT pv.* FROM (" . PREDICTION_SQL . " WHERE p.patient_id = ? AND p.model_used <> ?) pv
                       ORDER BY pv.created_at DESC LIMIT 1");
$stmt->execute([$patientId, $prediction['model_used']]);
$other = $stmt->fetch();

// ---------------------------------------------------------------------------
// 3. Probabilities for every type, highest first
// ---------------------------------------------------------------------------
$stmt = $pdo->prepare('SELECT cancer_type_id, prediction_probability FROM prediction_results
                       WHERE prediction_id = ? ORDER BY prediction_probability DESC, cancer_type_id');
$stmt->execute([$predictionId]);
$probabilities = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);      // type id => probability
$shown = array_slice($probabilities, 0, TOP_PROBABILITIES, true);
$rest = array_slice($probabilities, TOP_PROBABILITIES, null, true);
$dxId = $diagnosis ? (int)$diagnosis['cancer_type_id'] : null;
$dxRank = $dxId !== null ? array_search($dxId, array_keys($probabilities)) : false;

// ---------------------------------------------------------------------------
// 4. Mutations the model used (only the mutation model records any)
// ---------------------------------------------------------------------------
$stmt = $pdo->prepare('SELECT COUNT(*) FROM prediction_mutations WHERE prediction_id = ?');
$stmt->execute([$predictionId]);
$usedTotal = (int)$stmt->fetchColumn();
$usedMutations = [];
if ($usedTotal) {
    $typeOrder = implode(',', array_map(fn($t) => $pdo->quote($t), MUTATION_TYPES));
    $stmt = $pdo->prepare(
        "SELECT STRAIGHT_JOIN g.gene_symbol, g.chromosome, m.genomic_position, m.original_allele, m.altered_allele,
                m.mutation_type, pmu.allele_frequency
         FROM prediction_mutations pmu
         JOIN mutations m ON m.mutation_id = pmu.mutation_id
         JOIN genes g ON g.gene_id = m.gene_id
         WHERE pmu.prediction_id = ?
         ORDER BY FIELD(m.mutation_type, $typeOrder) = 0, FIELD(m.mutation_type, $typeOrder), g.gene_symbol
         LIMIT " . MUTATIONS_SHOWN
    );
    $stmt->execute([$predictionId]);
    $usedMutations = $stmt->fetchAll();
}

// ---------------------------------------------------------------------------
// 5. All feedback on this prediction, newest first
// ---------------------------------------------------------------------------
$stmt = $pdo->prepare('SELECT f.feedback_id, f.user_id, f.feedback_rating, f.comments, f.created_at,
                              pr.first_name, pr.last_name, pr.professional_type
                       FROM feedback f LEFT JOIN professionals pr ON pr.user_id = f.user_id
                       WHERE f.prediction_id = ? ORDER BY f.created_at DESC, f.feedback_id DESC');
$stmt->execute([$predictionId]);
$feedback = $stmt->fetchAll();
$ratingCounts = array_fill_keys(range(1, 5), 0);
foreach ($feedback as $f) {
    $ratingCounts[(int)$f['feedback_rating']]++;
}

// ---------------------------------------------------------------------------
// 6. My feedback: saved row (latest), or what I typed if the last submit failed
// ---------------------------------------------------------------------------
$saved = null;
foreach ($feedback as $f) {
    if ((int)$f['user_id'] === $me) {
        $saved = $f;
        break;
    }
}
$form = $old ?: ($saved ? ['rating' => (string)$saved['feedback_rating'], 'comment' => (string)$saved['comments']] : []);
$formValue = fn(string $key) => $form[$key] ?? '';
$fieldError = fn(string $key): string => isset($errors[$key])
    ? '<p class="field-error" id="' . e($key) . '-error">' . e($errors[$key]) . '</p>'
    : '';

// Next prediction without my feedback (oldest first, so a queue can be worked through)
$stmt = $pdo->prepare('SELECT p.prediction_id FROM predictions p
                       WHERE p.prediction_id <> ? AND NOT EXISTS
                             (SELECT 1 FROM feedback f WHERE f.prediction_id = p.prediction_id AND f.user_id = ?)
                       ORDER BY p.created_at, p.prediction_id LIMIT 1');
$stmt->execute([$predictionId, $me]);
$nextId = $stmt->fetchColumn();

audit($pdo, $me, 'view_prediction', 'predictions', $predictionId);

$typeOf = fn($id) => $id === null ? null : ($types[(int)$id] ?? null);

require __DIR__ . '/../../includes/header.php';
?>

<!-- ===================================================================
     1. Header
     =================================================================== -->
<nav class="breadcrumb" aria-label="Breadcrumb">
    <a href="research-predictions.php">Predictions</a> ›
    <a href="research-patient.php?id=<?= $patientId ?>">Patient <?= $patientId ?></a> ›
    <?= e(model_label($prediction['model_used'])) ?>
</nav>

<div class="page-header">
    <div class="prediction-heading">
        <h1>Patient <?= $patientId ?> · <?= e(model_label($prediction['model_used'])) ?></h1>
        <div class="heading-chips">
            <?= status_chip((bool)$prediction['reviewed']) ?>
            <span class="chip">Run <?= e(format_date($prediction['created_at'])) ?></span>
        </div>
    </div>
    <?php if ($nextId): ?>
    <a class="btn" href="research-prediction.php?id=<?= (int)$nextId ?>#feedback-form">Next needing my feedback →</a>
    <?php endif; ?>
</div>

<!-- ===================================================================
     2. Stage cards (stack on a phone). A stage not reached yet is greyed out.
     =================================================================== -->
<div class="stages">
    <section class="stage" aria-labelledby="stage-1">
        <h2 id="stage-1" class="stage-label">1 · Recorded diagnosis</h2>
        <?php if ($diagnosis): ?>
        <p class="stage-main"><?= cancer_chip($typeOf($diagnosis['cancer_type_id'])) ?></p>
        <dl class="stage-details">
            <div><dt>Cancer type</dt><dd><?= e($typeOf($diagnosis['cancer_type_id'])['name'] ?? '—') ?></dd></div>
            <div><dt>Diagnosed</dt><dd><?= e(format_month($diagnosis['date_of_diagnosis'])) ?></dd></div>
            <div><dt>Stage</dt><dd><?= e($diagnosis['stage'] === 'unknown' ? 'Not recorded' : $diagnosis['stage']) ?></dd></div>
        </dl>
        <?php else: ?>
        <p class="stage-main muted">None recorded</p>
        <?php endif; ?>
    </section>

    <section class="stage" aria-labelledby="stage-2">
        <h2 id="stage-2" class="stage-label">2 · Model's call</h2>
        <p class="stage-main"><?= cancer_chip($typeOf($prediction['top_type_id'])) ?> <span class="num"><?= pct($prediction['top_probability']) ?></span></p>
        <dl class="stage-details">
            <div><dt>Matches the diagnosis</dt><dd><?= correct_mark($prediction['correct'] === null ? null : (bool)$prediction['correct']) ?></dd></div>
            <div><dt>Model</dt><dd><?= e(model_label($prediction['model_used'])) ?></dd></div>
            <div><dt>Run by</dt><dd><?= $prediction['first_name'] ? '<a href="research-doctor.php?id=' . (int)$prediction['doctor_id'] . '">Dr ' . e($prediction['first_name'] . ' ' . $prediction['last_name']) . '</a>' : '—' ?></dd></div>
        </dl>
    </section>

    <section class="stage<?= $review ? '' : ' stage-pending' ?>" aria-labelledby="stage-3">
        <h2 id="stage-3" class="stage-label">3 · Oncologist's review</h2>
        <?php if ($review): ?>
        <p class="stage-main"><?= rating_chip($review['feedback_rating']) ?></p>
        <dl class="stage-details">
            <div><dt>Comment</dt><dd><?= $review['comments'] ? e($review['comments']) : '<span class="muted">None</span>' ?></dd></div>
            <div><dt>Reviewed</dt><dd><?= e(format_date($review['created_at'])) ?></dd></div>
        </dl>
        <?php else: ?>
        <p class="stage-main">Not yet</p>
        <p class="muted small-text">The oncologist who ran this prediction hasn't reviewed it.</p>
        <?php endif; ?>
    </section>

    <section class="stage<?= $other ? '' : ' stage-pending' ?>" aria-labelledby="stage-4">
        <h2 id="stage-4" class="stage-label">4 · Other model</h2>
        <?php if ($other): ?>
        <p class="stage-main"><?= cancer_chip($typeOf($other['top_type_id'])) ?> <span class="num"><?= pct($other['top_probability']) ?></span></p>
        <dl class="stage-details">
            <div><dt>Model</dt><dd><?= e(model_label($other['model_used'])) ?></dd></div>
            <div><dt>Matches the diagnosis</dt><dd><?= correct_mark($other['correct'] === null ? null : (bool)$other['correct']) ?></dd></div>
            <div><dt>&nbsp;</dt><dd><a href="research-prediction.php?id=<?= (int)$other['prediction_id'] ?>">Open that prediction</a></dd></div>
        </dl>
        <?php else: ?>
        <p class="stage-main">None</p>
        <p class="muted small-text">No other model has been run for this patient.</p>
        <?php endif; ?>
    </section>
</div>

<!-- ===================================================================
     3 + 4.

     CHART SLOT CONVENTION (for the visualisation teammate):
       <div id="chart-x">          chart drawn here; holds an HTML fallback
       <script id="chart-x-data">  the chart's data as JSON
     =================================================================== -->
<div class="grid-2">

    <!-- 3. Probabilities: the top 5, then everything else as one row -->
    <section class="card" aria-labelledby="prob-heading">
        <div class="card-header">
            <h2 id="prob-heading">Model probabilities</h2>
            <span class="muted small-text"><?= count($probabilities) ?> cancer types</span>
        </div>
        <div id="chart-probabilities" class="chart-slot" role="img"
             aria-label="The model's probability for each cancer type, with the recorded diagnosis marked">
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">Cancer type</th>
                            <th scope="col">Probability</th>
                            <th scope="col"><span class="visually-hidden">Marked</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($shown as $typeId => $probability): ?>
                        <tr<?= (int)$typeId === $dxId ? ' class="selected"' : '' ?>>
                            <!-- full name is in the chip's hover text; the card is too narrow for it -->
                            <td><?= cancer_chip($typeOf($typeId)) ?></td>
                            <td class="num"><?= pct($probability, 1) ?></td>
                            <td><?= (int)$typeId === $dxId ? '<span class="chip">Diagnosis</span>' : '' ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if ($rest): ?>
                        <tr>
                            <td class="muted">Other <?= count($rest) ?> types</td>
                            <td class="num"><?= pct(array_sum($rest), 1) ?></td>
                            <td>
                                <?php if ($dxRank !== false && $dxRank >= TOP_PROBABILITIES): ?>
                                <span class="chip">Diagnosis: <?= e($typeOf($dxId)['code']) ?> <?= pct($probabilities[$dxId], 1) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <!-- Shape: {"cancer_types":["BRCA",...],"probabilities":{"BRCA":62.1,...},"diagnosis":"BRCA"}  (all types, %) -->
        <script type="application/json" id="chart-probabilities-data"><?= json_encode(
            [
                'cancer_types'  => array_map(fn($id) => $typeOf($id)['code'], array_keys($probabilities)),
                'probabilities' => (object)array_combine(
                    array_map(fn($id) => $typeOf($id)['code'], array_keys($probabilities)),
                    array_map(fn($v) => round((float)$v * 100, 2), array_values($probabilities))
                ),
                'diagnosis'     => $dxId ? $typeOf($dxId)['code'] : null,
            ],
            JSON_HEX_TAG | JSON_HEX_AMP
        ) ?></script>
    </section>

    <!-- 4. Mutations the model used -->
    <section class="card" aria-labelledby="used-heading">
        <div class="card-header">
            <h2 id="used-heading">Mutations the model used</h2>
            <?php if ($usedTotal): ?>
            <span class="muted small-text num"><?= number_format($usedTotal) ?> in total, most serious first</span>
            <?php endif; ?>
        </div>
        <?php if ($usedMutations): ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th scope="col">Gene</th>
                        <th scope="col">Mutation</th>
                        <th scope="col">Type</th>
                        <th scope="col">Allele freq.</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($usedMutations as $m): ?>
                    <tr>
                        <td><span class="gene"><?= e($m['gene_symbol']) ?></span></td>
                        <td class="mono"><?= e(variant_name($m)) ?></td>
                        <td><span class="chip impact-<?= e(mutation_impact($m['mutation_type'])) ?>"><?= e(mutation_label($m['mutation_type'])) ?></span></td>
                        <td class="num"><?= $m['allele_frequency'] === null ? '—' : e(number_format((float)$m['allele_frequency'], 2)) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($usedTotal > MUTATIONS_SHOWN): ?>
        <p class="muted small-text"><a href="research-patient.php?id=<?= $patientId ?>#mutations-heading">All of this patient's mutations</a></p>
        <?php endif; ?>
        <?php else: ?>
        <p class="muted">
            <?= str_starts_with($prediction['model_used'], 'expression')
                ? 'This model predicts from gene expression, so it doesn\'t use individual mutations.'
                : 'No mutations were recorded for this prediction.' ?>
        </p>
        <?php endif; ?>
    </section>
</div>

<!-- ===================================================================
     5. Feedback from oncologists and researchers, newest first
     =================================================================== -->
<section class="card" aria-labelledby="feedback-heading">
    <div class="card-header">
        <h2 id="feedback-heading">Feedback <span class="muted">(<?= count($feedback) ?>)</span></h2>
    </div>

    <?php if ($feedback): ?>
    <!-- Ratings summary: one bar, a segment per rating (flex-grow = how many) -->
    <div class="agreement-summary">
        <div class="summary-bar rating-bar" aria-hidden="true">
            <?php foreach ($ratingCounts as $rating => $n): ?>
                <?php if ($n): ?><span class="rating-seg rating-<?= $rating ?>" style="flex-grow: <?= $n ?>"></span><?php endif; ?>
            <?php endforeach; ?>
        </div>
        <ul class="summary-counts">
            <?php foreach ($ratingCounts as $rating => $n): ?>
            <li><?= rating_chip($rating) ?> <span class="num"><?= $n ?></span></li>
            <?php endforeach; ?>
        </ul>
    </div>

    <ul class="feedback-list">
        <?php foreach ($feedback as $f): ?>
        <li class="feedback-entry">
            <p class="feedback-meta">
                <strong><?= $f['first_name'] ? 'Dr ' . e($f['first_name'] . ' ' . $f['last_name']) : 'Unknown user' ?></strong>
                · <?= e(ucfirst($f['professional_type'] ?? '')) ?>
                <?= (int)$f['user_id'] === (int)$prediction['doctor_id'] ? ' · ran this prediction' : '' ?>
                <?= (int)$f['user_id'] === $me ? ' · you' : '' ?>
                · <span class="muted"><?= e(format_date($f['created_at'])) ?></span>
            </p>
            <p class="feedback-chips"><?= rating_chip($f['feedback_rating']) ?></p>
            <?php if ($f['comments'] !== null && $f['comments'] !== ''): ?>
            <p class="feedback-comment"><?= e($f['comments']) ?></p>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php else: ?>
    <p class="muted">No feedback yet. Be the first.</p>
    <?php endif; ?>
</section>

<!-- ===================================================================
     6. My feedback. POSTs to feedback-save.php, which checks everything again
     (the browser checks are only for speed) and comes back here with a message.
     The feedback table stores a 1-5 rating and an optional comment.
     =================================================================== -->
<section class="card" aria-labelledby="my-feedback-heading" id="feedback-form">
    <div class="card-header">
        <h2 id="my-feedback-heading">My feedback</h2>
        <?php if ($saved): ?>
        <span class="muted small-text">Last saved <?= e(format_date($saved['created_at'])) ?></span>
        <?php endif; ?>
    </div>

    <?php if ($flash): ?>
    <p class="flash" role="status"><?= e($flash) ?></p>
    <?php endif; ?>

    <?php if ($errors): ?>
    <div class="error-summary" role="alert">
        <p><strong>Please fix <?= count($errors) === 1 ? 'this' : 'these' ?>:</strong></p>
        <ul>
            <?php foreach ($errors as $field => $message): ?>
            <li><a href="#<?= e($field) ?>"><?= e($message) ?></a></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <form class="feedback-form" method="post" action="feedback-save.php" novalidate>
        <!-- Which prediction, and the CSRF token that proves the form came from this site -->
        <input type="hidden" name="prediction_id" value="<?= $predictionId ?>">
        <input type="hidden" name="csrf_token" value="<?= e($token) ?>">

        <!-- Rating 1-5 (required). The first radio carries the id so error links can focus it. -->
        <fieldset class="form-group">
            <legend>How useful was this prediction? <span class="required" aria-hidden="true">*</span></legend>
            <div class="choice-row">
                <?php for ($n = 1; $n <= 5; $n++): ?>
                <label class="choice">
                    <input type="radio" name="rating" value="<?= $n ?>" required
                           <?= $n === 1 ? 'id="rating"' : '' ?>
                           <?= (string)$formValue('rating') === (string)$n ? 'checked' : '' ?>>
                    <?= $n ?><?= $n === 1 ? ' Not useful' : ($n === 5 ? ' Very useful' : '') ?>
                </label>
                <?php endfor; ?>
            </div>
            <?= $fieldError('rating') ?>
        </fieldset>

        <!-- Comment (optional) -->
        <div class="field">
            <label for="comment">Comment <span class="muted">(optional)</span></label>
            <textarea id="comment" name="comment" rows="4" maxlength="<?= FEEDBACK_COMMENT_MAX ?>"
                      aria-describedby="comment-count"
                      placeholder="For example: which cancer type you think it is, or a concern about the sample"><?= e($formValue('comment')) ?></textarea>
            <p class="hint counter" id="comment-count" data-max="<?= FEEDBACK_COMMENT_MAX ?>">
                <span class="num"><?= mb_strlen($formValue('comment')) ?></span> / <?= FEEDBACK_COMMENT_MAX ?>
            </p>
            <?= $fieldError('comment') ?>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= $saved ? 'Update feedback' : 'Submit feedback' ?></button>
        </div>
    </form>
</section>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
