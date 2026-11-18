<?php

require_once __DIR__ . '/../../includes/helpers.php';   // e(), query_with(), hidden_query_inputs(), lists

// ---------------------------------------------------------------------------
// Signed-in user
// ---------------------------------------------------------------------------
// TODO: delete this line and add a researcher-only check from the database.
$currentUser = ['user_id' => 0, 'name' => 'Dr Placeholder', 'role' => 'researcher'];

// ---------------------------------------------------------------------------
// Settings read by includes/header.php
// ---------------------------------------------------------------------------
$pageTitle  = 'My feedback';
$activePage = 'my-feedback';
$pageStyles = ['my-feedback.css'];

const FEEDBACK_PER_PAGE = 10;

// ---------------------------------------------------------------------------
// Filters (only values from these lists are accepted)
// ---------------------------------------------------------------------------
$yesNo = ['yes' => 'Yes', 'no' => 'No'];

$agreement = array_key_exists($_GET['agreement'] ?? '', AGREEMENT_OPTIONS) ? $_GET['agreement'] : '';
$flagged   = array_key_exists($_GET['flagged'] ?? '', $yesNo) ? $_GET['flagged'] : '';
$released  = array_key_exists($_GET['released'] ?? '', $yesNo) ? $_GET['released'] : '';
$feedbackPage = max(1, (int)($_GET['p'] ?? 1));   // not $page: header.php's nav loop overwrites $page

// TODO:: feedback WHERE user_id = :me, joined through
// research_patients (so a patient who withdrew consent drops out of the list), plus
//   AND f.agreement = ?                       when $agreement is set
//   AND f.data_quality_flag = 1 / 0           when $flagged is yes / no
//   AND (r.report_id IS NOT NULL) = 1 / 0     when $released is yes / no
// ORDER BY f.updated_at DESC LIMIT 10 OFFSET ...

// TODO: record the view: audit_logs action 'view_my_feedback'

require __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1>My feedback</h1>
        <p>Everything you've submitted, newest first</p>
    </div>
</div>

<!-- ===================================================================
     1. Tiles (over all of my feedback, not just the filtered rows)
     "Matched the final report" only counts entries where I picked a cancer
     type AND the report has been released; otherwise there's nothing to match.
     =================================================================== -->
<div class="tiles">
    <div class="tile">
        <span class="tile-value">—</span>
        <span class="tile-label">Feedback given</span>
    </div>
    <div class="tile">
        <span class="tile-value">—</span>
        <span class="tile-label">Agreed with the model</span>
    </div>
    <div class="tile">
        <span class="tile-value">—</span>
        <span class="tile-label">Matched the final report</span>
        <span class="muted small-text">where you picked a type and the report is released</span>
    </div>
    <div class="tile">
        <span class="tile-value">—</span>
        <span class="tile-label">Marked for retraining</span>
    </div>
</div>

<!-- ===================================================================
     2 + 3. Filters and table
     =================================================================== -->
<section class="card" aria-labelledby="list-heading">
    <h2 id="list-heading" class="visually-hidden">My feedback entries</h2>

    <form class="filters list-filters" method="get" action="#list-heading">
        <?= hidden_query_inputs(['agreement', 'flagged', 'released', 'p']) ?>
        <div class="field">
            <label for="agreement">My agreement</label>
            <select id="agreement" name="agreement">
                <option value="">Any</option>
                <?php foreach (AGREEMENT_OPTIONS as $value => $label): ?>
                <option value="<?= e($value) ?>" <?= $value === $agreement ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="flagged">Data quality flagged</label>
            <select id="flagged" name="flagged">
                <option value="">Any</option>
                <?php foreach ($yesNo as $value => $label): ?>
                <option value="<?= e($value) ?>" <?= $value === $flagged ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="released">Report released</label>
            <select id="released" name="released">
                <option value="">Any</option>
                <?php foreach ($yesNo as $value => $label): ?>
                <option value="<?= e($value) ?>" <?= $value === $released ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Filter</button>
        <a class="btn" href="<?= e(query_with(['agreement' => null, 'flagged' => null, 'released' => null, 'p' => null])) ?>#list-heading">Clear</a>
    </form>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th scope="col">Patient</th>
                    <th scope="col">Sample</th>
                    <th scope="col">Model's call</th>
                    <th scope="col">My agreement</th>
                    <th scope="col">My type</th>
                    <th scope="col">Final report</th>
                    <th scope="col">Match?</th>
                    <th scope="col">Usefulness</th>
                    <th scope="col">Flagged</th>
                    <th scope="col">Updated</th>
                    <th scope="col"><span class="visually-hidden">Edit</span></th>
                </tr>
            </thead>
            <tbody>
                <!-- TODO: foreach over FEEDBACK_PER_PAGE rows.
                     Match? = ✓ when my type equals the final report, ✗ when not,
                     "—" when either is missing (with visually-hidden text for screen readers).
                     Empty states:
                       no feedback at all: "You haven't given any feedback yet." + link to
                                           research-predictions.php (Needs my feedback)
                       filters:            "No feedback matches these filters." -->
                <?php foreach (['agree', 'disagree', 'partly'] as $i => $placeholderAgreement): ?>
                <tr>
                    <td><a class="row-link" href="research-patient.php?id=1">Patient —</a></td>
                    <td>Sample —</td>
                    <td>—</td>
                    <td><span class="chip agreement-<?= e($placeholderAgreement) ?>"><?= e(AGREEMENT_OPTIONS[$placeholderAgreement]) ?></span></td>
                    <td>—</td>
                    <td>—</td>
                    <td>—</td>
                    <td class="num">— / 5</td>
                    <td>—</td>
                    <td>—</td>
                    <td><a href="research-prediction.php?id=1#feedback-form">Edit</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- TODO: real totals; show Next only when p < ceil(total / FEEDBACK_PER_PAGE) -->
    <nav class="pager" aria-label="Feedback pages">
        <span class="muted num">— of — entries</span>
        <span class="pager-links">
            <?php if ($feedbackPage > 1): ?>
            <a class="btn" href="<?= e(query_with(['p' => $feedbackPage - 1])) ?>#list-heading">Previous</a>
            <?php endif; ?>
            <a class="btn" href="<?= e(query_with(['p' => $feedbackPage + 1])) ?>#list-heading">Next</a>
        </span>
    </nav>
</section>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
