<?php
session_start();

require_once __DIR__ . '/../../includes/db.php';

// --------------------------------------------------
// Authentication
// --------------------------------------------------

if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    $_SESSION['role'] !== 'oncologist'
) {
    header('Location: ../login.php');
    exit;
}

$user_id = (int) $_SESSION['user_id'];

// --------------------------------------------------
// Helper functions
// --------------------------------------------------

function h($value): string {
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function formatDate($date): string {
    if (!$date) {
        return 'Not recorded';
    }

    $timestamp = strtotime((string) $date);
    return $timestamp === false ? 'Not recorded' : date('d M Y', $timestamp);
}

// --------------------------------------------------
// Retrieve oncologist profile
// --------------------------------------------------

$stmt = $pdo->prepare(" 
    SELECT first_name, last_name, speciality
    FROM professionals
    WHERE user_id = ?
      AND professional_type = 'oncologist'
    LIMIT 1
");
$stmt->execute([$user_id]);
$oncologist = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$oncologist) {
    http_response_code(403);
    exit('Oncologist profile not found.');
}

$fullName = $oncologist['first_name'] . ' ' . $oncologist['last_name'];

// --------------------------------------------------
// Retrieve only patients actively assigned to this oncologist.
// Diagnosis information is aggregated separately to avoid
// multiplying patient rows when joining diagnoses.
// --------------------------------------------------

$stmt = $pdo->prepare(" 
    SELECT
        p.patient_id,
        p.first_name,
        p.last_name,
        p.birthdate,
        p.sex,
        p.country,
        COALESCE(d.diagnoses, '') AS diagnoses,
        COALESCE(d.diagnosis_count, 0) AS diagnosis_count
    FROM oncologist_patients op
    INNER JOIN patients p
        ON p.patient_id = op.patient_id
    LEFT JOIN (
        SELECT
            pd.patient_id,
            GROUP_CONCAT(
                DISTINCT ct.cancer_name
                ORDER BY ct.cancer_name
                SEPARATOR ', '
            ) AS diagnoses,
            COUNT(DISTINCT pd.diagnosis_id) AS diagnosis_count
        FROM patient_diagnoses pd
        INNER JOIN cancer_types ct
            ON ct.cancer_type_id = pd.cancer_type_id
        GROUP BY pd.patient_id
    ) d
        ON d.patient_id = p.patient_id
    WHERE op.user_id = ?
      AND op.assignment_status = 'active'
    ORDER BY p.last_name, p.first_name, p.patient_id
");
$stmt->execute([$user_id]);
$patients = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalPatients = count($patients);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CancerInsight | Patient Directory</title>

    <link rel="stylesheet" href="../style/app.css">
    <link rel="stylesheet" href="../style/oncologist/TCGA.css">
    <link rel="stylesheet" href="../style/oncologist/dashboard.css">
</head>
<body>

<header class="clinical-header">
    <div class="brand">
        <div class="brand-mark">C</div>
        <div>
            <h1>CancerInsight</h1>
            <span>Clinical Intelligence</span>
        </div>
    </div>

    <div class="header-actions">
        <div class="doctor-profile">
            <div class="doctor-avatar">
                <?= h(strtoupper(substr($oncologist['first_name'], 0, 1))) ?>
            </div>
            <div class="doctor-info">
                <strong>Dr. <?= h($fullName) ?></strong>
                <span><?= h($oncologist['speciality'] ?: 'Oncologist') ?></span>
            </div>
        </div>
        <a class="logout-link" href="../logout.php">Log out</a>
    </div>
</header>

<main class="clinical-dashboard">

    <section class="welcome-section">
        <div>
            <p class="eyebrow">CLINICAL WORKSPACE</p>
            <h2>Patient Management</h2>
            <p class="welcome-description">
                View and access the patient records actively assigned to your care.
            </p>
        </div>

        <div class="welcome-date">
            <span>Total patients</span>
            <strong><?= number_format($totalPatients) ?></strong>
        </div>
    </section>

    <nav class="ci-nav" aria-label="Clinical workspace navigation">
        <a href="oncologist-dashboard.php">Dashboard</a>
        <a href="patients.php" aria-current="page">Patients</a>
        <a href="oncologist-dashboard.php#tasks">My Tasks</a>
        <a href="oncologist-dashboard.php#appointments">Appointments</a>
        <a href="oncologist-dashboard.php#reports">Reports</a>
    </nav>

    <section class="panel" id="patients">
        <div class="panel-heading">
            <div>
                <h3>Patient Directory</h3>
                <p>Patients currently assigned to your care</p>
            </div>
            <a href="register-patient.php" class="ci-button">+ Register patient</a>
        </div>

        <?php if ($patients): ?>
            <div class="ci-search">
                <input
                    type="search"
                    id="patientSearch"
                    placeholder="Search by patient name or ID..."
                    autocomplete="off"
                    aria-label="Search patients by name or ID"
                >
            </div>
        <?php endif; ?>

        <?php if (!$patients): ?>
            <div class="ci-empty">
                <h3>No patients assigned yet</h3>
                <p>Patients registered and assigned to you will appear here.</p>
                <a href="register-patient.php" class="ci-button">Register your first patient</a>
            </div>
        <?php else: ?>
            <div class="ci-table-wrap">
                <table class="ci-table">
                    <thead>
                        <tr>
                            <th>Patient ID</th>
                            <th>Name</th>
                            <th>Birth date</th>
                            <th>Sex</th>
                            <th>Country</th>
                            <th>Diagnosis</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="patientRows">
                        <?php foreach ($patients as $p): ?>
                            <tr>
                                <td>#<?= h($p['patient_id']) ?></td>
                                <td><?= h($p['first_name'] . ' ' . $p['last_name']) ?></td>
                                <td><?= h(formatDate($p['birthdate'])) ?></td>
                                <td><?= h($p['sex'] ? ucfirst($p['sex']) : 'Not recorded') ?></td>
                                <td><?= h($p['country'] ?: 'Not recorded') ?></td>
                                <td><?= h($p['diagnoses'] ?: 'Not recorded') ?></td>
                                <td>
                                    <a href="patient-details.php?id=<?= urlencode((string) $p['patient_id']) ?>">
                                        View record
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <p id="noSearchResults" class="ci-empty" hidden>
                No matching patients found.
            </p>
        <?php endif; ?>
    </section>

</main>

<script>
const searchInput = document.getElementById('patientSearch');
const patientRows = document.querySelectorAll('#patientRows tr');
const noResults = document.getElementById('noSearchResults');

if (searchInput) {
    searchInput.addEventListener('input', function () {
        const searchTerm = this.value.trim().toLowerCase();
        let visibleCount = 0;

        patientRows.forEach(row => {
            const matches = row.textContent.toLowerCase().includes(searchTerm);
            row.hidden = !matches;
            if (matches) visibleCount++;
        });

        if (noResults) {
            noResults.hidden = visibleCount > 0;
        }
    });
}
</script>

</body>
</html>

