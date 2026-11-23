<?php
/*
 * Shared by every researcher page (research-*.php, researcher-dashboard.php,
 * model-performance.php, my-feedback.php, feedback-save.php).
 *
 *   research_require_login()   who is signed in; sends anyone but a researcher to login.php
 *   cancer_types() / cancer_chip() / CANCER_GROUPS   the 33 TCGA types and their 8 organ groups
 *   PREDICTION_SQL             one row per prediction with its top call, diagnosis and status
 *   audit()                    one row in audit_logs
 *
 * HOW THE SCHEMA IS USED (database/schema.sql as it stands)
 *   - A prediction's DOCTOR is the oncologist who ran it: predictions.user_id.
 *   - The model's CALL is the cancer type with the highest probability in prediction_results.
 *   - The RECORDED DIAGNOSIS is the patient's most recent row in patient_diagnoses; a call is
 *     "correct" when it matches that diagnosis.
 *   - A prediction is REVIEWED once the doctor who ran it has left feedback on it;
 *     until then it is awaiting review.
 *   - Feedback is feedback_rating (1-5, how useful the prediction was) plus comments.
 *   - Researchers only see patient IDs, never names, birthdates or sample codes.
 */

require_once __DIR__ . '/helpers.php';

// ---------------------------------------------------------------------------
// Sign-in
// ---------------------------------------------------------------------------

/**
 * Starts the session, connects to the database and returns the signed-in researcher:
 * ['user_id', 'name' => 'Dr First Last', 'role' => 'researcher', 'first_name', 'last_name'].
 * Anyone not signed in, or signed in as something other than a researcher, goes to login.php.
 * Also makes $pdo available to the page (it is created in includes/db.php).
 */
function research_require_login(): array
{
    global $pdo;
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    require_once __DIR__ . '/db.php';

    if (empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit();
    }

    $stmt = $pdo->prepare(
        "SELECT p.user_id, p.first_name, p.last_name
         FROM professionals p
         WHERE p.user_id = ? AND p.professional_type = 'researcher'"
    );
    $stmt->execute([$_SESSION['user_id']]);
    $row = $stmt->fetch();
    if (!$row) {
        header('Location: login.php');
        exit();
    }

    return [
        'user_id'    => (int)$row['user_id'],
        'name'       => 'Dr ' . $row['first_name'] . ' ' . $row['last_name'],
        'role'       => 'researcher',
        'first_name' => $row['first_name'],
        'last_name'  => $row['last_name'],
    ];
}

