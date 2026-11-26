
<?php
session_start();

require_once __DIR__ . '/../../includes/db.php';

// Only logged-in oncologists can access this page.
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'oncologist'
) {
    header('Location: ../login.php');
    exit();
}

$user_id = (int) $_SESSION['user_id'];

// Validate patient ID.
$patient_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$patient_id || $patient_id < 1) {
    http_response_code(400);
    exit('Invalid patient ID.');
}

// Escape output.
function h($value) {
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

// Handle independent clinical assessment submission.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_assessment'])) {

    $cancer_type_id = filter_input(
        INPUT_POST,
        'cancer_type_id',
        FILTER_VALIDATE_INT
    );

    $assessment_reasoning = trim($_POST['assessment_reasoning'] ?? '');
    $supporting_findings = trim($_POST['supporting_findings'] ?? '');

    if (!$cancer_type_id || $cancer_type_id < 1) {
        $assessmentError = 'Please select a cancer type.';
    } elseif ($assessment_reasoning === '') {
        $assessmentError = 'Please enter your clinical reasoning.';
    } else {
        try {
            // Verify that the selected cancer type exists.
            $check = $pdo->prepare("
                SELECT cancer_type_id
                FROM cancer_types
                WHERE cancer_type_id = ?
            ");
            $check->execute([$cancer_type_id]);

            if (!$check->fetchColumn()) {
                $assessmentError = 'The selected cancer type is invalid.';
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO clinician_assessments (
                        patient_id,
                        user_id,
                        cancer_type_id,
                        assessment_reasoning,
                        supporting_findings
                    ) VALUES (?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                    $patient_id,
                    $user_id,
                    $cancer_type_id,
                    $assessment_reasoning,
                    $supporting_findings !== '' ? $supporting_findings : null
                ]);

                header(
                    'Location: patient-details.php?id=' . $patient_id . '&assessment=saved'
                );
                exit;
            }
        } catch (PDOException $e) {
            error_log('Clinical assessment save error: ' . $e->getMessage());
            $assessmentError = 'Unable to save the assessment. Please try again.';
        }
    }
}

if (isset($_GET['assessment']) && $_GET['assessment'] === 'saved') {
    $assessmentMessage = 'Clinical assessment saved successfully.';
}

