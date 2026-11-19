<?php
require_once __DIR__ . '/../../includes/helpers.php';   // e(), csrf_token(), lists

// ---------------------------------------------------------------------------
// Signed-in user
// ---------------------------------------------------------------------------
// TODO: delete this line and add a researcher-only check from the database.
$currentUser = ['user_id' => 0, 'name' => 'Dr Placeholder', 'role' => 'researcher'];

// ---------------------------------------------------------------------------
// Settings read by includes/header.php
// ---------------------------------------------------------------------------
$pageTitle   = 'Prediction';
$activePage  = 'research-predictions';        // keeps "Predictions" highlighted in the side nav
$pageStyles  = ['research-prediction.css'];
$pageScripts = ['research-prediction.js'];    // feedback form niceties; the form works without it

// ---------------------------------------------------------------------------
// Which prediction?
// ---------------------------------------------------------------------------
$predictionId = (int)($_GET['id'] ?? 0);

// TODO: prediction query. No row when the id is
// unknown, the patient hasn't consented, or status = 'awaiting_diagnosis'
// (nothing to compare yet) -> not available.
$predictionFound = $predictionId > 0;

// ---------------------------------------------------------------------------
// Messages handed back by feedback-save.php
// ---------------------------------------------------------------------------
// The handler redirects back here (post/redirect/get, so refreshing doesn't
// re-submit). It leaves three things in the session, each read once:
//   flash       -> the green "saved" message
//   form_errors -> field name => message, shown under each field
//   form_old    -> what was typed, so a failed submit doesn't lose it
$token  = csrf_token();                       // also starts the session
$flash  = $_SESSION['flash'] ?? null;
$errors = $_SESSION['form_errors'] ?? [];
$old    = $_SESSION['form_old'] ?? [];
unset($_SESSION['flash'], $_SESSION['form_errors'], $_SESSION['form_old']);

// TODO: load this researcher's saved feedback for this prediction (one row at
// most: unique on prediction_id + user_id). $old wins over it after an error.
$saved = null;
$form = $old ?: ($saved ?? []);
$formValue = fn(string $key, $default = '') => $form[$key] ?? $default;

// Error text for one field, or nothing
$fieldError = fn(string $key): string => isset($errors[$key])
    ? '<p class="field-error" id="' . e($key) . '-error">' . e($errors[$key]) . '</p>'
    : '';

// ---------------------------------------------------------------------------
// Not available
// ---------------------------------------------------------------------------
if (!$predictionFound) {
    http_response_code(404);
    $pageTitle = 'Prediction not available';
    require __DIR__ . '/../../includes/header.php';
    ?>
    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="research-predictions.php">Predictions</a></nav>
    <section class="card not-available">
        <h1>This prediction isn't available</h1>
        <p class="muted">The link may be wrong, the patient's data may not be available for research, or the
            oncologist hasn't recorded their diagnosis yet.</p>
        <a class="btn" href="research-predictions.php">Back to predictions</a>
    </section>
    <?php
    require __DIR__ . '/../../includes/footer.php';
    exit();
}

// TODO: record the view: audit_logs action 'view_prediction', entity 'predictions', $predictionId

require __DIR__ . '/../../includes/header.php';
?>

<!-- ===================================================================
     1. Header
     =================================================================== -->
<nav class="breadcrumb" aria-label="Breadcrumb">
    <a href="research-predictions.php">Predictions</a> ›
    <a href="research-patient.php?id=1">Patient —</a> › Sample —
</nav>

<div class="page-header">
    <div class="prediction-heading">
        <h1>Patient — · Sample —</h1>
        <div class="heading-chips">
            <!-- TODO: status chip from predictions.status; model version -->
            <span class="chip status-reviewed"><?= e(PREDICTION_STATUSES['reviewed']) ?></span>
            <span class="chip">Model —</span>
        </div>
    </div>
    <!-- Work through the queue: oldest prediction without my feedback, other than this one.
         TODO: hide when there is none. -->
    <a class="btn" href="research-prediction.php?id=<?= $predictionId + 1 ?>#feedback-form">Next needing feedback →</a>
</div>

<!-- ===================================================================
     2. Stage cards: the four stages left to right (stack on a phone).
     A stage not reached yet is greyed out with "Not yet".
     =================================================================== -->
