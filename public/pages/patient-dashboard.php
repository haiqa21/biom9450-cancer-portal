<?php

session_start();
require_once __DIR__ . '/../../includes/helpers.php';

$placeholderFile = __DIR__ . '/../../includes/placeholder/patient-dashboard-data.php';
$usingPlaceholder = file_exists($placeholderFile);

if ($usingPlaceholder) {
    $data        = require $placeholderFile;
    $currentUser = $data['user'];
    $patient     = $data['patient'];
    $diagnoses   = $data['diagnoses'];
    $samples     = $data['samples'];
    $mutations   = $data['mutations'];
} else {
    // Only patients may open this page
    if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'patient') {
        header('Location: login.php');
        exit();
    }

    require_once __DIR__ . '/../../includes/db.php';
    $userId = $_SESSION['user_id'];

    $stmt = $pdo->prepare('SELECT patient_id, first_name, last_name, birthdate, sex FROM patients WHERE user_id = ?');
    $stmt->execute([$userId]);
    $patient = $stmt->fetch();

    if (!$patient) {
        http_response_code(404);
        exit('No patient record is linked to this account. Please contact your care team.');
    }

    $stmt = $pdo->prepare(
        'SELECT ct.cancer_name, d.date_of_diagnosis, d.stage, d.status
         FROM patient_diagnoses d
         JOIN cancer_types ct ON ct.cancer_type_id = d.cancer_type_id
         WHERE d.patient_id = ?
         ORDER BY d.date_of_diagnosis DESC'
    );
    $stmt->execute([$patient['patient_id']]);
    $diagnoses = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        'SELECT s.sample_code, s.date_detected, COUNT(pm.mutation_id) AS mutation_count
         FROM samples s
         LEFT JOIN patient_mutations pm ON pm.sample_id = s.sample_id
         WHERE s.patient_id = ?
         GROUP BY s.sample_id, s.sample_code, s.date_detected
         ORDER BY s.date_detected DESC'
    );
    $stmt->execute([$patient['patient_id']]);
    $samples = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        'SELECT g.gene_symbol, g.chromosome, m.genomic_position, m.original_allele, m.altered_allele,
                m.mutation_type, m.clinical_significance, pm.allele_frequency, s.sample_code
         FROM samples s
         JOIN patient_mutations pm ON pm.sample_id = s.sample_id
         JOIN mutations m ON m.mutation_id = pm.mutation_id
         JOIN genes g ON g.gene_id = m.gene_id
         WHERE s.patient_id = ?'
    );
    $stmt->execute([$patient['patient_id']]);
    $mutations = $stmt->fetchAll();

    // Audit trail: record that the patient viewed their own record
    $pdo->prepare(
        'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([$userId, 'view_own_profile', 'patients', $patient['patient_id'], $_SERVER['REMOTE_ADDR'] ?? null]);
}


$impactOrder = ['high' => 0, 'moderate' => 1, 'low' => 2, 'modifier' => 3];
foreach ($mutations as &$m) {
    $m['impact'] = mutation_impact($m['mutation_type']);
}
unset($m);

// Chromosomes sort 1-22, then X, Y, MT (not alphabetically)
$chromosomeRank = fn($c) => ['X' => 23, 'Y' => 24, 'MT' => 25][$c] ?? (int)$c;

// Most important first: by impact, then named genes before Ensembl IDs, then position
usort($mutations, function ($a, $b) use ($impactOrder, $chromosomeRank) {
    return [$impactOrder[$a['impact']], !is_named_gene($a['gene_symbol']), $chromosomeRank($a['chromosome']), $a['genomic_position']]
       <=> [$impactOrder[$b['impact']], !is_named_gene($b['gene_symbol']), $chromosomeRank($b['chromosome']), $b['genomic_position']];
});

$latestDiagnosis = $diagnoses[0] ?? null;
$earlierDiagnoses = array_slice($diagnoses, 1);
$proteinChanging = count(array_filter($mutations, fn($m) => in_array($m['impact'], ['high', 'moderate'], true)));
$namedGenes = array_values(array_unique(array_map(
    fn($m) => $m['gene_symbol'],
    array_filter($mutations, fn($m) => is_named_gene($m['gene_symbol']))
)));

$stageLabel = fn($stage) => $stage === 'unknown' ? 'Not recorded' : 'Stage ' . $stage;

$pageTitle   = 'My results';
$activePage  = 'patient-dashboard';
$pageStyles  = ['patient-dashboard.css'];
$pageScripts = ['patient-dashboard.js'];
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1>Hello, <?= e($patient['first_name']) ?></h1>
        <p>Your diagnosis and genetic test results</p>
    </div>
</div>

