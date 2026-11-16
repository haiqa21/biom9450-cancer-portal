<?php
session_start();
require_once __DIR__ . '/../../includes/db.php';

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'patient') {
    header('Location: login.php');
    exit();
}

$doctors = $pdo->query(
    "SELECT first_name, last_name, speciality, institution 
    FROM professionals
    WHERE professional_type = 'oncologist'
    ORDER BY last_name, first_name"
)->fetchAll();

$pageTitle = 'Find a doctor';
$activePage = 'doctors'; 
$pageStyles = ['patient-dashboard.css'];
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1>Find a doctor</h1>
        <p>Oncologists who can look after your care</p>
    </div>
</div>

<section class="card" aria-labelledby="doctors-heading">
    <div class="card-header">
        <h2 id="doctors-heading">Oncologists</h2>
    </div>

    <?php if (!$doctors): ?>
    <p class="muted">No doctors are listed yet.</p> 
    <?php else: ?>
    <ul class="doctor-list">
        <?php foreach ($doctors as $d): ?>
        <li class="doctor-item">
            <div>
                <strong>Dr <?= e($d['first_name'] . ' ' . $d['last_name']) ?></strong>
                <div class="muted small">
                    <?= e($d['speciality'] ?: 'Oncology') ?> 
                    <?= $d['institution'] ? ' . ' . e($d['institution']) : '' ?>
                </div>
            </div>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</section>


<?php require __DIR__ . '/../../includes/footer.php'; ?>