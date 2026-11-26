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

$patient_id = filter_input(INPUT_GET, 'patient_id', FILTER_VALIDATE_INT);
$editing_report_id = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);

if (!$patient_id || $patient_id < 1) {
    die('Invalid patient ID.');
}

function h($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$reportMessage = '';
$reportError = '';
$editingReport = null;

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf'];

try {

    // Verify that this user is an oncologist.
    $stmt = $pdo->prepare("
        SELECT professional_id, first_name, last_name
        FROM professionals
        WHERE user_id = ?
          AND professional_type = 'oncologist'
    ");
    $stmt->execute([$user_id]);
    $professional = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$professional) {
        die('Oncologist access required.');
    }

    // Verify that the patient is actively assigned to this oncologist.
    $stmt = $pdo->prepare("
        SELECT p.patient_id, p.first_name, p.last_name
        FROM patients p
        INNER JOIN oncologist_patients op
            ON op.patient_id = p.patient_id
        WHERE p.patient_id = ?
          AND op.user_id = ?
          AND op.assignment_status = 'active'
    ");
    $stmt->execute([$patient_id, $user_id]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$patient) {
        die('Patient not found or access denied.');
    }

    // Load an existing draft if the user is editing one.
    if ($editing_report_id) {

        $stmt = $pdo->prepare("
            SELECT
                report_id,
                assessment_id,
                prediction_id,
                report_title,
                report_summary,
                report_status
            FROM oncologist_reports
            WHERE report_id = ?
              AND patient_id = ?
              AND user_id = ?
              AND report_status = 'draft'
        ");

        $stmt->execute([
            $editing_report_id,
            $patient_id,
            $user_id
        ]);

        $editingReport = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$editingReport) {
            die('Draft not found or access denied.');
        }
    }

    // Retrieve clinical assessments.
    $stmt = $pdo->prepare("
        SELECT
            ca.assessment_id,
            ca.assessment_reasoning,
            ca.supporting_findings,
            ca.created_at,
            ct.cancer_name,
            ct.cancer_code
        FROM clinician_assessments ca
        INNER JOIN cancer_types ct
            ON ct.cancer_type_id = ca.cancer_type_id
        WHERE ca.patient_id = ?
        ORDER BY ca.created_at DESC
    ");
    $stmt->execute([$patient_id]);
    $assessments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Retrieve AI prediction results.
    $stmt = $pdo->prepare("
        SELECT
            p.prediction_id,
            p.model_used,
            p.created_at,
            ct.cancer_name,
            ct.cancer_code,
            pr.prediction_probability
        FROM predictions p
        INNER JOIN prediction_results pr
            ON pr.prediction_id = p.prediction_id
        INNER JOIN cancer_types ct
            ON ct.cancer_type_id = pr.cancer_type_id
        WHERE p.patient_id = ?
        ORDER BY p.created_at DESC,
                 pr.prediction_probability DESC
    ");
    $stmt->execute([$patient_id]);
    $predictions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Handle report draft creation and editing.
    if (
        $_SERVER['REQUEST_METHOD'] === 'POST'
        && isset($_POST['save_draft'])
    ) {

        // Validate CSRF token.
        if (
            !isset($_POST['csrf'])
            || !hash_equals($_SESSION['csrf'], $_POST['csrf'])
        ) {
            $reportError = 'Invalid request. Please reload the page.';
        }

        $report_title = trim($_POST['report_title'] ?? '');
        $report_content = trim($_POST['report_content'] ?? '');

        $posted_report_id = filter_input(
            INPUT_POST,
            'report_id',
            FILTER_VALIDATE_INT
        );

        $selected_assessment = filter_input(
            INPUT_POST,
            'assessment_id',
            FILTER_VALIDATE_INT
        );

        $selected_prediction = filter_input(
            INPUT_POST,
            'prediction_id',
            FILTER_VALIDATE_INT
        );

        if (!$reportError && $report_title === '') {
            $reportError = 'Please enter a report title.';
        }

        if (!$reportError && $report_content === '') {
            $reportError = 'Please enter report content.';
        }

        // Verify selected assessment belongs to this patient.
        if (!$reportError && $selected_assessment) {

            $check = $pdo->prepare("
                SELECT assessment_id
                FROM clinician_assessments
                WHERE assessment_id = ?
                  AND patient_id = ?
            ");

            $check->execute([
                $selected_assessment,
                $patient_id
            ]);

            if (!$check->fetchColumn()) {
                $reportError = 'Invalid clinical assessment selected.';
            }
        }

        // Verify selected prediction belongs to this patient.
        if (!$reportError && $selected_prediction) {

            $check = $pdo->prepare("
                SELECT prediction_id
                FROM predictions
                WHERE prediction_id = ?
                  AND patient_id = ?
            ");

            $check->execute([
                $selected_prediction,
                $patient_id
            ]);

            if (!$check->fetchColumn()) {
                $reportError = 'Invalid AI prediction selected.';
            }
        }

        if (!$reportError) {

            // Editing an existing draft.
            if ($posted_report_id) {

                $stmt = $pdo->prepare("
                    UPDATE oncologist_reports
                    SET
                        assessment_id = ?,
                        prediction_id = ?,
                        report_title = ?,
                        report_summary = ?
                    WHERE report_id = ?
                      AND patient_id = ?
                      AND user_id = ?
                      AND report_status = 'draft'
                ");

                $stmt->execute([
                    $selected_assessment ?: null,
                    $selected_prediction ?: null,
                    $report_title,
                    $report_content,
                    $posted_report_id,
                    $patient_id,
                    $user_id
                ]);

                // Verify that the draft still exists and is editable.
                $check = $pdo->prepare("
                    SELECT report_id
                    FROM oncologist_reports
                    WHERE report_id = ?
                      AND patient_id = ?
                      AND user_id = ?
                      AND report_status = 'draft'
                ");

                $check->execute([
                    $posted_report_id,
                    $patient_id,
                    $user_id
                ]);

                if (!$check->fetchColumn()) {
                    $reportError = 'Draft not found or no longer editable.';
                }

            } else {

                // Create a new draft.
                $stmt = $pdo->prepare("
                    INSERT INTO oncologist_reports (
                        user_id,
                        patient_id,
                        assessment_id,
                        prediction_id,
                        report_title,
                        report_summary,
                        report_status
                    )
                    VALUES (?, ?, ?, ?, ?, ?, 'draft')
                ");

                $stmt->execute([
                    $user_id,
                    $patient_id,
                    $selected_assessment ?: null,
                    $selected_prediction ?: null,
                    $report_title,
                    $report_content
                ]);
            }

            if (!$reportError) {
                header(
                    'Location: report-editor.php?patient_id='
                    . $patient_id . '&saved=1'
                );
                exit;
            }
        }

        // Keep the entered values if validation fails.
        $editingReport = [
            'report_id' => $posted_report_id ?: null,
            'assessment_id' => $selected_assessment ?: null,
            'prediction_id' => $selected_prediction ?: null,
            'report_title' => $report_title,
            'report_summary' => $report_content,
            'report_status' => 'draft'
        ];
    }

    // Save confirmation.
    if (isset($_GET['saved']) && $_GET['saved'] === '1') {
        $reportMessage = 'Report draft saved successfully.';
    }

    // Retrieve existing reports.
    $stmt = $pdo->prepare("
        SELECT
            report_id,
            report_title,
            report_summary,
            report_status,
            created_at,
            released_at
        FROM oncologist_reports
        WHERE patient_id = ?
          AND user_id = ?
        ORDER BY created_at DESC
    ");

    $stmt->execute([$patient_id, $user_id]);
    $existingReports = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {

    error_log('Report editor error: ' . $e->getMessage());
    die('Unable to load the report editor.');
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Report Editor | CancerInsight</title>

    <link rel="stylesheet" href="../style/app.css">
</head>

<body>

<main class="page-container">

    <h1>Clinical Report Editor</h1>

    <p>
        <strong>Patient:</strong>
        <?= h($patient['first_name'] . ' ' . $patient['last_name']) ?>
    </p>

    <?php if ($reportMessage): ?>
        <p class="success-message">
            <?= h($reportMessage) ?>
        </p>
    <?php endif; ?>

    <?php if ($reportError): ?>
        <p class="error-message">
            <?= h($reportError) ?>
        </p>
    <?php endif; ?>

    <!-- Clinical assessments -->
    <section class="details-section">

        <h2>Clinical Assessments</h2>

        <?php if (empty($assessments)): ?>

            <p>No clinical assessments recorded.</p>

        <?php else: ?>

            <?php foreach ($assessments as $assessment): ?>

                <article class="record-card">

                    <h3>
                        <?= h($assessment['cancer_name']) ?>
                        (<?= h($assessment['cancer_code']) ?>)
                    </h3>

                    <p>
                        <strong>Assessment:</strong>
                    </p>

                    <p>
                        <?= nl2br(h($assessment['assessment_reasoning'])) ?>
                    </p>

                    <p>
                        <strong>Supporting findings:</strong>
                    </p>

                    <p>
                        <?= $assessment['supporting_findings']
                            ? nl2br(h($assessment['supporting_findings']))
                            : 'None recorded.' ?>
                    </p>

                    <p>
                        <strong>Recorded:</strong>
                        <?= h($assessment['created_at']) ?>
                    </p>

                </article>

            <?php endforeach; ?>

        <?php endif; ?>

    </section>

    <!-- AI predictions -->
    <section class="details-section">

        <h2>Available AI Predictions</h2>

        <?php if (empty($predictions)): ?>

            <p>No AI predictions are currently available.</p>

        <?php else: ?>

            <?php foreach ($predictions as $prediction): ?>

                <article class="record-card">

                    <p>
                        <strong>
                            <?= h($prediction['cancer_name']) ?>
                            (<?= h($prediction['cancer_code']) ?>)
                        </strong>
                    </p>

                    <p>
                        Probability:
                        <?= number_format(
                            (float) $prediction['prediction_probability'] * 100,
                            2
                        ) ?>%
                    </p>

                    <p>
                        <strong>Model:</strong>
                        <?= h($prediction['model_used']) ?>
                    </p>

                    <p>
                        <strong>Generated:</strong>
                        <?= h($prediction['created_at']) ?>
                    </p>

                </article>

            <?php endforeach; ?>

        <?php endif; ?>

    </section>

    <!-- Report form -->
    <section class="details-section">

        <h2>
            <?= $editingReport
                ? 'Edit Report Draft'
                : 'Create Report Draft' ?>
        </h2>

        <form
            method="POST"
            action="report-editor.php?patient_id=<?= (int) $patient_id ?><?= $editingReport && !empty($editingReport['report_id']) ? '&edit=' . (int) $editingReport['report_id'] : '' ?>"
        >

            <input
                type="hidden"
                name="csrf"
                value="<?= h($csrf) ?>"
            >

            <input
                type="hidden"
                name="report_id"
                value="<?= (int) ($editingReport['report_id'] ?? 0) ?>"
            >

            <!-- Report title -->
            <div>

                <label for="report_title">
                    Report title
                </label>

                <input
                    type="text"
                    name="report_title"
                    id="report_title"
                    required
                    value="<?= h($editingReport['report_title'] ?? '') ?>"
                    placeholder="e.g. Clinical Assessment Report"
                >

            </div>

            <!-- Link assessment -->
            <div>

                <label for="assessment_id">
                    Link clinical assessment
                </label>

                <select name="assessment_id" id="assessment_id">

                    <option value="">
                        No assessment selected
                    </option>

                    <?php foreach ($assessments as $assessment): ?>

                        <option
                            value="<?= (int) $assessment['assessment_id'] ?>"
                            <?= (
                                isset($editingReport['assessment_id'])
                                && $editingReport['assessment_id']
                                    == $assessment['assessment_id']
                            ) ? 'selected' : '' ?>
                        >
                            <?= h($assessment['cancer_name']) ?>
                            (<?= h($assessment['created_at']) ?>)
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

            <!-- Link prediction -->
            <div>

                <label for="prediction_id">
                    Link AI prediction (optional)
                </label>

                <select name="prediction_id" id="prediction_id">

                    <option value="">
                        No AI prediction selected
                    </option>

                    <?php
                    $predictionIds = [];

                    foreach ($predictions as $prediction):

                        if (!in_array(
                            $prediction['prediction_id'],
                            $predictionIds
                        )):

                            $predictionIds[] =
                                $prediction['prediction_id'];
                    ?>

                        <option
                            value="<?= (int) $prediction['prediction_id'] ?>"
                            <?= (
                                isset($editingReport['prediction_id'])
                                && $editingReport['prediction_id']
                                    == $prediction['prediction_id']
                            ) ? 'selected' : '' ?>
                        >
                            Prediction #<?= (int) $prediction['prediction_id'] ?>
                            (<?= h($prediction['model_used']) ?>)
                        </option>

                    <?php
                        endif;
                    endforeach;
                    ?>

                </select>

            </div>

            <!-- Report content -->
            <div>

                <label for="report_content">
                    Report content
                </label>

                <textarea
                    name="report_content"
                    id="report_content"
                    rows="14"
                    required
                    placeholder="Write the clinical report here..."
                ><?= h($editingReport['report_summary'] ?? '') ?></textarea>

            </div>

            <button
                type="submit"
                name="save_draft"
                value="1"
                class="ci-button"
            >
                Save Draft
            </button>

            <?php if ($editingReport): ?>

                <p>
                    <a href="report-editor.php?patient_id=<?= (int) $patient_id ?>">
                        Cancel editing
                    </a>
                </p>

            <?php endif; ?>

        </form>

    </section>

    <!-- Existing reports -->
    <section class="details-section">

        <h2>Existing Reports</h2>

        <?php if (empty($existingReports)): ?>

            <p>No reports have been created yet.</p>

        <?php else: ?>

            <?php foreach ($existingReports as $report): ?>

                <article class="record-card">

                    <h3><?= h($report['report_title']) ?></h3>

                    <p>
                        <strong>Status:</strong>
                        <?= h(ucfirst($report['report_status'])) ?>
                    </p>

                    <p>
                        <strong>Created:</strong>
                        <?= h($report['created_at']) ?>
                    </p>

                    <?php if ($report['released_at']): ?>

                        <p>
                            <strong>Released:</strong>
                            <?= h($report['released_at']) ?>
                        </p>

                    <?php endif; ?>

                    <p>
                        <?= nl2br(h($report['report_summary'])) ?>
                    </p>

                    <?php if ($report['report_status'] === 'draft'): ?>

    <p>
        <a href="report-editor.php?patient_id=<?= (int) $patient_id ?>&edit=<?= (int) $report['report_id'] ?>">
            Edit Draft
        </a>
    </p>

    <p>
        <a href="release-report.php?report_id=<?= (int) $report['report_id'] ?>">
            Release Report
        </a>
    </p>

<?php elseif ($report['report_status'] === 'released'): ?>

    <p>
        <strong>Report has been released.</strong>
    </p>

<?php endif; ?>

                </article>

            <?php endforeach; ?>

        <?php endif; ?>

    </section>

    <p>
        <a href="patient-details.php?id=<?= (int) $patient_id ?>">
            Back to Patient Details
        </a>
    </p>

</main>

</body>
</html>
