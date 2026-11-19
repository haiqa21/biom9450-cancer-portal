<?php
/*
 * Doctor detail (research-doctor.php?id=<user_id>)
 * One oncologist: who they are, how their calls compare with the model and
 * the final reports, their patients and their review history.
 */

require_once __DIR__ . '/../../includes/helpers.php';   // e(), query_with(), hidden_query_inputs()

// ---------------------------------------------------------------------------
// Signed-in user
// ---------------------------------------------------------------------------
// TODO: delete this line and add a researcher-only check from the database.
$currentUser = ['user_id' => 0, 'name' => 'Dr Placeholder', 'role' => 'researcher'];

// ---------------------------------------------------------------------------
// Settings read by includes/header.php
// ---------------------------------------------------------------------------
$pageTitle  = 'Doctor';
$activePage = 'research-doctors';             // keeps "Doctors" highlighted in the side nav
$pageStyles = ['research-doctor.css'];

const ROWS_PER_PAGE = 20;

// ---------------------------------------------------------------------------
// Which doctor?
// ---------------------------------------------------------------------------
// The id in the URL is users.user_id of an oncologist. (int) turns anything
// that isn't a number into 0, so "?id=abc" and a missing id are both rejected.
$doctorId = (int)($_GET['id'] ?? 0);

// TODO: SELECT p.first_name, p.last_name, p.speciality, p.institution, u.created_at
//       FROM professionals p JOIN users u ON u.user_id = p.user_id
//       WHERE p.user_id = ? AND p.professional_type = 'oncologist'
// No row (unknown id, or the id of a researcher or patient) = not available.
$doctorFound = $doctorId > 0;

// ---------------------------------------------------------------------------
// Read the GET parameters
// ---------------------------------------------------------------------------
// Two independent pagers on one page, so each has its own parameter:
// ppage for the patients table, rpage for the review history.
$patientPage = max(1, (int)($_GET['ppage'] ?? 1));
$reviewPage  = max(1, (int)($_GET['rpage'] ?? 1));

// Review history filter. Values match what feedback.agreement will store.
$agreementOptions = [
    'agree'    => 'Agree',
    'partly'   => 'Partly agree',
    'disagree' => 'Disagree',
];
$agreement = array_key_exists($_GET['agreement'] ?? '', $agreementOptions) ? $_GET['agreement'] : '';