<div class="stages">
    <section class="stage" aria-labelledby="stage-1">
        <h2 id="stage-1" class="stage-label">1 · Initial diagnosis</h2>
        <p class="stage-main">—</p>
        <dl class="stage-details">
            <div><dt>Doctor</dt><dd><a href="research-doctor.php?id=1">Dr —</a></dd></div>
            <div><dt>Confidence</dt><dd>—</dd></div>
            <div><dt>Supporting genes</dt><dd>—</dd></div>
            <div><dt>Notes</dt><dd>—</dd></div>
            <div><dt>Recorded</dt><dd>—</dd></div>
        </dl>
    </section>

    <section class="stage" aria-labelledby="stage-2">
        <h2 id="stage-2" class="stage-label">2 · Model</h2>
        <p class="stage-main">— <span class="muted">(—%)</span></p>
        <dl class="stage-details">
            <div><dt>Model version</dt><dd>—</dd></div>
            <div><dt>Run</dt><dd>—</dd></div>
        </dl>
    </section>

    <section class="stage" aria-labelledby="stage-3">
        <h2 id="stage-3" class="stage-label">3 · Review</h2>
        <p class="stage-main">—</p>
        <dl class="stage-details">
            <div><dt>Cancer type if changed</dt><dd>—</dd></div>
            <div><dt>Reviewed</dt><dd>—</dd></div>
        </dl>
    </section>

    <!-- Placeholder shows the "not reached yet" look -->
    <section class="stage stage-pending" aria-labelledby="stage-4">
        <h2 id="stage-4" class="stage-label">4 · Final report</h2>
        <p class="stage-main">Not yet</p>
        <p class="muted small-text">Shown once the report is released to the patient.</p>
    </section>
</div>

<!-- ===================================================================
     3 + 4. Charts
     =================================================================== -->
<div class="grid-2">

    <!-- 3. Probabilities for all six cancer types, highest first.
         Markers: "Doctor" on the initial diagnosis, "Report" on the final report. -->
    <section class="card" aria-labelledby="prob-heading">
        <div class="card-header">
            <h2 id="prob-heading">Model probabilities</h2>
        </div>
        <div id="chart-probabilities" class="chart-slot" role="img"
             aria-label="The model's probability for each cancer type, with the doctor's initial diagnosis and the final report marked">
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">Cancer type</th>
                            <th scope="col">Probability</th>
                            <th scope="col">Marked</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- TODO: from prediction_probabilities, ORDER BY probability DESC.
                             "Marked" says Doctor and/or Report on the matching rows. -->
                        <?php foreach (CANCER_TYPES as $c): ?>
                        <tr>
                            <td><span class="cancer-name"><span class="dot dot-<?= e(strtolower($c)) ?>" aria-hidden="true"></span><?= e($c) ?></span></td>
                            <td class="num">—</td>
                            <td></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <!-- Shape: {"cancer_types":[...],"probabilities":{"Breast":62.1,...},"doctor":"Breast","report":null} -->
        <script type="application/json" id="chart-probabilities-data"><?= json_encode(
            [
                'cancer_types'  => CANCER_TYPES,
                'probabilities' => array_fill_keys(CANCER_TYPES, null),
                'doctor'        => null,
                'report'        => null,   // stays null until the report is released
            ],
            JSON_HEX_TAG | JSON_HEX_AMP
        ) ?></script>
    </section>

    <!-- 4. Genes that weighed most, with this sample's mutations in them -->
    <section class="card" aria-labelledby="genes-heading">
        <div class="card-header">
            <h2 id="genes-heading">Genes that weighed most</h2>
        </div>
        <div id="chart-prediction-genes" class="chart-slot" role="img"
             aria-label="The ten genes that weighed most in the model's call">
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">Gene</th>
                            <th scope="col">Weight</th>
                            <th scope="col">Mutations in this sample</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- TODO: from prediction_genes, ORDER BY weight DESC LIMIT 10.
                             No rows: "Gene weights aren't available for this model version." -->
                        <?php for ($i = 0; $i < 3; $i++): ?>
                        <tr>
                            <td><span class="gene">—</span></td>
                            <td class="num">—</td>
                            <td class="num">—</td>
                        </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <!-- Shape: {"genes":[{"gene":"TP53","weight":0.18,"in_sample":true}, ...]} -->
        <script type="application/json" id="chart-prediction-genes-data"><?= json_encode(
            ['genes' => []],
            JSON_HEX_TAG | JSON_HEX_AMP
        ) ?></script>
    </section>
</div>

<!-- ===================================================================
     5. Feedback from oncologists and researchers, newest first.
     Researchers always see everyone's feedback. (Oncologists see researcher
     feedback only after the report is released; that rule lives on their page.)
     =================================================================== -->
<section class="card" aria-labelledby="feedback-heading">
    <div class="card-header">
        <h2 id="feedback-heading">Feedback <span class="muted">(—)</span></h2>
    </div>

    <!-- Agreement summary: one bar, a segment per answer.
         TODO: flex-grow of each segment = its count. -->
    <div class="agreement-summary">
        <div class="summary-bar" aria-hidden="true"></div>
        <ul class="summary-counts">
            <?php foreach (AGREEMENT_OPTIONS as $value => $label): ?>
            <li><span class="chip agreement-<?= e($value) ?>"><?= e($label) ?></span> <span class="num">—</span></li>
            <?php endforeach; ?>
        </ul>
    </div>

    <!-- TODO: foreach over feedback for this prediction ORDER BY updated_at DESC.
         Empty state: <p class="muted">No feedback yet. Be the first.</p> -->
    <ul class="feedback-list">
        <?php foreach (['Oncologist' => 'agree', 'Researcher' => 'partly'] as $role => $placeholderAgreement): ?>
        <li class="feedback-entry">
            <p class="feedback-meta">
                <strong>Dr —</strong> · <?= e($role) ?> · <span class="muted">updated —</span>
            </p>
            <p class="feedback-chips">
                <span class="chip agreement-<?= e($placeholderAgreement) ?>"><?= e(AGREEMENT_OPTIONS[$placeholderAgreement]) ?></span>
                <span class="chip">Useful —/5</span>
                <span class="chip">Their call: —</span>
                <!-- only when flagged: <span class="chip chip-error">Data quality: Too few mutations</span> -->
            </p>
            <p class="feedback-comment">—</p>
        </li>
        <?php endforeach; ?>
    </ul>
