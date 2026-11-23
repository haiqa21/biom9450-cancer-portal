<?php
/*
 * My feedback (my-feedback.php)
 * Everything this researcher has submitted, newest first, so they can find and edit
 * past entries and see how the predictions they rated compared with the diagnosis.
 *
 *   1. Tiles     feedback given, average rating I gave, share of those predictions that
 *                matched the diagnosis, predictions still waiting for my feedback
 *   2. Filters   rating, model, matched diagnosis (GET form)
 *   3. Table     10 per page, newest first; Edit opens the comparison page's form
 */

require_once __DIR__ . '/../../includes/research.php';

$currentUser = research_require_login();       // also connects: $pdo
$me = $currentUser['user_id'];

$pageTitle  = 'My feedback';
$activePage = 'my-feedback';
$pageStyles = ['my-feedback.css'];

const FEEDBACK_PER_PAGE = 10;

$types = cancer_types($pdo);
$versions = model_versions($pdo);

// ---------------------------------------------------------------------------
// Filters (only values from these lists are accepted)
// ---------------------------------------------------------------------------
$rating  = in_array($_GET['rating'] ?? '', ['1', '2', '3', '4', '5'], true) ? (int)$_GET['rating'] : 0;
$version = in_array($_GET['version'] ?? '', $versions, true) ? $_GET['version'] : '';
$match   = in_array($_GET['match'] ?? '', ['yes', 'no'], true) ? $_GET['match'] : '';

$where = ['f.user_id = ?'];
$params = [$me];
if ($rating)          { $where[] = 'f.feedback_rating = ?'; $params[] = $rating; }
if ($version !== '')  { $where[] = 'pv.model_used = ?';     $params[] = $version; }
if ($match !== '')    { $where[] = 'pv.correct = ?';        $params[] = $match === 'yes' ? 1 : 0; }
$from = 'FROM feedback f JOIN (' . PREDICTION_SQL . ') pv ON pv.prediction_id = f.prediction_id
         WHERE ' . implode(' AND ', $where);

// ---------------------------------------------------------------------------
// 1. Tiles (over all my feedback, not just the filtered rows)
// ---------------------------------------------------------------------------
$stmt = $pdo->prepare('SELECT COUNT(*) AS given, AVG(f.feedback_rating) AS avg_rating, AVG(pv.correct) AS matched
                       FROM feedback f JOIN (' . PREDICTION_SQL . ') pv ON pv.prediction_id = f.prediction_id
                       WHERE f.user_id = ?');
$stmt->execute([$me]);
$tiles = $stmt->fetch();
$stmt = $pdo->prepare('SELECT COUNT(*) FROM predictions p WHERE NOT EXISTS
                       (SELECT 1 FROM feedback f WHERE f.prediction_id = p.prediction_id AND f.user_id = ?)');
$stmt->execute([$me]);
$waiting = (int)$stmt->fetchColumn();

// ---------------------------------------------------------------------------
// 3. The table
// ---------------------------------------------------------------------------
$stmt = $pdo->prepare("SELECT COUNT(*) $from");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$feedbackPage = page_number('p', $total, FEEDBACK_PER_PAGE);   // not $page: header.php's nav loop uses $page

$stmt = $pdo->prepare(
    "SELECT f.feedback_rating, f.comments, f.created_at AS saved_at, pv.*
     $from
     ORDER BY f.created_at DESC, f.feedback_id DESC
     LIMIT " . FEEDBACK_PER_PAGE . ' OFFSET ' . (($feedbackPage - 1) * FEEDBACK_PER_PAGE)
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

audit($pdo, $me, 'view_my_feedback');

require __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1>My feedback</h1>
        <p>Everything you've submitted, newest first</p>
    </div>
</div>

<!-- ===================================================================
     1. Tiles
     =================================================================== -->
<div class="tiles">
    <div class="tile">
        <span class="tile-value"><?= number_format($tiles['given']) ?></span>
        <span class="tile-label">Feedback given</span>
    </div>
    <div class="tile">
        <span class="tile-value"><?= $tiles['avg_rating'] === null ? '—' : number_format((float)$tiles['avg_rating'], 1) . ' / 5' ?></span>
        <span class="tile-label">Average rating you gave</span>
    </div>
    <div class="tile">
        <span class="tile-value"><?= pct($tiles['given'] ? $tiles['matched'] : null) ?></span>
        <span class="tile-label">Of those predictions matched the diagnosis</span>
    </div>
    <a class="tile tile-link" href="research-predictions.php?tab=needs">
        <span class="tile-value"><?= number_format($waiting) ?></span>
        <span class="tile-label">Predictions still waiting for your feedback</span>
    </a>
</div>

<!-- ===================================================================
     2 + 3. Filters and table
     =================================================================== -->
<section class="card" aria-labelledby="list-heading">
    <h2 id="list-heading" class="visually-hidden">My feedback entries</h2>

    <form class="filters list-filters" method="get" action="#list-heading">
        <?= hidden_query_inputs(['rating', 'version', 'match', 'p']) ?>
        <div class="field">
            <label for="rating">My rating</label>
            <select id="rating" name="rating">
                <option value="">Any</option>
                <?php for ($r = 1; $r <= 5; $r++): ?>
                <option value="<?= $r ?>" <?= $r === $rating ? 'selected' : '' ?>><?= $r ?> / 5</option>
                <?php endfor; ?>
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
        <button type="submit" class="btn btn-primary">Filter</button>
        <a class="btn" href="<?= e(query_with(['rating' => null, 'version' => null, 'match' => null, 'p' => null])) ?>#list-heading">Clear</a>
    </form>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th scope="col">Patient</th>
                    <th scope="col">Model</th>
                    <th scope="col">Model's call</th>
                    <th scope="col">Diagnosis</th>
                    <th scope="col">Matched?</th>
                    <th scope="col">My rating</th>
                    <th scope="col">My comment</th>
                    <th scope="col">Saved</th>
                    <th scope="col"><span class="visually-hidden">Edit</span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= patient_link((int)$r['patient_id']) ?></td>
                    <td><?= e(model_label($r['model_used'])) ?></td>
                    <td><?= cancer_chip($types[(int)$r['top_type_id']] ?? null) ?> <span class="muted num"><?= pct($r['top_probability']) ?></span></td>
                    <td><?= cancer_chip($types[(int)$r['dx_type_id']] ?? null) ?></td>
                    <td><?= correct_mark($r['correct'] === null ? null : (bool)$r['correct']) ?></td>
                    <td><?= rating_chip($r['feedback_rating']) ?></td>
                    <td class="comment-cell"><?= $r['comments'] === null || $r['comments'] === '' ? '<span class="muted">—</span>' : e(mb_strimwidth($r['comments'], 0, 90, '…')) ?></td>
                    <td><?= e(format_date($r['saved_at'])) ?></td>
                    <td><a href="research-prediction.php?id=<?= (int)$r['prediction_id'] ?>#feedback-form">Edit</a></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                <tr><td colspan="9" class="empty">
                    <?php if ((int)$tiles['given'] === 0): ?>
                    You haven't given any feedback yet. <a href="research-predictions.php?tab=needs">Find predictions that need it</a>
                    <?php else: ?>
                    No feedback matches these filters.
                    <?php endif; ?>
                </td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?= pager('p', $feedbackPage, FEEDBACK_PER_PAGE, $total, 'list-heading', 'entries') ?>
</section>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
