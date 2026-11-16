<?php
// Data for the oncologist and researcher dashboards.
// Each function reads from the database, or from includes/placeholder/dashboard-data.php while that file exists.

require_once __DIR__ . '/helpers.php';

const DASHBOARD_PAGE_SIZE = 10;

function dashboard_source(): array
{
    $placeholder = __DIR__ . '/placeholder/dashboard-data.php';
    if (file_exists($placeholder)) {
        return ['placeholder' => true, 'data' => require $placeholder];
    }
    require __DIR__ . '/db.php';
    return ['placeholder' => false, 'pdo' => $pdo];
}

// SQL that orders mutation types by impact, matching mutation_impact() in helpers.php
const IMPACT_ORDER_SQL = "CASE
    WHEN m.mutation_type IN ('nonsense', 'frameshift', 'splice acceptor', 'splice donor', 'start lost', 'stop lost') THEN 0
    WHEN m.mutation_type IN ('missense', 'inframe deletion', 'inframe insertion') THEN 1
    WHEN m.mutation_type IN ('synonymous', 'splice region', '5'' UTR start gain') THEN 2
    ELSE 3 END";

function impact_rank(string $type): int
{
    return ['high' => 0, 'moderate' => 1, 'low' => 2, 'modifier' => 3][mutation_impact($type)];
}

function chromosome_rank(string $c): int
{
    return ['X' => 23, 'Y' => 24, 'MT' => 25][$c] ?? (int)$c;
}

/** Headline numbers for the tiles. */
function dashboard_stats(array $src): array
{
    if ($src['placeholder']) {
        return $src['data']['stats'];
    }
    $pdo = $src['pdo'];
    return [
        'patients'           => (int)$pdo->query('SELECT COUNT(*) FROM patients')->fetchColumn(),
        'samples'            => (int)$pdo->query('SELECT COUNT(*) FROM samples')->fetchColumn(),
        'patient_mutations'  => (int)$pdo->query('SELECT COUNT(*) FROM patient_mutations')->fetchColumn(),
        'awaiting_feedback'  => (int)$pdo->query(
            'SELECT COUNT(*) FROM predictions pr
             WHERE NOT EXISTS (SELECT 1 FROM feedback f WHERE f.prediction_id = pr.prediction_id)'
        )->fetchColumn(),
    ];
}

/** Values for the filter dropdowns. */
function dashboard_filter_options(array $src): array
{
    if ($src['placeholder']) {
        return $src['data']['filter_options'];
    }
    $pdo = $src['pdo'];
    $chromosomes = $pdo->query('SELECT DISTINCT chromosome FROM genes')->fetchAll(PDO::FETCH_COLUMN);
    usort($chromosomes, fn($a, $b) => chromosome_rank($a) <=> chromosome_rank($b));
    $types = $pdo->query('SELECT DISTINCT mutation_type FROM mutations')->fetchAll(PDO::FETCH_COLUMN);
    usort($types, fn($a, $b) => [impact_rank($a), $a] <=> [impact_rank($b), $b]);
    return [
        'cancer_types' => $pdo->query('SELECT cancer_name FROM cancer_types ORDER BY cancer_name')->fetchAll(PDO::FETCH_COLUMN),
        'chromosomes'  => $chromosomes,
        'types'        => $types,
        'named_genes'  => $pdo->query("SELECT gene_symbol FROM genes WHERE gene_symbol NOT LIKE 'ENSG%' ORDER BY gene_symbol")
                              ->fetchAll(PDO::FETCH_COLUMN),
    ];
}

/**
 * One page of patients, with their latest diagnosis, mutation count and latest prediction.
 * Returns [rows, total].
 */