/** One audit_logs row. Never stops the page if logging fails. */
function audit(PDO $pdo, int $userId, string $action, ?string $entityType = null, ?int $entityId = null, ?string $details = null): void
{
    try {
        $pdo->prepare(
            'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$userId, $action, $entityType, $entityId, $details, $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (PDOException $e) {
        // auditing must not break the page
    }
}

// ---------------------------------------------------------------------------
// Cancer types and organ groups
// ---------------------------------------------------------------------------
// cancer_types.cancer_name holds the TCGA code (BRCA) and description the full
// name (Breast invasive carcinoma). 33 colours can't be told apart, so charts and
// dots colour by organ group (8 colours, .dot-group-* / --group-* in app.css) and
// always print the code next to the colour.

const CANCER_GROUPS = [
    // key => [label, TCGA codes]   (order = colour order, largest group first)
    'breast_gyn' => ['Breast and gynaecological', ['BRCA', 'OV', 'UCEC', 'UCS', 'CESC']],
    'urinary'    => ['Urinary and male reproductive', ['KIRC', 'KIRP', 'KICH', 'BLCA', 'PRAD', 'TGCT']],
    'digestive'  => ['Digestive', ['COAD', 'READ', 'STAD', 'ESCA', 'PAAD', 'LIHC', 'CHOL']],
    'lung_hn'    => ['Lung, head and neck', ['LUAD', 'LUSC', 'MESO', 'HNSC', 'THYM']],
    'endocrine'  => ['Endocrine and soft tissue', ['THCA', 'ACC', 'PCPG', 'SARC']],
    'brain'      => ['Brain', ['GBM', 'LGG']],
    'skin_eye'   => ['Skin and eye', ['SKCM', 'UVM']],
    'blood'      => ['Blood and lymph', ['LAML', 'DLBC']],
];

/** Short group names for tight spots such as table column headers. */
const GROUP_SHORT_NAMES = [
    'breast_gyn' => 'Breast/gyn', 'urinary' => 'Urinary', 'digestive' => 'Digestive', 'lung_hn' => 'Lung/H&N',
    'endocrine' => 'Endocrine', 'brain' => 'Brain', 'skin_eye' => 'Skin/eye', 'blood' => 'Blood',
];

/** Organ group key for a TCGA code ('other' if a new code appears). */
function cancer_group(string $code): string
{
    foreach (CANCER_GROUPS as $key => [, $codes]) {
        if (in_array($code, $codes, true)) {
            return $key;
        }
    }
    return 'other';
}

/**
 * All cancer types, loaded once per request:
 * cancer_type_id => ['id', 'code', 'name', 'group'], ordered by group then code.
 */
function cancer_types(PDO $pdo): array
{
    static $types = null;
    if ($types === null) {
        $types = [];
        foreach ($pdo->query('SELECT cancer_type_id, cancer_name, description FROM cancer_types') as $row) {
            $types[(int)$row['cancer_type_id']] = [
                'id'    => (int)$row['cancer_type_id'],
                'code'  => $row['cancer_name'],
                'name'  => $row['description'] ?: $row['cancer_name'],
                'group' => cancer_group($row['cancer_name']),
            ];
        }
        $groupOrder = array_flip(array_keys(CANCER_GROUPS));
        uasort($types, fn($a, $b) => [$groupOrder[$a['group']] ?? 99, $a['code']] <=> [$groupOrder[$b['group']] ?? 99, $b['code']]);
    }
    return $types;
}

/** Cancer type chip: group-coloured dot + code, full name on hover. Empty string for null. */
function cancer_chip(?array $type): string
{
    if (!$type) {
        return '<span class="muted">—</span>';
    }
    return '<span class="chip" title="' . e($type['name']) . '">'
         . '<span class="dot dot-group-' . e($type['group']) . '" aria-hidden="true"></span>'
         . e($type['code']) . '</span>';
}

/** <option>s for a cancer-type <select>, grouped by organ group in <optgroup>s. */
function cancer_type_options(PDO $pdo, $selectedId): string
{
    $html = '';
    $byGroup = [];
    foreach (cancer_types($pdo) as $type) {
        $byGroup[$type['group']][] = $type;
    }
    foreach ($byGroup as $group => $types) {
        $html .= '<optgroup label="' . e(CANCER_GROUPS[$group][0] ?? 'Other') . '">';
        foreach ($types as $t) {
            $html .= '<option value="' . $t['id'] . '"' . ((string)$t['id'] === (string)$selectedId ? ' selected' : '') . '>'
                   . e($t['code'] . ' · ' . $t['name']) . '</option>';
        }
        $html .= '</optgroup>';
    }
    return $html;
}

// ---------------------------------------------------------------------------
// Models and review status
// ---------------------------------------------------------------------------

/** predictions.model_used => readable name. Unknown values show as stored. */
const MODEL_LABELS = [
    'mutation-v1'   => 'Mutation model v1',
    'expression-v2' => 'Expression model v2',
];

function model_label(string $model): string
{
    return MODEL_LABELS[$model] ?? $model;
}

/** Review status of a prediction (see the top of this file). */
const REVIEW_STATUSES = [
    'pending'  => 'Awaiting review',
    'reviewed' => 'Reviewed',
];

function status_chip(bool $reviewed): string
{
    return $reviewed
        ? '<span class="chip status-reviewed">Reviewed</span>'
        : '<span class="chip status-pending_review">Awaiting review</span>';
}

/** Tick or cross for "did the model's call match the recorded diagnosis". */
function correct_mark(?bool $correct): string
{
    if ($correct === null) {
        return '<span class="muted">—</span>';
    }
    return $correct
        ? '<span class="mark-yes" aria-hidden="true">✓</span><span class="visually-hidden">Matches</span>'
        : '<span class="mark-no" aria-hidden="true">✗</span><span class="visually-hidden">Does not match</span>';
}

/** 0.8734 -> "87%"; null -> "—" */
function pct($fraction, int $decimals = 0): string
{
    return $fraction === null ? '—' : number_format((float)$fraction * 100, $decimals) . '%';
}

// ---------------------------------------------------------------------------
// One row per prediction
// ---------------------------------------------------------------------------
// Use as a derived table:  FROM (PREDICTION_SQL) pv  and filter on its columns:
//   prediction_id, patient_id, doctor_id, model_used, created_at,
//   top_type_id, top_probability, dx_type_id, correct (1/0/NULL), reviewed (1/0),
//   feedback_count
// The top call is the highest probability for the prediction (ties go to the lower
// type id); the diagnosis is the patient's most recent patient_diagnoses row.

const PREDICTION_SQL = "
    SELECT p.prediction_id, p.patient_id, p.user_id AS doctor_id, p.model_used, p.created_at,
           top.cancer_type_id AS top_type_id, top.prediction_probability AS top_probability,
           dx.cancer_type_id AS dx_type_id,
           CASE WHEN dx.cancer_type_id IS NULL THEN NULL
                ELSE top.cancer_type_id = dx.cancer_type_id END AS correct,
           EXISTS (SELECT 1 FROM feedback f
                   WHERE f.prediction_id = p.prediction_id AND f.user_id = p.user_id) AS reviewed,
           (SELECT COUNT(*) FROM feedback f WHERE f.prediction_id = p.prediction_id) AS feedback_count
    FROM predictions p
    LEFT JOIN (
        SELECT prediction_id, cancer_type_id, prediction_probability
        FROM (SELECT pr.*, ROW_NUMBER() OVER (PARTITION BY pr.prediction_id
                                              ORDER BY pr.prediction_probability DESC, pr.cancer_type_id) AS rn
              FROM prediction_results pr) ranked
        WHERE rn = 1
    ) top ON top.prediction_id = p.prediction_id
    LEFT JOIN (
        SELECT patient_id, cancer_type_id
        FROM (SELECT d.*, ROW_NUMBER() OVER (PARTITION BY d.patient_id
                                             ORDER BY d.date_of_diagnosis DESC, d.diagnosis_id DESC) AS rn
              FROM patient_diagnoses d) ranked
        WHERE rn = 1
    ) dx ON dx.patient_id = p.patient_id
";

/** Model versions that have predictions, newest run first. */
function model_versions(PDO $pdo): array
{
    return $pdo->query('SELECT model_used FROM predictions GROUP BY model_used ORDER BY MAX(created_at) DESC')
               ->fetchAll(PDO::FETCH_COLUMN);
}

/** Oncologists: user_id => 'Dr First Last', by last name. */
function oncologist_names(PDO $pdo): array
{
    $names = [];
    foreach ($pdo->query("SELECT user_id, first_name, last_name FROM professionals
                          WHERE professional_type = 'oncologist' ORDER BY last_name, first_name") as $row) {
        $names[(int)$row['user_id']] = 'Dr ' . $row['first_name'] . ' ' . $row['last_name'];
    }
    return $names;
}

/** Pager: "1–20 of 2,000" text plus Previous / Next links that keep the other filters. */
function pager(string $param, int $page, int $perPage, int $total, string $anchor, string $noun): string
{
    $pages = max(1, (int)ceil($total / $perPage));
    $from = $total ? ($page - 1) * $perPage + 1 : 0;
    $to = min($total, $page * $perPage);
    $html = '<nav class="pager" aria-label="' . e(ucfirst($noun)) . ' pages"><span class="muted num">'
          . ($total ? number_format($from) . '–' . number_format($to) . ' of ' . number_format($total) . ' ' . e($noun) : 'No ' . e($noun))
          . '</span><span class="pager-links">';
    if ($page > 1) {
        $html .= '<a class="btn" href="' . e(query_with([$param => $page - 1])) . '#' . e($anchor) . '">Previous</a>';
    }
    if ($page < $pages) {
        $html .= '<a class="btn" href="' . e(query_with([$param => $page + 1])) . '#' . e($anchor) . '">Next</a>';
    }
    return $html . '</span></nav>';
}

/** Page number from the URL, at least 1 and at most the last page. */
function page_number(string $param, int $total, int $perPage): int
{
    $pages = max(1, (int)ceil($total / $perPage));
    return min($pages, max(1, (int)($_GET[$param] ?? 1)));
}

/** Rating chip: "4 / 5", green for 4-5, amber for 3, red for 1-2 (the number is always shown). */
function rating_chip($rating): string
{
    if ($rating === null || $rating === '') {
        return '<span class="muted">—</span>';
    }
    $rating = (int)$rating;
    $kind = $rating >= 4 ? 'agreement-agree' : ($rating === 3 ? 'agreement-partly' : 'agreement-disagree');
    return '<span class="chip ' . $kind . '">' . $rating . ' / 5</span>';
}

/** Patient label used everywhere researchers see a patient: the ID only, never a name. */
function patient_link(int $patientId, string $extraQuery = ''): string
{
    return '<a class="row-link" href="research-patient.php?id=' . $patientId . e($extraQuery) . '">Patient ' . $patientId . '</a>';
}

/** "chr17:7,577,120 C>T" (GRCh37). A reference allele too long for the schema is stored as NULL and shown as "…". */
function variant_name(array $m): string
{
    // long indel alleles are shortened so the table stays readable
    $short = fn(?string $allele) => $allele === null ? '…' : (strlen($allele) > 10 ? substr($allele, 0, 8) . '…' : $allele);
    return 'chr' . $m['chromosome'] . ':' . number_format((float)$m['genomic_position']) . ' '
         . $short($m['original_allele']) . '>' . $short($m['altered_allele']);
}

/**
 * The mutation types in the TCGA data (MAF Variant_Classification), most serious first.
 * A fixed list rather than SELECT DISTINCT, which would scan all 719,000 variants.
 */
const MUTATION_TYPES = [
    'Nonsense_Mutation', 'Frame_Shift_Del', 'Frame_Shift_Ins', 'Splice_Site', 'Translation_Start_Site',
    'Nonstop_Mutation', 'Missense_Mutation', 'In_Frame_Del', 'In_Frame_Ins', 'Silent',
    "5'UTR", "3'UTR", "5'Flank", "3'Flank", 'Intron', 'RNA',
];