try {
    // Confirm that this patient is assigned to this oncologist.
    $stmt = $pdo->prepare("
        SELECT p.*
        FROM patients p
        INNER JOIN oncologist_patients op
            ON op.patient_id = p.patient_id
        WHERE p.patient_id = ?
          AND op.user_id = ?
          AND op.assignment_status = 'active'
        LIMIT 1
    ");

    $stmt->execute([$patient_id, $user_id]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$patient) {
        http_response_code(404);
        exit('Patient not found or you do not have permission to access this patient.');
    }

    // Calculate age.
    $birthdate = new DateTime($patient['birthdate']);
    $today = new DateTime();
    $age = $birthdate->diff($today)->y;

    // Retrieve available cancer types for clinical assessments.
    $stmt = $pdo->query("
        SELECT cancer_type_id, cancer_name, cancer_code
        FROM cancer_types
        ORDER BY cancer_name
    ");

    $cancerTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Retrieve diagnoses.
    $stmt = $pdo->prepare("
        SELECT
            d.*,
            ct.cancer_name,
            ct.cancer_code
        FROM patient_diagnoses d
        INNER JOIN cancer_types ct
            ON ct.cancer_type_id = d.cancer_type_id
        WHERE d.patient_id = ?
        ORDER BY d.date_of_diagnosis DESC
    ");

    $stmt->execute([$patient_id]);
    $diagnoses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Retrieve treatments.
    $stmt = $pdo->prepare("
        SELECT t.*, d.cancer_type_id
        FROM patient_treatments t
        INNER JOIN patient_diagnoses d
            ON d.diagnosis_id = t.diagnosis_id
        WHERE d.patient_id = ?
        ORDER BY t.start_date DESC
    ");

    $stmt->execute([$patient_id]);
    $treatments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group treatments by diagnosis.
    $treatmentsByDiagnosis = [];

    foreach ($treatments as $treatment) {
        $treatmentsByDiagnosis[$treatment['cancer_type_id']][] = $treatment;
    }

    // Retrieve genetic samples.
    $stmt = $pdo->prepare("
        SELECT *
        FROM samples
        WHERE patient_id = ?
        ORDER BY date_detected DESC
    ");

    $stmt->execute([$patient_id]);
    $samples = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Retrieve mutations and associated genes for each sample.
    $stmt = $pdo->prepare("
        SELECT
            pm.sample_id,
            pm.allele_frequency,
            m.mutation_id,
            m.genomic_position,
            m.original_allele,
            m.altered_allele,
            m.mutation_type,
            m.clinical_significance,
            m.genome_assembly,
            g.gene_symbol,
            g.chromosome
        FROM patient_mutations pm
        INNER JOIN mutations m
            ON m.mutation_id = pm.mutation_id
        INNER JOIN genes g
            ON g.gene_id = m.gene_id
        INNER JOIN samples s
            ON s.sample_id = pm.sample_id
        WHERE s.patient_id = ?
        ORDER BY s.sample_id, g.gene_symbol
    ");

    $stmt->execute([$patient_id]);
    $mutationRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $mutationsBySample = [];

    foreach ($mutationRows as $mutation) {
        $mutationsBySample[$mutation['sample_id']][] = $mutation;
    }

    // Retrieve saved clinical assessments for this patient.
    $stmt = $pdo->prepare("
        SELECT
            ca.assessment_id,
            ca.assessment_reasoning,
            ca.supporting_findings,
            ca.created_at,
            ct.cancer_name,
            ct.cancer_code,
            prof.first_name,
            prof.last_name
        FROM clinician_assessments ca
        INNER JOIN cancer_types ct
            ON ct.cancer_type_id = ca.cancer_type_id
        INNER JOIN professionals prof
            ON prof.user_id = ca.user_id
        WHERE ca.patient_id = ?
        ORDER BY ca.created_at DESC
    ");

$stmt->execute([$patient_id]);
$clinicalAssessments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Retrieve AI predictions for this patient.
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
        ORDER BY p.created_at DESC, pr.prediction_probability DESC
    ");

    $stmt->execute([$patient_id]);
    $aiPredictions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Retrieve the latest consent record for each consent type.
    $stmt = $pdo->prepare("
        SELECT pc.*
        FROM patient_consents pc
        INNER JOIN (
            SELECT consent_type, MAX(consent_id) AS latest_id
            FROM patient_consents
            WHERE patient_id = ?
            GROUP BY consent_type
        ) latest
            ON pc.consent_id = latest.latest_id
        ORDER BY pc.consent_type
    ");

    $stmt->execute([$patient_id]);
    $consentRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $consents = [
        'ai_processing' => null,
        'research' => null
    ];

    foreach ($consentRows as $consent) {
        $consents[$consent['consent_type']] = $consent;
    }

} catch (PDOException $e) {
    error_log('Patient details error: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to load patient details. Please try again later.');
}

function formatDate($date) {
    if (!$date) {
        return 'Not recorded';
    }

    return date('d M Y', strtotime($date));
}

function consentLabel($consent) {
    return $consent
        ? ucfirst($consent['consent_status'])
        : 'Not recorded';
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Patient Details | CancerInsight</title>

    <link rel="stylesheet" href="../style/app.css">
    <link rel="stylesheet" href="../style/oncologist/dashboard.css">
</head>

<body>

<main class="patient-details-page">

    <a href="patients.php" class="back-link">
        &larr; Back to Patient Directory
    </a>

    <header class="patient-header">
        <div>
            <p class="eyebrow">PATIENT RECORD</p>

            <h1>
                <?= h($patient['first_name'] . ' ' . $patient['last_name']) ?>
            </h1>

            <p>
                Patient ID: <?= h($patient['patient_id']) ?>
            </p>
        </div>
    </header>

    <section class="details-section">
        <h2>Personal Information</h2>

        <div class="details-grid">
            <div>
                <span>Full Name</span>
                <strong><?= h($patient['first_name'] . ' ' . $patient['last_name']) ?></strong>
            </div>

            <div>
                <span>Age</span>
                <strong><?= h($age) ?> years</strong>
            </div>

            <div>
                <span>Date of Birth</span>
                <strong><?= h(formatDate($patient['birthdate'])) ?></strong>
            </div>

            <div>
                <span>Sex</span>
                <strong><?= h(ucfirst($patient['sex'])) ?></strong>
            </div>

            <div>
                <span>Country</span>
                <strong><?= h($patient['country']) ?></strong>
            </div>

            <div>
                <span>Patient ID</span>
                <strong><?= h($patient['patient_id']) ?></strong>
            </div>
        </div>
    </section>

    <section class="details-section">
        <h2>Diagnoses</h2>

        <?php if (empty($diagnoses)): ?>
            <p>No diagnoses recorded.</p>
        <?php else: ?>

            <?php foreach ($diagnoses as $diagnosis): ?>

                <article class="record-card">

                    <h3><?= h($diagnosis['cancer_name']) ?></h3>

                    <p>
                        TCGA code:
                        <strong><?= h($diagnosis['cancer_code']) ?></strong>
                    </p>

                    <div class="details-grid">
                        <div>
                            <span>Date of Diagnosis</span>
                            <strong><?= h(formatDate($diagnosis['date_of_diagnosis'])) ?></strong>
                        </div>

                        <div>
                            <span>Stage</span>
                            <strong><?= h($diagnosis['stage']) ?></strong>
                        </div>

                        <div>
                            <span>Status</span>
                            <strong><?= h(ucfirst($diagnosis['status'])) ?></strong>
                        </div>
                    </div>

                    <h4>Treatment History</h4>

                    <?php
                    $diagnosisTreatments = array_filter(
                        $treatments,
                        function ($treatment) use ($diagnosis) {
                            return $treatment['diagnosis_id'] == $diagnosis['diagnosis_id'];
                        }
                    );
                    ?>

                    <?php if (empty($diagnosisTreatments)): ?>
                        <p>No treatments recorded.</p>
                    <?php else: ?>

                        <?php foreach ($diagnosisTreatments as $treatment): ?>
                            <div class="record-item">
                                <strong><?= h($treatment['treatment_type']) ?></strong>

                                <p><?= h($treatment['treatment_description'] ?: 'No description recorded.') ?></p>

                                <p>
                                    <?= h(formatDate($treatment['start_date'])) ?>
                                    –
                                    <?= h(formatDate($treatment['end_date'])) ?>
                                </p>

                                <span><?= h(ucfirst($treatment['status'])) ?></span>
                            </div>
                        <?php endforeach; ?>

                    <?php endif; ?>

                </article>

            <?php endforeach; ?>

        <?php endif; ?>
    </section>

    <section class="details-section">
        <h2>Genetic Samples and Mutations</h2>

        <?php if (empty($samples)): ?>
            <p>No genetic samples recorded.</p>
        <?php else: ?>

            <?php foreach ($samples as $sample): ?>

                <article class="record-card">

                    <h3>Sample: <?= h($sample['sample_code']) ?></h3>

                    <p>
                        Date detected:
                        <?= h(formatDate($sample['date_detected'])) ?>
                    </p>

                    <?php
                    $sampleMutations = $mutationsBySample[$sample['sample_id']] ?? [];
                    ?>

                    <?php if (empty($sampleMutations)): ?>
                        <p>No mutations recorded for this sample.</p>
                    <?php else: ?>

                        <div class="table-wrapper">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Gene</th>
                                        <th>Chromosome</th>
                                        <th>Position</th>
                                        <th>Mutation</th>
                                        <th>Type</th>
                                        <th>Clinical Significance</th>
                                        <th>Allele Frequency</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php foreach ($sampleMutations as $mutation): ?>
                                        <tr>
                                            <td><?= h($mutation['gene_symbol']) ?></td>
                                            <td><?= h($mutation['chromosome']) ?></td>
                                            <td><?= h($mutation['genomic_position']) ?></td>
                                            <td>
                                                <?= h($mutation['original_allele'] ?? '-') ?>
                                                &rarr;
                                                <?= h($mutation['altered_allele'] ?? '-') ?>
                                            </td>
                                            <td><?= h($mutation['mutation_type']) ?></td>
                                            <td><?= h(ucwords(str_replace('_', ' ', $mutation['clinical_significance']))) ?></td>
                                            <td>
                                                <?= $mutation['allele_frequency'] !== null
                                                    ? h(number_format((float) $mutation['allele_frequency'] * 100, 2)) . '%'
                                                    : 'Not recorded' ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                    <?php endif; ?>

                </article>

            <?php endforeach; ?>

        <?php endif; ?>
    </section>

    <section class="details-section">
        <h2>Patient Consent</h2>

        <div class="details-grid">

            <div>
                <span>AI Processing Consent</span>
                <strong><?= h(consentLabel($consents['ai_processing'])) ?></strong>

                <?php if ($consents['ai_processing']): ?>
                    <p>
                        Recorded: <?= h(formatDate($consents['ai_processing']['recorded_at'])) ?>
                    </p>
                <?php endif; ?>
            </div>

            <div>
                <span>Research Consent</span>
                <strong><?= h(consentLabel($consents['research'])) ?></strong>

                <?php if ($consents['research']): ?>
                    <p>
                        Recorded: <?= h(formatDate($consents['research']['recorded_at'])) ?>
                    </p>
                <?php endif; ?>
            </div>

        </div>
    </section>

   <section class="details-section">

    <h2>Clinical Assessment</h2>

    <p>
        Record your independent clinical assessment before reviewing
        any AI-generated predictions.
    </p>

    <?php if ($assessmentMessage): ?>
        <p class="success-message">
            <?= h($assessmentMessage) ?>
        </p>
    <?php endif; ?>

    <?php if ($assessmentError): ?>
        <p class="error-message">
            <?= h($assessmentError) ?>
        </p>
    <?php endif; ?>

    <form method="POST" action="patient-details.php?id=<?= (int) $patient_id ?>">

        <div>
            <label for="cancer_type_id">Suspected cancer type</label>

            <select
                name="cancer_type_id"
                id="cancer_type_id"
                required
            >
                <option value="">Select cancer type</option>

                <?php foreach ($cancerTypes as $type): ?>
                    <option value="<?= (int) $type['cancer_type_id'] ?>">
                        <?= h($type['cancer_name']) ?>
                        (<?= h($type['cancer_code']) ?>)
                    </option>
                <?php endforeach; ?>

            </select>
        </div>

        <div>
            <label for="assessment_reasoning">
                Clinical reasoning
            </label>

            <textarea
                name="assessment_reasoning"
                id="assessment_reasoning"
                rows="5"
                required
                placeholder="Explain your independent clinical assessment..."
            ></textarea>
        </div>

        <div>
            <label for="supporting_findings">
                Supporting findings
            </label>

            <textarea
                name="supporting_findings"
                id="supporting_findings"
                rows="4"
                placeholder="Record relevant symptoms, test results, mutation findings or other evidence..."
            ></textarea>
        </div>

        <button
            type="submit"
            name="submit_assessment"
            value="1"
            class="ci-button"
        >
            Save Clinical Assessment
        </button>

    </form>

</section>

<section class="details-section">

    <h2>AI Prediction Results</h2>

    <p>
        AI-generated predictions will be displayed here when available.
        Predictions are only generated when the patient has consented
        to AI processing.
    </p>

    <?php if (empty($aiPredictions)): ?>

        <p>No AI predictions have been generated for this patient yet.</p>

    <?php else: ?>

        <?php foreach ($aiPredictions as $prediction): ?>

            <article class="record-card">

                <h3>
                    <?= h($prediction['cancer_name']) ?>
                    (<?= h($prediction['cancer_code']) ?>)
                </h3>

                <p>
                    <strong>Probability:</strong>
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
                    <?= h(formatDate($prediction['created_at'])) ?>
                </p>

            </article>

        <?php endforeach; ?>

    <?php endif; ?>

</section>

</main>

</body>
</html>
