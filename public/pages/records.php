<?php
session_start();
require_once __DIR__ . '/../../includes/db.php';

//Only signed-in patients may view this page 
if (empty($_SESSION['user_id']) || ($SESSION['role'] ?? '') !== 'patient') {
    header('Location: login.php');
    exit();
}

$stmt = $pdo->prepare('
    SELECT patient_id, first_name, last_name, birthdate, sex, country
    FROM patients WHERE user_id = ?'
);
$stmt->execute([$_SESSION['user_id']]);
$patient = $stmt->fetch();

if (!$patient) {
    http_response_code(404);
    exit('No patient record is linked to this account. Please contact your care team.');
}

$pid = $patient['patient_id'];

$stmt = $pdo->prepare(
    'SELECT ct.cancer_name, d.date_of_diagnosis, d.stage, d.status
     FROM patient_diagnoses d
     JOIN cancer_types ct ON ct.cancer_type_id = d.cancer_type_id
     WHERE d.patient_id = ?
     ORDER BY d.date_of_diagnosis DESC'
);
$stmt->execute([$pid]);
$diagnoses = $stmt->fetchAll();

$stmt = $pdo->prepare(
    'SELECT s.sample_code, s.date_detected, COUNT(pm.mutation_id) AS mutation_count
     FROM samples s
     LEFT JOIN patient_mutations pm ON pm.sample_id = s.sample_id
     WHERE s.patient_id = ?
     GROUP BY s.sample_id, s.sample_code, s.date_detected
     ORDER BY s.date_detected DESC'
);
$stmt->execute([$pid]);
$samples = $stmt->fetchAll();

$stageLabel = fn($stage) => $stage === 'unknown' ? 'Not recorded' : 'Stage ' . $stage;

$pageTitle  = 'My records';
$activePage = 'records';
$pageStyles = ['patient-dashboard.css'];
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1>My records</h1>
        <p>Your personal details, diagnosis history and tests</p>
    </div>
</div>

<div class="grid-2">
    <section class="card" aria-labelledby="details-heading">
        <div class="card-header"><h2 id="details-heading">Personal details</h2></div>
        <dl class="details">
            <div><dt>Name</dt><dd><?= e($patient['first_name'] . ' ' . $patient['last_name']) ?></dd></div>
            <div><dt>Date of birth</dt><dd><?= e(format_date($patient['birthdate'])) ?></dd></div>
            <div><dt>Age</dt><dd><?= e(age_from_birthdate($patient['birthdate'])) ?></dd></div>
            <div><dt>Sex</dt><dd><?= e(ucfirst($patient['sex'])) ?></dd></div>
            <div><dt>Country</dt><dd><?= e($patient['country'] ?? 'Not recorded') ?></dd></div>
        </dl>
        <p class="muted small">Please contact your care team to update these details.</p>
    </section>

    <section class="card" aria-labelledby="tests-heading">
        <div class="card-header"><h2 id="tests-heading">Tests</h2></div>
        <?php if (!$samples): ?>
        <p class="muted">No tests have been recorded yet.</p>
        <?php else: ?>
        <ul class="sample-list">
            <?php foreach ($samples as $s): ?>
            <li>
                <span class="mono"><?= e($s['sample_code']) ?></span>
                <span class="muted">
                    <?= $s['date_detected'] ? 'Tested ' . e(format_date($s['date_detected'])) . ' · ' : '' ?>
                    <?= e($s['mutation_count']) ?> changes
                </span>
            </li>
            <?php endforeach; ?>
        </ul>
        <a class="btn btn-small" href="patient-dashboard.php">View full results</a>
        <?php endif; ?>
    </section>
</div>

<section class="card" aria-labelledby="history-heading">
    <div class="card-header"><h2 id="history-heading">Diagnosis history</h2></div>
    <?php if (!$diagnoses): ?>
    <p class="muted">No diagnosis has been recorded yet.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th scope="col">Date diagnosed</th>
                    <th scope="col">Cancer type</th>
                    <th scope="col">Stage</th>
                    <th scope="col">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($diagnoses as $d): ?>
                <tr>
                    <td><?= e(format_date($d['date_of_diagnosis'])) ?></td>
                    <td><?= e($d['cancer_name']) ?></td>
                    <td><?= e($stageLabel($d['stage'])) ?></td>
                    <td><?= $d['status'] === 'active' ? 'Active treatment' : 'In remission' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../../includes/footer.php'; ?>