<div class="grid-2">
    <section class="card" aria-labelledby="diagnosis-heading">
        <div class="card-header">
            <h2 id="diagnosis-heading">Your diagnosis</h2>
            <?php if ($latestDiagnosis): ?>
            <span class="chip <?= $latestDiagnosis['status'] === 'active' ? 'chip-success' : '' ?>">
                <?= $latestDiagnosis['status'] === 'active' ? 'Active treatment' : 'In remission' ?>
            </span>
            <?php endif; ?>
        </div>

        <?php if ($latestDiagnosis): ?>
        <dl class="details">
            <div>
                <dt>Cancer type</dt>
                <dd class="cancer-type">
                    <span class="dot dot-<?= e(strtolower($latestDiagnosis['cancer_name'])) ?>" aria-hidden="true"></span>
                    <?= e($latestDiagnosis['cancer_name']) ?>
                </dd>
            </div>
            <div>
                <dt>Stage</dt>
                <dd><?= e($stageLabel($latestDiagnosis['stage'])) ?></dd>
            </div>
            <div>
                <dt>Diagnosed</dt>
                <dd><?= e(format_date($latestDiagnosis['date_of_diagnosis'])) ?></dd>
            </div>
        </dl>

        <?php if ($earlierDiagnoses): ?>
        <h3 class="sub-heading">Earlier diagnoses</h3>
        <ul class="plain-list">
            <?php foreach ($earlierDiagnoses as $d): ?>
            <li><?= e($d['cancer_name']) ?>, <?= e(strtolower($stageLabel($d['stage']))) ?>, <?= e(format_date($d['date_of_diagnosis'])) ?></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <?php else: ?>
        <p class="muted">No diagnosis has been recorded yet.</p>
        <?php endif; ?>
    </section>

    <section class="card" aria-labelledby="details-heading">
        <div class="card-header">
            <h2 id="details-heading">Your details</h2>
        </div>
        <dl class="details">
            <div>
                <dt>Patient ID</dt>
                <dd class="num"><?= e($patient['patient_id']) ?></dd>
            </div>
            <div>
                <dt>Date of birth</dt>
                <dd><?= e(format_date($patient['birthdate'])) ?></dd>
            </div>
            <div>
                <dt>Age</dt>
                <dd><?= e(age_from_birthdate($patient['birthdate'])) ?></dd>
            </div>
            <div>
                <dt>Sex</dt>
                <dd><?= e(ucfirst($patient['sex'])) ?></dd>
            </div>
        </dl>
        <p class="muted small">Please contact care team to update details.</p>
    </section>
</div>

<section class="card" aria-labelledby="results-heading">
    <div class="card-header">
        <h2 id="results-heading">Your genetic test results</h2>
    </div>

    <?php if (!$samples): ?>
    <p class="muted">No samples have been tested yet. Your results will appear here once they are ready.</p>
    <?php else: ?>

    <div class="summary">
        <div class="summary-item">
            <span class="summary-value num"><?= count($mutations) ?></span>
            <span class="summary-label">changes found in your genes</span>
        </div>
        <div class="summary-item">
            <span class="summary-value num"><?= $proteinChanging ?></span>
            <span class="summary-label">change a protein</span>
        </div>
        <div class="summary-item">
            <span class="summary-value num"><?= count($samples) ?></span>
            <span class="summary-label"><?= count($samples) === 1 ? 'sample tested' : 'samples tested' ?></span>
        </div>
    </div>

    <button type="button" class="btn btn-small explainer-toggle" aria-expanded="false" aria-controls="results-explainer">
        Show explanation
    </button>

    <div class="explainer" id="results-explainer" hidden>
        <p>Your tumour sample was compared with a reference human genome. Each row below is one place where the DNA was different.
        Changes that alter a protein are listed first, because they are the ones most likely to matter.</p>
        <?php if ($namedGenes): ?>
        <p>Changes were found in these well-studied cancer genes:
            <?= implode(', ', array_map(fn($g) => '<strong class="gene">' . e($g) . '</strong>', $namedGenes)) ?>.
            Your oncologist will explain what these mean for you.</p>
        <?php endif; ?>
    </div>

    <ul class="sample-list" aria-label="Samples">
        <?php foreach ($samples as $s): ?>
        <li>
            <span class="mono"><?= e($s['sample_code']) ?></span>
            <span class="muted">
                <?= $s['date_detected'] ? 'Tested ' . e(format_date($s['date_detected'])) . ' · ' : '' ?>
                <?= e($s['mutation_count']) ?> changes
            </span>
        </li>
        <?php endforeach; ?>
    </ul>

    <div class="table-tools">
        <label for="impact-filter">Show</label>
        <select id="impact-filter">
            <option value="all">All changes (<?= count($mutations) ?>)</option>
            <option value="protein">Protein-changing only (<?= $proteinChanging ?>)</option>
        </select>
    </div>

    <div class="table-wrap">
        <table id="mutation-table">
            <thead>
                <tr>
                    <th scope="col">Gene</th>
                    <th scope="col">Chromosome</th>
                    <th scope="col">Position</th>
                    <th scope="col">Change</th>
                    <th scope="col">Type of change</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($mutations as $m): ?>
                <tr data-impact="<?= e($m['impact']) ?>">
                    <td>
                        <?php if (is_named_gene($m['gene_symbol'])): ?>
                        <strong class="gene"><?= e($m['gene_symbol']) ?></strong>
                        <?php else: ?>
                        <span class="mono"><?= e($m['gene_symbol']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($m['chromosome']) ?></td>
                    <td class="num"><?= e(number_format((float)$m['genomic_position'])) ?></td>
                    <td class="mono allele">
                        <span class="ref"><?= e($m['original_allele'] ?? '-') ?></span>
                        <span aria-label="changed to">→</span>
                        <span class="alt"><?= e($m['altered_allele'] ?? '-') ?></span>
                    </td>
                    <td><span class="chip impact-<?= e($m['impact']) ?>"><?= e(ucfirst($m['mutation_type'])) ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <button type="button" class="btn show-more" id="show-more" hidden>Show all changes</button>

    <details class="key">
        <summary>What do the colours mean?</summary>
        <ul>
            <li><span class="chip impact-high">Red</span> likely to stop the protein working</li>
            <li><span class="chip impact-moderate">Amber</span> changes one part of the protein</li>
            <li><span class="chip impact-low">Blue</span> unlikely to change the protein</li>
            <li><span class="chip impact-modifier">Grey</span> outside the part of the gene that makes the protein</li>
        </ul>
    </details>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