function dashboard_patients(array $src, string $search, string $cancer, int $page): array
{
    $offset = ($page - 1) * DASHBOARD_PAGE_SIZE;

    if ($src['placeholder']) {
        $rows = array_values(array_filter($src['data']['patients'], function ($p) use ($search, $cancer) {
            $name = strtolower($p['first_name'] . ' ' . $p['last_name']);
            return ($cancer === '' || $p['cancer_name'] === $cancer)
                && ($search === '' || str_contains($name, strtolower($search)) || (string)$p['patient_id'] === $search);
        }));
        return [array_slice($rows, $offset, DASHBOARD_PAGE_SIZE), count($rows)];
    }

    $pdo = $src['pdo'];
    $where = 'WHERE (? = \'\' OR ct.cancer_name = ?)
              AND (? = \'\' OR CONCAT(p.first_name, \' \', p.last_name) LIKE ? OR p.patient_id = ?)';
    $params = [$cancer, $cancer, $search, '%' . $search . '%', ctype_digit($search) ? (int)$search : 0];
    $from = 'FROM patients p
             LEFT JOIN patient_diagnoses d ON d.diagnosis_id = (
                 SELECT d2.diagnosis_id FROM patient_diagnoses d2
                 WHERE d2.patient_id = p.patient_id
                 ORDER BY d2.date_of_diagnosis DESC LIMIT 1)
             LEFT JOIN cancer_types ct ON ct.cancer_type_id = d.cancer_type_id';

    $stmt = $pdo->prepare("SELECT COUNT(*) $from $where");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT p.patient_id, p.first_name, p.last_name, p.birthdate, p.sex,
                ct.cancer_name, d.stage, d.status,
                (SELECT COUNT(*) FROM samples s
                 JOIN patient_mutations pm ON pm.sample_id = s.sample_id
                 WHERE s.patient_id = p.patient_id) AS mutation_count
         $from $where
         ORDER BY p.last_name, p.first_name, p.patient_id
         LIMIT " . DASHBOARD_PAGE_SIZE . " OFFSET " . (int)$offset
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Latest prediction for each patient on this page: the cancer type with the highest probability
    if ($rows) {
        $ids = array_column($rows, 'patient_id');
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            "SELECT pr.patient_id, ct.cancer_name, res.prediction_probability
             FROM predictions pr
             JOIN prediction_results res ON res.prediction_id = pr.prediction_id
             JOIN cancer_types ct ON ct.cancer_type_id = res.cancer_type_id
             WHERE pr.prediction_id IN (
                 SELECT MAX(prediction_id) FROM predictions WHERE patient_id IN ($in) GROUP BY patient_id)
             ORDER BY res.prediction_probability DESC"
        );
        $stmt->execute($ids);
        $latest = [];
        foreach ($stmt->fetchAll() as $r) {
            $latest[$r['patient_id']] ??= ['cancer' => $r['cancer_name'], 'probability' => (float)$r['prediction_probability']];
        }
        foreach ($rows as &$row) {
            $row['prediction'] = $latest[$row['patient_id']] ?? null;
        }
        unset($row);
    }
    return [$rows, $total];
}

/**
 * One page of patient mutations matching the explorer filters.
 * $f keys: chromosome, gene (symbol prefix), from, to (positions), type. Returns [rows, total].
 */
function dashboard_mutations(array $src, array $f, int $page): array
{
    $offset = ($page - 1) * DASHBOARD_PAGE_SIZE;

    if ($src['placeholder']) {
        $gene = strtoupper($f['gene']);
        $rows = array_values(array_filter($src['data']['mutation_rows'], fn($r) =>
            ($f['chromosome'] === '' || $r['chromosome'] === $f['chromosome'])
            && ($gene === '' || str_starts_with(strtoupper($r['gene_symbol']), $gene))
            && ($f['from'] === null || $r['genomic_position'] >= $f['from'])
            && ($f['to'] === null || $r['genomic_position'] <= $f['to'])
            && ($f['type'] === '' || $r['mutation_type'] === $f['type'])));
        return [array_slice($rows, $offset, DASHBOARD_PAGE_SIZE), count($rows)];
    }

    $pdo = $src['pdo'];
    $where = [];
    $params = [];
    if ($f['chromosome'] !== '') { $where[] = 'g.chromosome = ?';       $params[] = $f['chromosome']; }
    if ($f['gene'] !== '')       { $where[] = 'g.gene_symbol LIKE ?';    $params[] = $f['gene'] . '%'; }
    if ($f['from'] !== null)     { $where[] = 'm.genomic_position >= ?'; $params[] = $f['from']; }
    if ($f['to'] !== null)       { $where[] = 'm.genomic_position <= ?'; $params[] = $f['to']; }
    if ($f['type'] !== '')       { $where[] = 'm.mutation_type = ?';     $params[] = $f['type']; }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $from = 'FROM patient_mutations pm
             JOIN mutations m ON m.mutation_id = pm.mutation_id
             JOIN genes g ON g.gene_id = m.gene_id
             JOIN samples s ON s.sample_id = pm.sample_id
             JOIN patients p ON p.patient_id = s.patient_id';

    $stmt = $pdo->prepare("SELECT COUNT(*) $from $whereSql");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT m.mutation_id, g.gene_symbol, g.chromosome, m.genomic_position, m.original_allele,
                m.altered_allele, m.mutation_type, p.patient_id, p.first_name, p.last_name
         $from $whereSql
         ORDER BY " . IMPACT_ORDER_SQL . ", g.gene_symbol LIKE 'ENSG%', g.gene_symbol, m.genomic_position, p.last_name, p.first_name, p.patient_id
         LIMIT " . DASHBOARD_PAGE_SIZE . " OFFSET " . (int)$offset
    );
    $stmt->execute($params);
    return [$stmt->fetchAll(), $total];
}

