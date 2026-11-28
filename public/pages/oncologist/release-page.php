<?php
session_start();

require_once __DIR__ . '/../../includes/db.php';

// Authentication
if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    $_SESSION['role'] !== 'oncologist'
) {
    header('Location: ../login.php');
    exit;
}

$user_id = (int) $_SESSION['user_id'];

$report_id = filter_input(INPUT_GET, 'report_id', FILTER_VALIDATE_INT);

if (!$report_id || $report_id < 1) {
    die('Invalid report ID.');
}

function h($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// CSRF token
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION['csrf'];

try {
    // Verify oncologist
    $stmt = $pdo->prepare(" 
        SELECT professional_id
        FROM professionals
        WHERE user_id = ?
          AND professional_type = 'oncologist'
    ");

    $stmt->execute([$user_id]);

    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        die('Oncologist access required.');
    }

    // Retrieve report and verify patient assignment
    $stmt = $pdo->prepare(" 
        SELECT
            r.report_id,
            r.patient_id,
            r.report_title,
            r.report_summary,
            r.report_status,
            r.created_at,
            p.first_name,
            p.last_name
        FROM oncologist_reports r
        INNER JOIN patients p
            ON p.patient_id = r.patient_id
        INNER JOIN oncologist_patients op
            ON op.patient_id = r.patient_id
        WHERE r.report_id = ?
          AND r.user_id = ?
          AND op.user_id = ?
          AND op.assignment_status = 'active'
    ");

    $stmt->execute([
        $report_id,
        $user_id,
        $user_id
    ]);

    $report = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$report) {
        die('Report not found or access denied.');
    }

    // Only drafts can be released
    if ($report['report_status'] !== 'draft') {
        die('This report has already been released or cannot be released.');
    }

    $error = '';

    // Handle release request
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Verify CSRF token
        if (
            !isset($_POST['csrf']) ||
            !hash_equals($_SESSION['csrf'], $_POST['csrf'])
        ) {
            $error = 'Invalid request. Please reload the page.';
        }

        if (!$error) {
            // Release only if report is still a draft
            $stmt = $pdo->prepare(" 
                UPDATE oncologist_reports
                SET
                    report_status = 'released',
                    released_at = CURRENT_TIMESTAMP
                WHERE report_id = ?
                  AND patient_id = ?
                  AND user_id = ?
                  AND report_status = 'draft'
            ");

            $stmt->execute([
                $report_id,
                $report['patient_id'],
                $user_id
            ]);

            if ($stmt->rowCount() === 1) {
                $released = true;
            } else {
                $error = 'The report could not be released. It may have already been released.';
            }
        }
    }
} catch (PDOException $e) {
    error_log('Report release error: ' . $e->getMessage());
    die('Unable to process the report release.');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Release Clinical Report | CancerInsight</title>
</head>
<body>
    <main class="page-container">
        <?php if (isset($released) && $released): ?>
            <h1>Report Released Successfully</h1>

            <p>
                The clinical report has been marked as released.
            </p>

            <p>
                <strong>Patient:</strong>
                <?= h($report['first_name'] . ' ' . $report['last_name']) ?>
            </p>

            <p>
                <strong>Report:</strong>
                <?= h($report['report_title']) ?>
            </p>

            <p>
                The release timestamp has been recorded.
            </p>

            <p>
                <a href="report-editor.php?patient_id=<?= (int) $report['patient_id'] ?>">
                    Return to Report Editor
                </a>
            </p>

            <p>
                <a href="patient-details.php?id=<?= (int) $report['patient_id'] ?>">
                    Return to Patient Details
                </a>
            </p>
        <?php else: ?>
            <h1>Release Clinical Report</h1>

            <?php if ($error): ?>
                <p class="error-message">
                    <?= h($error) ?>
                </p>
            <?php endif; ?>

            <p>
                <strong>Patient:</strong>
                <?= h($report['first_name'] . ' ' . $report['last_name']) ?>
            </p>

            <p>
                <strong>Report title:</strong>
                <?= h($report['report_title']) ?>
            </p>

            <h2>Report Content</h2>

            <div class="record-card">
                <?= nl2br(h($report['report_summary'])) ?>
            </div>

            <p>
                <strong>Current status:</strong>
                Draft
            </p>

            <p>
                Releasing this report will change its status from Draft
                to Released and record the release timestamp.
            </p>

            <p>
                Review the report before proceeding.
            </p>

            <form method="POST">
                <input
                    type="hidden"
                    name="csrf"
                    value="<?= h($csrf) ?>"
                >

                <button type="submit" class="ci-button">
                    Confirm Release
                </button>
            </form>

            <p>
                <a href="report-editor.php?patient_id=<?= (int) $report['patient_id'] ?>&edit=<?= (int) $report['report_id'] ?>">
                    Return to Editing
                </a>
            </p>
        <?php endif; ?>
    </main>
</body>
</html>
