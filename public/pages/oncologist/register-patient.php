
<?php
session_start();

require_once __DIR__ . '/../../includes/db.php';

// Only authenticated oncologists can register patients
if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    $_SESSION['role'] !== 'oncologist'
) {
    header('Location: ../login.php');
    exit;
}

$user_id = (int) $_SESSION['user_id'];

function h($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Verify oncologist profile
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

// Load cancer types for the diagnosis dropdown
$stmt = $pdo->query("
    SELECT cancer_type_id, cancer_name
    FROM cancer_types
    ORDER BY cancer_name
");
$cancerTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Collect account details
    $email = strtolower(trim($_POST['email'] ?? ''));
    $username = trim($_POST['username'] ?? '');

    // Personal details
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $birthdate = $_POST['birthdate'] ?? '';
    $sex = $_POST['sex'] ?? 'unknown';
    $country = trim($_POST['country'] ?? '');

    // Diagnosis
    $cancerTypeId = $_POST['cancer_type_id'] ?? '';
    $diagnosisDate = $_POST['date_of_diagnosis'] ?? '';
    $stage = $_POST['stage'] ?? 'unknown';
    $diagnosisStatus = $_POST['diagnosis_status'] ?? 'active';

    // Treatment
    $treatmentType = trim($_POST['treatment_type'] ?? '');
    $treatmentDescription = trim($_POST['treatment_description'] ?? '');
    $treatmentStart = $_POST['treatment_start'] ?: null;
    $treatmentEnd = $_POST['treatment_end'] ?: null;
    $treatmentStatus = $_POST['treatment_status'] ?? 'ongoing';

    // Consent
    $aiConsent = $_POST['ai_consent'] ?? 'denied';
    $researchConsent = $_POST['research_consent'] ?? 'denied';

    // Basic validation
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    }

    if ($username === '' || strlen($username) > 50) {
        $errors[] = 'Enter a username of up to 50 characters.';
    }

    if ($firstName === '' || $lastName === '' || $country === '') {
        $errors[] = 'Complete all required personal information fields.';
    }

    if (!$birthdate || !strtotime($birthdate)) {
        $errors[] = 'Enter a valid date of birth.';
    }

    if (!in_array($sex, ['female', 'male', 'other', 'unknown'], true)) {
        $errors[] = 'Invalid sex selection.';
    }

    if (!in_array($stage, ['0', 'I', 'II', 'III', 'IV', 'unknown'], true)) {
        $errors[] = 'Invalid cancer stage.';
    }

    if (!in_array($diagnosisStatus, ['active', 'remission'], true)) {
        $errors[] = 'Invalid diagnosis status.';
    }

    if (!in_array($aiConsent, ['granted', 'denied'], true)) {
        $errors[] = 'Invalid AI consent selection.';
    }

    if (!in_array($researchConsent, ['granted', 'denied'], true)) {
        $errors[] = 'Invalid research consent selection.';
    }

    if (!in_array($treatmentStatus, ['ongoing', 'completed', 'discontinued'], true)) {
        $errors[] = 'Invalid treatment status.';
    }

    if (!ctype_digit((string) $cancerTypeId) || (int) $cancerTypeId < 1) {
        $errors[] = 'Select a valid cancer type.';
    }

    if (!$diagnosisDate || !strtotime($diagnosisDate)) {
        $errors[] = 'Enter a valid diagnosis date.';
    }

    if ($treatmentType === '') {
        $treatmentType = null;
    }

    if ($treatmentType === null && (
        $treatmentDescription !== '' ||
        $treatmentStart ||
        $treatmentEnd
    )) {
        $errors[] = 'Enter a treatment type if providing treatment details.';
    }

    if (
        $treatmentStart &&
        $treatmentEnd &&
        $treatmentEnd < $treatmentStart
    ) {
        $errors[] = 'Treatment end date cannot be before its start date.';
    }

    // Confirm cancer type exists
    if (!$errors) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM cancer_types
            WHERE cancer_type_id = ?
        ");
        $stmt->execute([(int) $cancerTypeId]);

        if (!$stmt->fetchColumn()) {
            $errors[] = 'The selected cancer type does not exist.';
        }
    }

    // Check duplicate email and username
    if (!$errors) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM users
            WHERE email = ? OR username = ?
        ");
        $stmt->execute([$email, $username]);

        if ($stmt->fetchColumn() > 0) {
            $errors[] = 'That email address or username is already registered.';
        }
    }

    // Create all records together
    if (!$errors) {

        try {
            $pdo->beginTransaction();

            // Generate a random initial credential.
            // Patient password setup will be implemented separately.
            $temporaryPassword = bin2hex(random_bytes(32));
            $passwordHash = password_hash(
                $temporaryPassword,
                PASSWORD_DEFAULT
            );

            // Create login account
            $stmt = $pdo->prepare("
                INSERT INTO users (
                    username,
                    email,
                    password_hash,
                    role
                )
                VALUES (?, ?, ?, 'patient')
            ");

            $stmt->execute([
                $username,
                $email,
                $passwordHash
            ]);

            $patientUserId = (int) $pdo->lastInsertId();

            // Create patient profile
            $stmt = $pdo->prepare("
                INSERT INTO patients (
                    user_id,
                    first_name,
                    last_name,
                    birthdate,
                    sex,
                    country
                )
                VALUES (?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $patientUserId,
                $firstName,
                $lastName,
                $birthdate,
                $sex,
                $country
            ]);

            $patientId = (int) $pdo->lastInsertId();

            // Assign patient to registering oncologist
            $stmt = $pdo->prepare("
                INSERT INTO oncologist_patients (
                    user_id,
                    patient_id
                )
                VALUES (?, ?)
            ");

            $stmt->execute([
                $user_id,
                $patientId
            ]);

            // Save diagnosis
            $stmt = $pdo->prepare("
                INSERT INTO patient_diagnoses (
                    patient_id,
                    cancer_type_id,
                    date_of_diagnosis,
                    stage,
                    status
                )
                VALUES (?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $patientId,
                (int) $cancerTypeId,
                $diagnosisDate,
                $stage,
                $diagnosisStatus
            ]);

            $diagnosisId = (int) $pdo->lastInsertId();

            // Save treatment only when supplied
            if ($treatmentType !== null) {

                $stmt = $pdo->prepare("
                    INSERT INTO patient_treatments (
                        diagnosis_id,
                        treatment_type,
                        treatment_description,
                        start_date,
                        end_date,
                        status
                    )
                    VALUES (?, ?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                    $diagnosisId,
                    $treatmentType,
                    $treatmentDescription ?: null,
                    $treatmentStart,
                    $treatmentEnd,
                    $treatmentStatus
                ]);
            }

            // Record AI consent
            $stmt = $pdo->prepare("
                INSERT INTO patient_consents (
                    patient_id,
                    consent_type,
                    consent_status,
                    recorded_by
                )
                VALUES (?, 'ai_processing', ?, ?)
            ");

            $stmt->execute([
                $patientId,
                $aiConsent,
                $user_id
            ]);

            // Record research consent independently
            $stmt = $pdo->prepare("
                INSERT INTO patient_consents (
                    patient_id,
                    consent_type,
                    consent_status,
                    recorded_by
                )
                VALUES (?, 'research', ?, ?)
            ");

            $stmt->execute([
                $patientId,
                $researchConsent,
                $user_id
            ]);

            $pdo->commit();

            $success = true;

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Patient registration failed: ' . $e->getMessage());

            $errors[] = 'Patient registration failed. Please check the details and try again.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>CancerInsight | Register Patient</title>
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
                <strong>Dr. <?= h($oncologist['first_name'] . ' ' . $oncologist['last_name']) ?></strong>
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
            <h2>Register a Patient</h2>
            <p class="welcome-description">
                Create a patient account and add their clinical information.
            </p>
        </div>
    </section>

    <nav class="ci-nav">
        <a href="oncologist-dashboard.php">Dashboard</a>
        <a href="patients.php">Patients</a>
        <a href="register-patient.php">Register Patient</a>
    </nav>

    <?php if ($success): ?>

        <section class="panel">
            <div class="ci-empty">
                <h3>Patient registered successfully</h3>
                <p>
                    The patient account, clinical profile, diagnosis,
                    consent records and oncologist assignment have been saved.
                </p>
                <a href="patients.php" class="ci-button">
                    Return to patient directory
                </a>
            </div>
        </section>

    <?php else: ?>

        <?php if ($errors): ?>
            <section class="panel">
                <div role="alert">
                    <h3>Please correct the following:</h3>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?= h($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </section>
        <?php endif; ?>

        <form method="POST" class="panel ci-form">

            <div class="panel-heading">
                <div>
                    <h3>Account Details</h3>
                    <p>Patient login information</p>
                </div>
            </div>

            <div class="ci-form-row">
                <label for="email">Email address *</label>
                <input type="email" id="email" name="email" required
                       value="<?= h($_POST['email'] ?? '') ?>">
            </div>

            <div class="ci-form-row">
                <label for="username">Username *</label>
                <input type="text" id="username" name="username"
                       maxlength="50" required
                       value="<?= h($_POST['username'] ?? '') ?>">
            </div>

            <div class="panel-heading">
                <div>
                    <h3>Personal Information</h3>
                    <p>Patient identity and demographic details</p>
                </div>
            </div>

            <div class="ci-form-row">
                <label for="first_name">First name *</label>
                <input type="text" id="first_name" name="first_name"
                       maxlength="100" required
                       value="<?= h($_POST['first_name'] ?? '') ?>">
            </div>

            <div class="ci-form-row">
                <label for="last_name">Last name *</label>
                <input type="text" id="last_name" name="last_name"
                       maxlength="100" required
                       value="<?= h($_POST['last_name'] ?? '') ?>">
            </div>

            <div class="ci-form-row">
                <label for="birthdate">Date of birth *</label>
                <input type="date" id="birthdate" name="birthdate" required
                       value="<?= h($_POST['birthdate'] ?? '') ?>">
            </div>

            <div class="ci-form-row">
                <label for="sex">Sex *</label>
                <select id="sex" name="sex" required>
                    <option value="unknown">Unknown</option>
                    <option value="female">Female</option>
                    <option value="male">Male</option>
                    <option value="other">Other</option>
                </select>
            </div>

            <div class="ci-form-row">
                <label for="country">Country *</label>
                <input type="text" id="country" name="country"
                       maxlength="100" required
                       value="<?= h($_POST['country'] ?? '') ?>">
            </div>

            <div class="panel-heading">
                <div>
                    <h3>Cancer Diagnosis</h3>
                    <p>Record the patient's diagnosis</p>
                </div>
            </div>

            <div class="ci-form-row">
                <label for="cancer_type_id">Cancer type *</label>
                <select id="cancer_type_id" name="cancer_type_id" required>
                    <option value="">Select cancer type</option>
                    <?php foreach ($cancerTypes as $type): ?>
                        <option value="<?= h($type['cancer_type_id']) ?>"
                            <?= (($_POST['cancer_type_id'] ?? '') == $type['cancer_type_id']) ? 'selected' : '' ?>>
                            <?= h($type['cancer_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="ci-form-row">
                <label for="date_of_diagnosis">Date of diagnosis *</label>
                <input type="date" id="date_of_diagnosis"
                       name="date_of_diagnosis" required
                       value="<?= h($_POST['date_of_diagnosis'] ?? '') ?>">
            </div>

            <div class="ci-form-row">
                <label for="stage">Cancer stage *</label>
                <select id="stage" name="stage">
                    <option value="unknown">Unknown</option>
                    <option value="0">Stage 0</option>
                    <option value="I">Stage I</option>
                    <option value="II">Stage II</option>
                    <option value="III">Stage III</option>
                    <option value="IV">Stage IV</option>
                </select>
            </div>

            <div class="ci-form-row">
                <label for="diagnosis_status">Diagnosis status *</label>
                <select id="diagnosis_status" name="diagnosis_status">
                    <option value="active">Active</option>
                    <option value="remission">Remission</option>
                </select>
            </div>

            <div class="panel-heading">
                <div>
                    <h3>Treatment Information</h3>
                    <p>Optional treatment details</p>
                </div>
            </div>

            <div class="ci-form-row">
                <label for="treatment_type">Treatment type</label>
                <input type="text" id="treatment_type" name="treatment_type"
                       maxlength="100"
                       value="<?= h($_POST['treatment_type'] ?? '') ?>">
            </div>

            <div class="ci-form-row">
                <label for="treatment_description">Treatment description</label>
                <textarea id="treatment_description"
                          name="treatment_description"><?= h($_POST['treatment_description'] ?? '') ?></textarea>
            </div>

            <div class="ci-form-row">
                <label for="treatment_start">Treatment start date</label>
                <input type="date" id="treatment_start" name="treatment_start"
                       value="<?= h($_POST['treatment_start'] ?? '') ?>">
            </div>

            <div class="ci-form-row">
                <label for="treatment_end">Treatment end date</label>
                <input type="date" id="treatment_end" name="treatment_end"
                       value="<?= h($_POST['treatment_end'] ?? '') ?>">
            </div>

            <div class="ci-form-row">
                <label for="treatment_status">Treatment status</label>
                <select id="treatment_status" name="treatment_status">
                    <option value="ongoing">Ongoing</option>
                    <option value="completed">Completed</option>
                    <option value="discontinued">Discontinued</option>
                </select>
            </div>

            <div class="panel-heading">
                <div>
                    <h3>Patient Consent</h3>
                    <p>Record consent decisions separately</p>
                </div>
            </div>

            <div class="ci-form-row">
                <label for="ai_consent">AI processing consent *</label>
                <select id="ai_consent" name="ai_consent" required>
                    <option value="denied">Not granted</option>
                    <option value="granted">Granted</option>
                </select>
            </div>

            <div class="ci-form-row">
                <label for="research_consent">Research data consent *</label>
                <select id="research_consent" name="research_consent" required>
                    <option value="denied">Not granted</option>
                    <option value="granted">Granted</option>
                </select>
            </div>

            <p class="ci-note">
                Only record consent decisions that have actually been
                communicated and confirmed by the patient. These selections
                must reflect the patient's informed choices, not the
                clinician's assumptions.
            </p>

            <button type="submit" class="ci-button">
                Register Patient
            </button>

        </form>

    <?php endif; ?>

</main>

</body>
</html>