/**
 * Patients who share a mutation, and patients with any mutation in the same gene.
 * Returns null if the mutation does not exist.
 */
function dashboard_related(array $src, int $mutationId, int $patientId): ?array
{
    if ($src['placeholder']) {
        $rows = $src['data']['mutation_rows'];
        $match = null;
        foreach ($rows as $r) {
            if ($r['mutation_id'] === $mutationId) { $match = $r; break; }
        }
        if (!$match) {
            return null;
        }
        $patients = $src['data']['patients_by_id'];
        $same = $gene = [];
        foreach ($rows as $r) {
            if ($r['patient_id'] === $patientId) continue;
            if ($r['mutation_id'] === $mutationId) $same[$r['patient_id']] = $patients[$r['patient_id']];
            elseif ($r['gene_symbol'] === $match['gene_symbol']) $gene[$r['patient_id']] = $patients[$r['patient_id']];
        }
        $gene = array_diff_key($gene, $same);
        $byName = fn($a, $b) => [$a['last_name'], $a['first_name'], $a['patient_id']] <=> [$b['last_name'], $b['first_name'], $b['patient_id']];
        usort($same, $byName);
        usort($gene, $byName);
        return ['mutation' => $match, 'same_mutation' => $same, 'same_gene' => $gene];
    }

    $pdo = $src['pdo'];
    $stmt = $pdo->prepare(
        'SELECT m.mutation_id, m.gene_id, g.gene_symbol, g.chromosome, m.genomic_position,
                m.original_allele, m.altered_allele, m.mutation_type
         FROM mutations m JOIN genes g ON g.gene_id = m.gene_id WHERE m.mutation_id = ?'
    );
    $stmt->execute([$mutationId]);
    $mutation = $stmt->fetch();
    if (!$mutation) {
        return null;
    }

    $patientSql = 'SELECT DISTINCT p.patient_id, p.first_name, p.last_name, p.birthdate, p.sex, ct.cancer_name
                   FROM patient_mutations pm
                   JOIN mutations m ON m.mutation_id = pm.mutation_id
                   JOIN samples s ON s.sample_id = pm.sample_id
                   JOIN patients p ON p.patient_id = s.patient_id
                   LEFT JOIN patient_diagnoses d ON d.diagnosis_id = s.diagnosis_id
                   LEFT JOIN cancer_types ct ON ct.cancer_type_id = d.cancer_type_id';

    $stmt = $pdo->prepare("$patientSql WHERE pm.mutation_id = ? AND p.patient_id <> ? ORDER BY p.last_name, p.first_name, p.patient_id");
    $stmt->execute([$mutationId, $patientId]);
    $same = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        "$patientSql WHERE m.gene_id = ? AND p.patient_id <> ?
         AND p.patient_id NOT IN (SELECT s2.patient_id FROM patient_mutations pm2
                                  JOIN samples s2 ON s2.sample_id = pm2.sample_id
                                  WHERE pm2.mutation_id = ?)
         ORDER BY p.last_name, p.first_name, p.patient_id"
    );
    $stmt->execute([$mutation['gene_id'], $patientId, $mutationId]);
    $gene = $stmt->fetchAll();

    return ['mutation' => $mutation, 'same_mutation' => $same, 'same_gene' => $gene];
}

/** Named genes with how many patients have a mutation in each, most first. */
function dashboard_gene_options(array $src): array
{
    if ($src['placeholder']) {
        return $src['data']['gene_options'];
    }
    return $src['pdo']->query(
        "SELECT g.gene_symbol, COUNT(DISTINCT s.patient_id) AS patients
         FROM patient_mutations pm
         JOIN mutations m ON m.mutation_id = pm.mutation_id
         JOIN genes g ON g.gene_id = m.gene_id
         JOIN samples s ON s.sample_id = pm.sample_id
         WHERE g.gene_symbol NOT LIKE 'ENSG%'
         GROUP BY g.gene_symbol
         ORDER BY patients DESC, g.gene_symbol"
    )->fetchAll();
}

