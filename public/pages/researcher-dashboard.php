<?php
session_start();
require_once __DIR__ . '/../../includes/dashboard-data.php';

$src = dashboard_source();
$currentUser = require_professional($src, 'researcher');

$options = dashboard_filter_options($src);
$stats = dashboard_stats($src);

// Genes chosen for the chart (defaults to the 8 most frequently mutated named genes)
$geneOptions = dashboard_gene_options($src);
$validGenes = array_column($geneOptions, 'gene_symbol');
$selectedGenes = array_values(array_intersect((array)($_GET['genes'] ?? []), $validGenes));
if (!isset($_GET['genes'])) {
    $selectedGenes = array_slice($validGenes, 0, 8);
}
$geneCounts = dashboard_gene_counts($src, $selectedGenes);
uasort($geneCounts, fn($a, $b) => array_sum($b) <=> array_sum($a));
$maxGeneTotal = $geneCounts ? max(array_map('array_sum', $geneCounts)) : 0;

$patientSearch = trim($_GET['q'] ?? '');
$patientCancer = trim($_GET['cancer'] ?? '');
$patientPage = max(1, (int)($_GET['ppage'] ?? 1));
[$patients, $patientTotal] = dashboard_patients($src, $patientSearch, $patientCancer, $patientPage);

$filters = dashboard_mutation_filters($_GET);
$mutationPage = max(1, (int)($_GET['mpage'] ?? 1));
[$mutations, $mutationTotal] = dashboard_mutations($src, $filters, $mutationPage);

$selectedMutation = (int)($_GET['mutation'] ?? 0);
$selectedPatient = (int)($_GET['patient'] ?? 0);
$related = $selectedMutation ? dashboard_related($src, $selectedMutation, $selectedPatient) : null;

dashboard_audit($src, (int)$currentUser['user_id'], 'view_analytics', 'genes=' . implode(',', $selectedGenes));

$cancerTypes = $options['cancer_types'];

$pageTitle  = 'Dashboard';
$activePage = 'researcher-dashboard';
$pageStyles = ['dashboard.css'];
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1>Welcome back, Dr <?= e($currentUser['last_name']) ?></h1>
    </div>
    <a class="btn btn-primary" href="new-patient.php">New patient</a>
</div>

<div class="tiles">
    <div class="tile"><span class="tile-value"><?= number_format($stats['patients']) ?></span><span class="tile-label">Patients</span></div>
    <div class="tile"><span class="tile-value"><?= number_format($stats['samples']) ?></span><span class="tile-label">Samples</span></div>
    <div class="tile"><span class="tile-value"><?= number_format($stats['patient_mutations']) ?></span><span class="tile-label">Patient mutations</span></div>
    <div class="tile"><span class="tile-value"><?= number_format($stats['awaiting_feedback']) ?></span><span class="tile-label">Predictions without feedback</span></div>
</div>

<section class="card" aria-labelledby="genes-heading">
    <div class="card-header">
        <h2 id="genes-heading">Mutations in selected genes</h2>
        <span class="muted small-text">Patients with at least one mutation in each gene</span>
    </div>

    <form method="get" action="#genes-heading">
        <?php foreach (['q', 'cancer', 'ppage', 'chromosome', 'gene', 'from', 'to', 'type', 'mpage', 'mutation', 'patient'] as $keep): ?>
            <?php if (isset($_GET[$keep]) && $_GET[$keep] !== ''): ?>
            <input type="hidden" name="<?= e($keep) ?>" value="<?= e($_GET[$keep]) ?>">
            <?php endif; ?>
        <?php endforeach; ?>
        <input type="hidden" name="genes[]" value="">

        <fieldset class="gene-picker">
            <legend>Genes</legend>
            <?php foreach ($geneOptions as $g): ?>
            <label>
                <input type="checkbox" name="genes[]" value="<?= e($g['gene_symbol']) ?>"
                       <?= in_array($g['gene_symbol'], $selectedGenes, true) ? 'checked' : '' ?>>
                <?= e($g['gene_symbol']) ?>
            </label>
            <?php endforeach; ?>
        </fieldset>
        <div class="gene-picker-actions">
            <button type="submit" class="btn btn-primary">Update chart</button>
        </div>
    </form>

    <div class="legend" aria-hidden="true">
        <?php foreach ($cancerTypes as $c): ?>
        <span><span class="dot dot-<?= e(strtolower($c)) ?>"></span><?= e($c) ?></span>
        <?php endforeach; ?>
    </div>

    <!-- Chart slot for the visualisation teammate: draw into #gene-chart using the JSON in #gene-chart-data.
         The bars below are a plain HTML fallback and can be replaced. -->
    <div id="gene-chart" class="gene-bars" role="img"
         aria-label="Number of patients with a mutation in each selected gene, split by cancer type">
        <?php foreach ($geneCounts as $gene => $byCancer): ?>
        <?php $total = array_sum($byCancer); ?>
        <div class="gene-bar">
            <span class="gene"><?= e($gene) ?></span>
            <span class="track">
                <?php foreach ($cancerTypes as $c): ?>
                    <?php if (!empty($byCancer[$c])): ?>
                    <span class="seg seg-<?= e(strtolower($c)) ?>"
                          style="width: calc(<?= round($byCancer[$c] / $maxGeneTotal * 100, 2) ?>% - 2px)"
                          title="<?= e($gene) ?>, <?= e($c) ?>: <?= $byCancer[$c] ?> patients"></span>
                    <?php endif; ?>
                <?php endforeach; ?>
            </span>
            <span class="total"><?= $total ?></span>
        </div>
        <?php endforeach; ?>
        <?php if (!$geneCounts): ?>
        <p class="muted">Select at least one gene.</p>
        <?php endif; ?>
    </div>
    <script type="application/json" id="gene-chart-data"><?= json_encode(
        ['cancer_types' => $cancerTypes, 'genes' => $geneCounts],
        JSON_HEX_TAG | JSON_HEX_AMP
    ) ?></script>
</section>

<?php require __DIR__ . '/../../includes/partials/patient-table.php'; ?>

<?php require __DIR__ . '/../../includes/partials/mutation-explorer.php'; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