</section>

<!-- ===================================================================
     6. My feedback
     Same form oncologists use. POSTs to feedback-save.php, which checks
     everything again (the browser checks are only for speed) and comes back
     here with a message.
     =================================================================== -->
<section class="card" aria-labelledby="my-feedback-heading" id="feedback-form">
    <div class="card-header">
        <h2 id="my-feedback-heading">My feedback</h2>
        <?php if ($saved): ?>
        <span class="muted small-text">Last saved —</span>
        <?php endif; ?>
    </div>

    <?php if ($flash): ?>
    <p class="flash" role="status"><?= e($flash) ?></p>
    <?php endif; ?>

    <?php if ($errors): ?>
    <!-- Error summary: one link per problem, jumping to that field -->
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

        <!-- Agreement (required). The first radio carries the id so error links can focus it. -->
        <fieldset class="form-group">
            <legend>Agreement with the model <span class="required" aria-hidden="true">*</span></legend>
            <div class="choice-row">
                <?php $first = true; foreach (AGREEMENT_OPTIONS as $value => $label): ?>
                <label class="choice">
                    <input type="radio" name="agreement" value="<?= e($value) ?>" required
                           <?= $first ? 'id="agreement"' : '' ?>
                           <?= $formValue('agreement') === $value ? 'checked' : '' ?>>
                    <?= e($label) ?>
                </label>
                <?php $first = false; endforeach; ?>
            </div>
            <?= $fieldError('agreement') ?>
        </fieldset>

        <!-- Their own cancer type (optional). The hint shows when Disagree is picked (JS). -->
        <div class="field">
            <label for="assessed_cancer_type">My cancer type <span class="muted">(optional)</span></label>
            <select id="assessed_cancer_type" name="assessed_cancer_type">
                <option value="">No opinion</option>
                <?php foreach (CANCER_TYPES as $c): ?>
                <option <?= $formValue('assessed_cancer_type') === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="hint" id="disagree-hint" hidden>Which type do you think it is?</p>
            <?= $fieldError('assessed_cancer_type') ?>
        </div>

        <!-- Usefulness 1-5 (required) -->
        <fieldset class="form-group">
            <legend>How useful was the prediction? <span class="required" aria-hidden="true">*</span></legend>
            <div class="choice-row">
                <?php for ($n = 1; $n <= 5; $n++): ?>
                <label class="choice">
                    <input type="radio" name="usefulness" value="<?= $n ?>" required
                           <?= $n === 1 ? 'id="usefulness"' : '' ?>
                           <?= (string)$formValue('usefulness') === (string)$n ? 'checked' : '' ?>>
                    <?= $n ?><?= $n === 1 ? ' Not useful' : ($n === 5 ? ' Very useful' : '') ?>
                </label>
                <?php endfor; ?>
            </div>
            <?= $fieldError('usefulness') ?>
        </fieldset>

        <!-- Data quality: the reason is required only when the box is ticked.
             Without JS the reason is always visible; with JS it appears on ticking. -->
        <div class="form-group">
            <label class="choice">
                <input type="checkbox" name="quality_flag" id="quality_flag" value="1"
                       <?= $formValue('quality_flag') ? 'checked' : '' ?>>
                Data quality concern
            </label>
            <div class="field" id="quality-reason-field">
                <label for="quality_reason">Reason</label>
                <select id="quality_reason" name="quality_reason">
                    <option value="">Choose a reason</option>
                    <?php foreach (DATA_QUALITY_REASONS as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $formValue('quality_reason') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $fieldError('quality_reason') ?>
            </div>
        </div>

        <!-- Comment: optional, but required when the reason is "Other" -->
        <div class="field">
            <label for="comment">Comment</label>
            <textarea id="comment" name="comment" rows="4" maxlength="<?= FEEDBACK_COMMENT_MAX ?>"
                      aria-describedby="comment-count"><?= e($formValue('comment')) ?></textarea>
            <p class="hint counter" id="comment-count" data-max="<?= FEEDBACK_COMMENT_MAX ?>">
                <span class="num"><?= mb_strlen($formValue('comment')) ?></span> / <?= FEEDBACK_COMMENT_MAX ?>
            </p>
            <?= $fieldError('comment') ?>
        </div>

        <!-- Researcher feedback counts towards retraining unless they untick this -->
        <label class="choice">
            <input type="checkbox" name="include_in_training" value="1"
                   <?= ($old ? !empty($old['include_in_training']) : true) ? 'checked' : '' ?>>
            Include in retraining
        </label>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= $saved ? 'Update feedback' : 'Submit feedback' ?></button>
        </div>
    </form>
</section>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