/**
 * Patients with at least one mutation in each selected gene, split by cancer type.
 * Returns ['TP53' => ['Breast' => 4, 'Brain' => 2, ...], ...].
 */
function dashboard_gene_counts(array $src, array $symbols): array
{
    if (!$symbols) {
        return [];
    }
    $counts = array_fill_keys($symbols, []);

    if ($src['placeholder']) {
        $seen = [];
        $patients = $src['data']['patients_by_id'];
        foreach ($src['data']['mutation_rows'] as $r) {
            $g = $r['gene_symbol'];
            if (!isset($counts[$g]) || isset($seen[$g][$r['patient_id']])) continue;
            $seen[$g][$r['patient_id']] = true;
            $c = $patients[$r['patient_id']]['cancer_name'];
            $counts[$g][$c] = ($counts[$g][$c] ?? 0) + 1;
        }
        foreach ($counts as &$c) { ksort($c); }
    unset($c);
        return $counts;
    }

    $in = implode(',', array_fill(0, count($symbols), '?'));
    $stmt = $src['pdo']->prepare(
        "SELECT g.gene_symbol, ct.cancer_name, COUNT(DISTINCT s.patient_id) AS patients
         FROM patient_mutations pm
         JOIN mutations m ON m.mutation_id = pm.mutation_id
         JOIN genes g ON g.gene_id = m.gene_id
         JOIN samples s ON s.sample_id = pm.sample_id
         JOIN patient_diagnoses d ON d.diagnosis_id = s.diagnosis_id
         JOIN cancer_types ct ON ct.cancer_type_id = d.cancer_type_id
         WHERE g.gene_symbol IN ($in)
         GROUP BY g.gene_symbol, ct.cancer_name"
    );
    $stmt->execute($symbols);
    foreach ($stmt->fetchAll() as $r) {
        $counts[$r['gene_symbol']][$r['cancer_name']] = (int)$r['patients'];
    }
    foreach ($counts as &$c) { ksort($c); }
    unset($c);
    return $counts;
}

/** Read and clean the explorer filters from the query string. */
function dashboard_mutation_filters(array $get): array
{
    $num = fn($v) => isset($v) && ctype_digit(str_replace(',', '', (string)$v)) ? (int)str_replace(',', '', $v) : null;
    return [
        'chromosome' => trim($get['chromosome'] ?? ''),
        'gene'       => strtoupper(trim($get['gene'] ?? '')),
        'from'       => $num($get['from'] ?? null),
        'to'         => $num($get['to'] ?? null),
        'type'       => trim($get['type'] ?? ''),
    ];
}

/** The current URL with some query parameters changed (null removes one). */
function url_with(array $changes): string
{
    $query = array_merge($_GET, $changes);
    $query = array_filter($query, fn($v) => $v !== null && $v !== '');
    return '?' . http_build_query($query);
}

/** Guard for professional pages: returns the signed-in professional, or redirects. */
function require_professional(array $src, string $type): array
{
    if ($src['placeholder']) {
        return $src['data']['users'][$type];
    }
    if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'professional') {
        header('Location: login.php');
        exit();
    }
    $stmt = $src['pdo']->prepare(
        'SELECT first_name, last_name, professional_type, institution, speciality FROM professionals WHERE user_id = ?'
    );
    $stmt->execute([$_SESSION['user_id']]);
    $pro = $stmt->fetch();
    if (!$pro) {
        header('Location: login.php');
        exit();
    }
    if ($pro['professional_type'] !== $type) {
        header('Location: ' . $pro['professional_type'] . '-dashboard.php');
        exit();
    }
    return [
        'user_id' => $_SESSION['user_id'],
        'name'        => 'Dr ' . $pro['first_name'] . ' ' . $pro['last_name'],
        'last_name'   => $pro['last_name'],
        'role'        => $type,
        'institution' => $pro['institution'],
        'speciality'  => $pro['speciality'],
    ];
}

function dashboard_audit(array $src, int $userId, string $action, ?string $details = null): void
{
    if ($src['placeholder']) {
        return;
    }
    $src['pdo']->prepare('INSERT INTO audit_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)')
        ->execute([$userId, $action, $details, $_SERVER['REMOTE_ADDR'] ?? null]);
}
