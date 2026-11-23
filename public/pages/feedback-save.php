<?php
/*
 * feedback-save.php (POST only, no page of its own)
 * Receives the feedback form from research-prediction.php, checks it, saves it,
 * and redirects back to the form with a message.
 *
 * Post/redirect/get: it always ends with a 303 redirect, so refreshing the
 * comparison page afterwards never re-submits the form.
 *
 * The feedback table stores feedback_rating (1-5) and comments. One row per person per
 * prediction: submitting again updates that row (and its created_at, so the page can
 * show when it was last saved).
 */

require_once __DIR__ . '/../../includes/research.php';

// ---------------------------------------------------------------------------
// 1. POST only
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('This address only accepts the feedback form.');
}

// ---------------------------------------------------------------------------
// 2. Signed in as a researcher (anyone else is sent to login.php)
// ---------------------------------------------------------------------------
$currentUser = research_require_login();       // also connects: $pdo
$me = $currentUser['user_id'];

// ---------------------------------------------------------------------------
// 3. CSRF token matches the one in the session. hash_equals() compares in
//    constant time. A mismatch usually means the session expired.
// ---------------------------------------------------------------------------
$predictionId = (int)($_POST['prediction_id'] ?? 0);
$back = 'research-prediction.php?id=' . $predictionId . '#feedback-form';

if (!hash_equals(csrf_token(), (string)($_POST['csrf_token'] ?? ''))) {
    $_SESSION['form_errors'] = ['rating' => 'Your session expired. Please try again.'];
    $_SESSION['form_old'] = ['rating' => (string)($_POST['rating'] ?? ''), 'comment' => (string)($_POST['comment'] ?? '')];
    header('Location: ' . $back, true, 303);
    exit();
}

// ---------------------------------------------------------------------------
// 4. The prediction exists
// ---------------------------------------------------------------------------
$stmt = $pdo->prepare('SELECT prediction_id FROM predictions WHERE prediction_id = ?');
$stmt->execute([$predictionId]);
if (!$stmt->fetch()) {
    http_response_code(400);
    exit("That prediction isn't available.");
}

// ---------------------------------------------------------------------------
// 5. Check the fields (the same rules as the form; browser checks don't count)
// ---------------------------------------------------------------------------
$errors = [];

$rating = (int)($_POST['rating'] ?? 0);
if ($rating < 1 || $rating > 5) {
    $errors['rating'] = 'Rate how useful the prediction was, from 1 to 5.';
}

$comment = trim((string)($_POST['comment'] ?? ''));
if (mb_strlen($comment) > FEEDBACK_COMMENT_MAX) {
    $errors['comment'] = 'Keep the comment to ' . FEEDBACK_COMMENT_MAX . ' characters or fewer.';
}

if ($errors) {
    // send the messages and what was typed back to the form
    $_SESSION['form_errors'] = $errors;
    $_SESSION['form_old'] = ['rating' => $rating ? (string)$rating : '', 'comment' => $comment];
    header('Location: ' . $back, true, 303);
    exit();
}

// ---------------------------------------------------------------------------
// 6. Save: update my existing row for this prediction, or add one
// ---------------------------------------------------------------------------
$stmt = $pdo->prepare('SELECT feedback_id FROM feedback WHERE prediction_id = ? AND user_id = ?
                       ORDER BY created_at DESC, feedback_id DESC LIMIT 1');
$stmt->execute([$predictionId, $me]);
$existing = $stmt->fetchColumn();

if ($existing) {
    $pdo->prepare('UPDATE feedback SET feedback_rating = ?, comments = ?, created_at = NOW() WHERE feedback_id = ?')
        ->execute([$rating, $comment === '' ? null : $comment, $existing]);
    audit($pdo, $me, 'edit_feedback', 'feedback', (int)$existing, 'prediction ' . $predictionId);
    $_SESSION['flash'] = 'Feedback updated.';
} else {
    $pdo->prepare('INSERT INTO feedback (prediction_id, user_id, feedback_rating, comments) VALUES (?, ?, ?, ?)')
        ->execute([$predictionId, $me, $rating, $comment === '' ? null : $comment]);
    audit($pdo, $me, 'submit_feedback', 'feedback', (int)$pdo->lastInsertId(), 'prediction ' . $predictionId);
    $_SESSION['flash'] = 'Feedback saved.';
}

header('Location: ' . $back, true, 303);
exit();
