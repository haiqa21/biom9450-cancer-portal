<?php
// Terminal-only test for step 4.2. Run from the project folder:
//   php tests/test-save-prediction.php
// Lives outside public/, so nobody can open it in a browser.

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/save-prediction.php';

$userId = (int)$pdo->query("SELECT user_id FROM users WHERE username = 'test_onc'")->fetchColumn();
if ($userId === 0) {
    exit("No test user found. Run the INSERT INTO users line first.\n");
}

// 3 = opted in, 2 = not opted in, 99999 = does not exist
foreach ([3, 2, 99999] as $patientId) {
    $r = run_and_save_prediction($pdo, $patientId, $userId);
    if ($r['ok']) {
        echo "Patient $patientId: saved prediction #{$r['prediction_id']} -> {$r['result']['top_type']} "
           . round($r['result']['confidence'] * 100) . '%'
           . ($r['result']['uncertain'] ? ' (UNCERTAIN)' : '')
           . ' | top genes: ' . implode(', ', array_keys($r['result']['top_genes'])) . "\n";
    } else {
        echo "Patient $patientId: {$r['error']}\n";
    }
}