// ---------------------------------------------------------------------------
// Not available: show a short message instead of the page
// ---------------------------------------------------------------------------
// 404 so the browser and logs see it as missing.
if (!$doctorFound) {
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

// TODO: record the view: INSERT INTO audit_logs (... 'view_doctor', 'professionals', $doctorId ...)

require __DIR__ . '/../../includes/header.php';
?>

<!-- ===================================================================
     1. Profile
     =================================================================== -->
<nav class="breadcrumb" aria-label="Breadcrumb">
    <a href="research-doctors.php">Doctors</a> › Dr —
</nav>

<div class="page-header">
    <div class="profile">
        <!-- TODO: 'Dr ' . first_name . ' ' . last_name -->
        <h1>Dr —</h1>
        <p class="profile-meta">
            <span class="chip">Oncologist</span>
            <span>Speciality: —</span>
            <span>Institution: —</span>
            <!-- users.created_at, through format_date() -->
            <span>Account created —</span>
        </p>
    </div>
</div>

<!-- ===================================================================
     2. Tiles
     The counted tiles link to where those rows are listed.
     TODO: replace each "—" with this doctor's figures. Percentages are over
     their RELEASED reports only, and show "—" when there are none yet.
     =================================================================== -->
<div class="tiles">
    <a class="tile tile-link" href="#patients-heading">
        <span class="tile-value">—</span>
        <span class="tile-label">Patients</span>
    </a>
    <a class="tile tile-link" href="#reviews-heading">
        <span class="tile-value">—</span>
        <span class="tile-label">Reviews done</span>
    </a>
    <a class="tile tile-link" href="research-predictions.php?doctor=<?= $doctorId ?>&amp;status=pending_review">
        <span class="tile-value">—</span>
        <span class="tile-label">Awaiting review</span>
    </a>
    <div class="tile">
        <span class="tile-value">—</span>
        <span class="tile-label">Initial diagnosis matched report</span>
    </div>
    <div class="tile">
        <span class="tile-value">—</span>
        <span class="tile-label">Model matched report</span>
    </div>
</div>

<!-- ===================================================================
     3. How they rated the model
     Counts of this doctor's reviews that agreed, partly agreed or disagreed
     with the model's prediction.
     =================================================================== -->
<section class="card" aria-labelledby="agreement-heading">
    <div class="card-header">
        <h2 id="agreement-heading">Oncologist Model Rating</h2>
    </div>

    <div id="chart-doctor-agreement" class="chart-slot agreement-slot" role="img"
         aria-label="How many of this doctor's reviews agreed, partly agreed or disagreed with the model">
        <!-- Fallback: an empty bar and one labelled count per answer.
             TODO: give each segment flex-grow = its count so widths are proportional. -->
        <div class="agreement-bar" aria-hidden="true"></div>
        <ul class="agreement-counts">
            <?php foreach ($agreementOptions as $value => $label): ?>
            <li>
                <span class="chip agreement-<?= e($value) ?>"><?= e($label) ?></span>
                <span class="num">—</span>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <script type="application/json" id="chart-doctor-agreement-data"><?= json_encode(
        ['segments' => array_map(
            fn($label) => ['label' => $label, 'count' => 0],
            array_values($agreementOptions)
        )],
        JSON_HEX_TAG | JSON_HEX_AMP
    ) ?></script>
</section>

<!-- ===================================================================
     4. Patients (consenting only, de-identified), 20 per page
     Patients this doctor is on the care team for (primary or referred).
     =================================================================== -->
<section class="card" aria-labelledby="patients-heading">
    <div class="card-header">
        <h2 id="patients-heading">Patients</h2>
        <span class="muted small-text">Consenting patients only</span>
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
                <!-- TODO: foreach over ROWS_PER_PAGE rows from research_patients joined to
                     care_assignments for this doctor. "Latest prediction" is a status chip:
                     <span class="chip status-<status>">label</span>.
                     Empty state: <tr><td colspan="8" class="empty">No consenting patients yet.</td></tr> -->
                <?php for ($i = 0; $i < 4; $i++): ?>
                <tr>
                    <td><a class="row-link" href="research-patient.php?id=1&amp;doctor=<?= $doctorId ?>">Patient —</a></td>
                    <td class="num">—</td>
                    <td>—</td>
                    <td>—</td>
                    <td>—</td>
                    <td>—</td>
                    <td class="num">—</td>
                    <td>—</td>
                </tr>
                <?php endfor; ?>
            </tbody>
        </table>
    </div>

    <!-- TODO: real totals; show Next only when ppage < ceil(total / ROWS_PER_PAGE) -->
    <nav class="pager" aria-label="Patient pages">
        <span class="muted num">— of — patients</span>
        <span class="pager-links">
            <?php if ($patientPage > 1): ?>
            <a class="btn" href="<?= e(query_with(['ppage' => $patientPage - 1])) ?>#patients-heading">Previous</a>
            <?php endif; ?>
            <a class="btn" href="<?= e(query_with(['ppage' => $patientPage + 1])) ?>#patients-heading">Next</a>
        </span>
    </nav>
</section>

<!-- ===================================================================
     5. Review history: every prediction this doctor reviewed, 20 per page, filterable by agreement
     =================================================================== -->
<section class="card" aria-labelledby="reviews-heading">
    <div class="card-header">
        <h2 id="reviews-heading">Review history</h2>
    </div>

    <!-- Agreement filter. Keeps the id and the patients page; resets rpage. -->
    <form class="filters review-filters" method="get" action="#reviews-heading">
        <?= hidden_query_inputs(['agreement', 'rpage']) ?>
        <div class="field">
            <label for="agreement">Agreement with model</label>
            <select id="agreement" name="agreement">
                <option value="">All</option>
                <?php foreach ($agreementOptions as $value => $label): ?>
                <option value="<?= e($value) ?>" <?= $value === $agreement ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Filter</button>
        <a class="btn" href="<?= e(query_with(['agreement' => null, 'rpage' => null])) ?>#reviews-heading">Clear</a>
    </form>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th scope="col">Date</th>
                    <th scope="col">Patient</th>
                    <th scope="col">Initial diagnosis</th>
                    <th scope="col">Model's top prediction</th>
                    <th scope="col">Final report</th>
                    <th scope="col">Agreement</th>
                    <th scope="col"><span class="visually-hidden">Open</span></th>
                </tr>
            </thead>
            <tbody>
                <!-- TODO: foreach over ROWS_PER_PAGE of this doctor's reviews, ORDER BY review date DESC,
                     with AND agreement = ? when the filter is set.
                     Date shows month + year only (researchers never see full dates).
                     "Final report" shows "—" until the report is released.
                     Empty state: <tr><td colspan="7" class="empty">No reviews match this filter.</td></tr> -->
                <?php foreach (['agree', 'disagree', 'partly'] as $placeholderAgreement): ?>
                <tr>
                    <td>—</td>
                    <td><a class="row-link" href="research-patient.php?id=1&amp;doctor=<?= $doctorId ?>">Patient —</a></td>
                    <td>—</td>
                    <td>— <span class="muted">(—%)</span></td>
                    <td>—</td>
                    <td><span class="chip agreement-<?= $placeholderAgreement ?>"><?= e($agreementOptions[$placeholderAgreement]) ?></span></td>
                    <td><a href="research-prediction.php?id=0">Compare</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- TODO: real totals; show Next only when rpage < ceil(total / ROWS_PER_PAGE) -->
    <nav class="pager" aria-label="Review pages">
        <span class="muted num">— of — reviews</span>
        <span class="pager-links">
            <?php if ($reviewPage > 1): ?>
            <a class="btn" href="<?= e(query_with(['rpage' => $reviewPage - 1])) ?>#reviews-heading">Previous</a>
            <?php endif; ?>
            <a class="btn" href="<?= e(query_with(['rpage' => $reviewPage + 1])) ?>#reviews-heading">Next</a>
        </span>
    </nav>
</section>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
