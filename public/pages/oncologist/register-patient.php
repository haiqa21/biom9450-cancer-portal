<?php
session_start();
require_once __DIR__ . '/../../includes/db.php';

if (!isset($_SESSION['user_id'], $_SESSION['role']) || $_SESSION['role'] !== 'oncologist') {
    header('Location: ../login.php');
    exit;
}

$user_id = (int) $_SESSION['user_id'];

function h($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function validDate(string $date): bool {
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

$stmt = $pdo->prepare("SELECT first_name, last_name, speciality FROM professionals WHERE user_id = ? AND professional_type = 'oncologist' LIMIT 1");
$stmt->execute([$user_id]);
$oncologist = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$oncologist) {
    http_response_code(403);
    exit('Oncologist profile not found.');
}

$stmt = $pdo->query("SELECT cancer_type_id, cancer_name FROM cancer_types ORDER BY cancer_name");
$cancerTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($csrfToken, (string) $_POST['csrf_token'])) {
        $errors[] = 'Your session validation failed. Please refresh the page and try again.';
    }

    $email = strtolower(trim($_POST['email'] ?? ''));
    $username = trim($_POST['username'] ?? '');
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $birthdate = trim($_POST['birthdate'] ?? '');
    $sex = $_POST['sex'] ?? 'unknown';
    $country = trim($_POST['country'] ?? '');
    $cancerTypeId = $_POST['cancer_type_id'] ?? '';
    $diagnosisDate = trim($_POST['date_of_diagnosis'] ?? '');
    $stage = $_POST['stage'] ?? 'unknown';
    $diagnosisStatus = $_POST['diagnosis_status'] ?? 'active';
    $treatmentType = trim($_POST['treatment_type'] ?? '');
    $treatmentDescription = trim($_POST['treatment_description'] ?? '');
    $treatmentStart = trim($_POST['treatment_start'] ?? '') ?: null;
    $treatmentEnd = trim($_POST['treatment_end'] ?? '') ?: null;
    $treatmentStatus = $_POST['treatment_status'] ?? 'ongoing';
    $aiConsent = $_POST['ai_consent'] ?? 'denied';
    $researchConsent = $_POST['research_consent'] ?? 'denied';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    }

    if ($username === '' || strlen($username) > 50) {
        $errors[] = 'Enter a username of up to 50 characters.';
    }

    if ($firstName === '' || $lastName === '' || $country === '') {
        $errors[] = 'Complete all required personal information fields.';
    }

    if (!validDate($birthdate) || $birthdate > date('Y-m-d')) {
        $errors[] = 'Enter a valid date of birth that is not in the future.';
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

    if (!validDate($diagnosisDate) || $diagnosisDate > date('Y-m-d')) {
        $errors[] = 'Enter a valid diagnosis date that is not in the future.';
    }

    if ($treatmentStart !== null && (!validDate($treatmentStart) || $treatmentStart > date('Y-m-d'))) {
        $errors[] = 'Enter a valid treatment start date.';
    }

    if ($treatmentEnd !== null && !validDate($treatmentEnd)) {
        $errors[] = 'Enter a valid treatment end date.';
    }

    if ($treatmentStart && $treatmentEnd && $treatmentEnd < $treatmentStart) {
        $errors[] = 'Treatment end date cannot be before its start date.';
    }

    if ($treatmentType === '' && ($treatmentDescription !== '' || $treatmentStart || $treatmentEnd)) {
        $errors[] = 'Enter a treatment type if providing treatment details.';
    }

    if ($diagnosisDate && validDate($diagnosisDate) && validDate($birthdate) && $diagnosisDate < $birthdate) {
        $errors[] = 'Diagnosis date cannot be before the date of birth.';
    }

    if (!$errors) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM cancer_types WHERE cancer_type_id = ?");
        $stmt->execute([(int) $cancerTypeId]);

        if (!(int) $stmt->fetchColumn()) {
            $errors[] = 'The selected cancer type does not exist.';
        }
    }

    if (!$errors) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? OR username = ?");
        $stmt->execute([$email, $username]);

        if ((int) $stmt->fetchColumn() > 0) {
            $errors[] = 'That email address or username is already registered.';
        }
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $temporaryPassword = bin2hex(random_bytes(32));
            $passwordHash = password_hash($temporaryPassword, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, 'patient')");
            $stmt->execute([$username, $email, $passwordHash]);
            $patientUserId = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare("INSERT INTO patients (user_id, first_name, last_name, birthdate, sex, country) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$patientUserId, $firstName, $lastName, $birthdate, $sex, $country]);
            $patientId = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare("INSERT INTO oncologist_patients (user_id, patient_id, assignment_status) VALUES (?, ?, 'active')");
            $stmt->execute([$user_id, $patientId]);

            $stmt = $pdo->prepare("INSERT INTO patient_diagnoses (patient_id, cancer_type_id, date_of_diagnosis, stage, status) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$patientId, (int) $cancerTypeId, $diagnosisDate, $stage, $diagnosisStatus]);
            $diagnosisId = (int) $pdo->lastInsertId();

            if ($treatmentType !== '') {
                $stmt = $pdo->prepare("INSERT INTO patient_treatments (diagnosis_id, treatment_type, treatment_description, start_date, end_date, status) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$diagnosisId, $treatmentType, $treatmentDescription ?: null, $treatmentStart, $treatmentEnd, $treatmentStatus]);
            }

            $stmt = $pdo->prepare("INSERT INTO patient_consents (patient_id, consent_type, consent_status, recorded_by) VALUES (?, 'ai_processing', ?, ?)");
            $stmt->execute([$patientId, $aiConsent, $user_id]);

            $stmt = $pdo->prepare("INSERT INTO patient_consents (patient_id, consent_type, consent_status, recorded_by) VALUES (?, 'research', ?, ?)");
            $stmt->execute([$patientId, $researchConsent, $user_id]);

            $pdo->commit();
            $success = true;

            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $csrfToken = $_SESSION['csrf_token'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Patient registration failed: ' . $e->getMessage());
            $errors[] = 'Patient registration failed. Please check the details and try again.';
        }
    }
}

function selected($name, $value, $default = ''): string {
    $current = $_POST[$name] ?? $default;
    return (string) $current === (string) $value ? 'selected' : '';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CancerInsight | Register Patient</title>
    <link rel="stylesheet" href="../style/clinical.css">
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
            <div class="doctor-avatar"><?= h(strtoupper(substr($oncologist['first_name'], 0, 1))) ?></div>
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
            <p class="welcome-description">Create a patient account and add their clinical information.</p>
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
                <p>The patient account, clinical profile, diagnosis, consent records and oncologist assignment have been saved.</p>
                <a href="patients.php" class="ci-button">Return to patient directory</a>
            </div>
        </section>
    <?php else: ?>

        <?php if ($errors): ?>
            <section class="panel" role="alert">
                <h3>Please correct the following:</h3>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= h($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <form method="POST" class="panel ci-form">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

            <div class="panel-heading">
                <div>
                    <h3>Account Details</h3>
                    <p>Patient login information</p>
                </div>
            </div>

            <div class="ci-form-row">
                <label for="email">Email address *</label>
                <input type="email" id="email" name="email" required value="<?= h($_POST['email'] ?? '') ?>">
            </div>

            <div class="ci-form-row">
                <label for="username">Username *</label>
                <input type="text" id="username" name="username" maxlength="50" required value="<?= h($_POST['username'] ?? '') ?>">
            </div>

            <div class="panel-heading">
                <div>
                    <h3>Personal Information</h3>
                    <p>Patient identity and demographic details</p>
                </div>
            </div>

            <div class="ci-form-row">
                <label for="first_name">First name *</label>
                <input type="text" id="first_name" name="first_name" maxlength="100" required value="<?= h($_POST['first_name'] ?? '') ?>">
            </div>

            <div class="ci-form-row">
                <label for="last_name">Last name *</label>
                <input type="text" id="last_name" name="last_name" maxlength="100" required value="<?= h($_POST['last_name'] ?? '') ?>">
            </div>

            <div class="ci-form-row">
                <label for="birthdate">Date of birth *</label>
                <input type="date" id="birthdate" name="birthdate" max="<?= date('Y-m-d') ?>" required value="<?= h($_POST['birthdate'] ?? '') ?>">
            </div>

            <div class="ci-form-row">
                <label for="sex">Sex *</label>
                <select id="sex" name="sex" required>
                    <option value="unknown" <?= selected('sex', 'unknown', 'unknown') ?>>Unknown</option>
                    <option value="female" <?= selected('sex', 'female') ?>>Female</option>
                    <option value="male" <?= selected('sex', 'male') ?>>Male</option>
                    <option value="other" <?= selected('sex', 'other') ?>>Other</option>
                </select>
            </div>

            <div class="ci-form-row">
                <label for="country">Country *</label>
                <input type="text" id="country" name="country" maxlength="100" required value="<?= h($_POST['country'] ?? '') ?>">
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
                        <option value="<?= h($type['cancer_type_id']) ?>" <?= selected('cancer_type_id', $type['cancer_type_id']) ?>>
                            <?= h($type['cancer_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="ci-form-row">
                <label for="date_of_diagnosis">Date of diagnosis *</label>
                <input type="date" id="date_of_diagnosis" name="date_of_diagnosis" max="<?= date('Y-m-d') ?>" required value="<?= h($_POST['date_of_diagnosis'] ?? '') ?>">
            </div>

            <div class="ci-form-row">
                <label for="stage">Cancer stage *</label>
                <select id="stage" name="stage">
                    <option value="unknown" <?= selected('stage', 'unknown', 'unknown') ?>>Unknown</option>
                    <option value="0" <?= selected('stage', '0') ?>>Stage 0</option>
                    <option value="I" <?= selected('stage', 'I') ?>>Stage I</option>
                    <option value="II" <?= selected('stage', 'II') ?>>Stage II</option>
                    <option value="III" <?= selected('stage', 'III') ?>>Stage III</option>
                    <option value="IV" <?= selected('stage', 'IV') ?>>Stage IV</option>
                </select>
            </div>

            <div class="ci-form-row">
                <label for="diagnosis_status">Diagnosis status *</label>
                <select id="diagnosis_status" name="diagnosis_status">
                    <option value="active" <?= selected('diagnosis_status', 'active', 'active') ?>>Active</option>
                    <option value="remission" <?= selected('diagnosis_status', 'remission') ?>>Remission</option>
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
                <input type="text" id="treatment_type" name="treatment_type" maxlength="100" value="<?= h($_POST['treatment_type'] ?? '') ?>">
            </div>

            <div class="ci-form-row">
                <label for="treatment_description">Treatment description</label>
                <textarea id="treatment_description" name="treatment_description"><?= h($_POST['treatment_description'] ?? '') ?></textarea>
            </div>

            <div class="ci-form-row">
                <label for="treatment_start">Treatment start date</label>
                <input type="date" id="treatment_start" name="treatment_start" value="<?= h($_POST['treatment_start'] ?? '') ?>">
            </div>

            <div class="ci-form-row">
                <label for="treatment_end">Treatment end date</label>
                <input type="date" id="treatment_end" name="treatment_end" value="<?= h($_POST['treatment_end'] ?? '') ?>">
            </div>

            <div class="ci-form-row">
                <label for="treatment_status">Treatment status</label>
                <select id="treatment_status" name="treatment_status">
                    <option value="ongoing" <?= selected('treatment_status', 'ongoing', 'ongoing') ?>>Ongoing</option>
                    <option value="completed" <?= selected('treatment_status', 'completed') ?>>Completed</option>
                    <option value="discontinued" <?= selected('treatment_status', 'discontinued') ?>>Discontinued</option>
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
                    <option value="denied" <?= selected('ai_consent', 'denied', 'denied') ?>>Not granted</option>
                    <option value="granted" <?= selected('ai_consent', 'granted') ?>>Granted</option>
                </select>
            </div>

            <div class="ci-form-row">
                <label for="research_consent">Research data consent *</label>
                <select id="research_consent" name="research_consent" required>
                    <option value="denied" <?= selected('research_consent', 'denied', 'denied') ?>>Not granted</option>
                    <option value="granted" <?= selected('research_consent', 'granted') ?>>Granted</option>
                </select>
            </div>

            <p class="ci-note">Only record consent decisions that have actually been communicated and confirmed by the patient. These selections must reflect the patient's informed choices, not the clinician's assumptions.</p>

            <button type="submit" class="ci-button">Register Patient</button>
        </form>
    <?php endif; ?>
</main>

</body>
</html>
