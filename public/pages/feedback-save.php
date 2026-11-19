<?php
/*
 * feedback-save.php (POST only)
 * Receives the feedback form from research-prediction.php, checks it, saves
 * it, and redirects back to the form with a message.
 *
 * Post/redirect/get: the handler always ends with a 303 redirect, so
 * refreshing the comparison page afterwards never re-submits the form.
 */

require_once __DIR__ . '/../../includes/helpers.php';   // e(), csrf_token(), lists

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---------------------------------------------------------------------------
// 1. POST only
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('This address only accepts the feedback form.');
}

// ---------------------------------------------------------------------------
// 2. Signed in as a researcher or oncologist
// ---------------------------------------------------------------------------
// TODO: if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit(); }
//       then look up professionals.professional_type for $_SESSION['user_id'];
//       anyone who isn't a researcher or oncologist -> login.php.
$userId = (int)($_SESSION['user_id'] ?? 0);
$authorRole = 'researcher';   // TODO: from professional_type

// ---------------------------------------------------------------------------
// 3. CSRF token matches the one in the session
// ---------------------------------------------------------------------------
// hash_equals() compares in constant time, so the token can't be guessed by
// timing. A mismatch usually means the session expired while the form was open.
$predictionId = (int)($_POST['prediction_id'] ?? 0);
$back = 'research-prediction.php?id=' . $predictionId . '#feedback-form';

if (!hash_equals(csrf_token(), (string)($_POST['csrf_token'] ?? ''))) {
    $_SESSION['form_errors'] = ['agreement' => 'Your session expired. Please try again.'];
    $_SESSION['form_old'] = $_POST;
    header('Location: ' . $back, true, 303);
    exit();
}

// ---------------------------------------------------------------------------
// 4-6. The prediction exists, is past awaiting_diagnosis, and this user may
//      give feedback on it
// ---------------------------------------------------------------------------
// TODO: SELECT status, patient_id FROM predictions JOIN samples ... WHERE prediction_id = ?
//   - no row, or status = 'awaiting_diagnosis'          -> 400 + "not available"
//   - researcher: patient must be in research_patients  (consenting)
//   - oncologist: must be on the patient's care team
if ($predictionId <= 0) {
    http_response_code(400);
    exit('That prediction isn\'t available.');
}

// ---------------------------------------------------------------------------
// 7. Check every field (same rules as the form; the browser checks don't count)
// ---------------------------------------------------------------------------
$errors = [];

$agreement = (string)($_POST['agreement'] ?? '');
if (!array_key_exists($agreement, AGREEMENT_OPTIONS)) {
    $errors['agreement'] = 'Choose whether you agree with the model.';
}

$usefulness = (int)($_POST['usefulness'] ?? 0);
if ($usefulness < 1 || $usefulness > 5) {
    $errors['usefulness'] = 'Rate how useful the prediction was, from 1 to 5.';
}

// Optional, but if given it must be one of the six types. ?? are we storing more than the six types?
// TODO: store the matching cancer_type_id instead of the name.
$assessedType = trim((string)($_POST['assessed_cancer_type'] ?? ''));
if ($assessedType !== '' && !in_array($assessedType, CANCER_TYPES, true)) {
    $errors['assessed_cancer_type'] = 'Choose a cancer type from the list, or "No opinion".';
}

$qualityFlag = !empty($_POST['quality_flag']);
$qualityReason = (string)($_POST['quality_reason'] ?? '');
if ($qualityFlag && !array_key_exists($qualityReason, DATA_QUALITY_REASONS)) {
    $errors['quality_reason'] = 'Choose a reason for the data quality concern.';
}
if (!$qualityFlag) {
    $qualityReason = '';   // a reason without the box ticked is ignored
}

$comment = trim((string)($_POST['comment'] ?? ''));
if (mb_strlen($comment) > FEEDBACK_COMMENT_MAX) {
    $errors['comment'] = 'Keep the comment to ' . FEEDBACK_COMMENT_MAX . ' characters or fewer.';
} elseif ($qualityReason === 'other' && $comment === '') {
    $errors['comment'] = 'Describe the data quality concern in the comment.';
}

$includeInTraining = !empty($_POST['include_in_training']);

// ---------------------------------------------------------------------------
// 8. Errors: send the messages and the typed values back to the form
// ---------------------------------------------------------------------------
if ($errors) {
    $_SESSION['form_errors'] = $errors;
    $_SESSION['form_old'] = [
        'agreement'            => $agreement,
        'assessed_cancer_type' => $assessedType,
        'usefulness'           => $usefulness ?: '',
        'quality_flag'         => $qualityFlag,
        'quality_reason'       => $qualityReason ?: (string)($_POST['quality_reason'] ?? ''),
        'comment'              => $comment,
        'include_in_training'  => $includeInTraining,
    ];
    header('Location: ' . $back, true, 303);
    exit();
}

// ---------------------------------------------------------------------------
// 9-10. Save (one row per person per prediction) and audit
// ---------------------------------------------------------------------------
// TODO: upsert, using the unique key on (prediction_id, user_id):

// ---------------------------------------------------------------------------
// 11. Success message and back to the form
// ---------------------------------------------------------------------------
$_SESSION['flash'] = 'Feedback checked. (Not saved yet: the database isn\'t connected.)';
header('Location: ' . $back, true, 303);
exit();
