<?php
session_start();
require_once __DIR__ . '/../../includes/dashboard-data.php';

$src = dashboard_source();
$currentUser = require_professional($src, 'oncologist');

$options = dashboard_filter_options($src);
$stats = dashboard_stats($src);

$patientSearch = trim($_GET['q'] ?? '');
$patientCancer = trim($_GET['cancer'] ?? '');
$patientPage = max(1, (int)($_GET['ppage'] ?? 1));
[$patients, $patientTotal] = dashboard_patients($src, $patientSearch, $patientCancer, $patientPage);

$filters = dashboard_mutation_filters($_GET);
$mutationPage = max(1, (int)($_GET['mpage'] ?? 1));
[$mutations, $mutationTotal] = dashboard_mutations($src, $filters, $mutationPage);

$selectedMutation = (int)($_GET['mutation'] ?? 0);
$selectedPatient = (int)($_GET['patient'] ?? 0);
$related = $selectedMutation ? dashboard_related($src, $selectedMutation, $selectedPatient) : null;

dashboard_audit($src, (int)$currentUser['user_id'], 'view_dashboard', http_build_query(array_filter($filters, fn($v) => $v !== '' && $v !== null)) ?: null);

$pageTitle  = 'Dashboard';
$activePage = 'oncologist-dashboard';
$pageStyles = ['dashboard.css'];
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1>Welcome back, Dr <?= e($currentUser['last_name']) ?></h1>
    </div>
    <a class="btn btn-primary" href="new-patient.php">New patient</a>
</div>

<div class="tiles">
    <div class="tile"><span class="tile-value"><?= number_format($stats['patients']) ?></span><span class="tile-label">Patients</span></div>
    <div class="tile"><span class="tile-value"><?= number_format($stats['samples']) ?></span><span class="tile-label">Samples</span></div>
    <div class="tile"><span class="tile-value"><?= number_format($stats['patient_mutations']) ?></span><span class="tile-label">Patient mutations</span></div>
    <div class="tile"><span class="tile-value"><?= number_format($stats['awaiting_feedback']) ?></span><span class="tile-label">Predictions without feedback</span></div>
</div>

<?php require __DIR__ . '/../../includes/partials/patient-table.php'; ?>

<?php require __DIR__ . '/../../includes/partials/mutation-explorer.php'; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
