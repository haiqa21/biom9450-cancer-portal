<?php
// Mutation explorer shared by the oncologist and researcher dashboards.
// Needs: $mutations, $mutationTotal, $mutationPage, $filters, $options, $related, $selectedMutation, $selectedPatient
$mutationPages = max(1, (int)ceil($mutationTotal / DASHBOARD_PAGE_SIZE));

$relatedList = function (array $people) {
    if (!$people) {
        return '<p class="muted">No other patients.</p>';
    }
    $html = '<ul class="people">';
    foreach (array_slice($people, 0, 8) as $p) {
        $html .= '<li><a href="patient-detail.php?id=' . e($p['patient_id']) . '">' . e($p['first_name'] . ' ' . $p['last_name']) . '</a>'
               . '<span class="muted small-text">ID ' . e($p['patient_id']) . ' · ' . e(age_from_birthdate($p['birthdate'])) . ' · ' . e(ucfirst($p['sex'])) . '</span>'
               . ($p['cancer_name'] ? '<span class="chip"><span class="dot dot-' . e(strtolower($p['cancer_name'])) . '" aria-hidden="true"></span>' . e($p['cancer_name']) . '</span>' : '')
               . '</li>';
    }
    $html .= '</ul>';
    if (count($people) > 8) {
        $html .= '<p class="muted small-text">and ' . (count($people) - 8) . ' more</p>';
    }
    return $html;
};

$variantName = function (array $m): string {
    $ref = $m['original_allele'] ?? '-';
    $alt = $m['altered_allele'] ?? '-';
    return 'chr' . $m['chromosome'] . ':' . number_format((float)$m['genomic_position']) . ' ' . $ref . '>' . $alt;
};
?>
<section class="card" aria-labelledby="explorer-heading">
    <div class="card-header">
        <h2 id="explorer-heading">Mutation explorer</h2>
        <span class="muted small-text num"><?= number_format($mutationTotal) ?> matching patient mutations</span>
    </div>

    <form class="filters explorer-filters" method="get" action="#explorer-heading">
        <?php foreach (['q', 'cancer', 'ppage'] as $keep): ?>
            <?php if (isset($_GET[$keep]) && $_GET[$keep] !== ''): ?>
            <input type="hidden" name="<?= e($keep) ?>" value="<?= e($_GET[$keep]) ?>">
            <?php endif; ?>
        <?php endforeach; ?>
        <?php foreach ($_GET['genes'] ?? [] as $g): ?>
        <input type="hidden" name="genes[]" value="<?= e($g) ?>">
        <?php endforeach; ?>

        <div class="field">
            <label for="chromosome">Chromosome</label>
            <select id="chromosome" name="chromosome">
                <option value="">All</option>
                <?php foreach ($options['chromosomes'] as $c): ?>
                <option <?= $c === $filters['chromosome'] ? 'selected' : '' ?>><?= e($c) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="gene">Gene</label>
            <input id="gene" name="gene" list="gene-options" value="<?= e($filters['gene']) ?>" placeholder="e.g. TP53">
            <datalist id="gene-options">
                <?php foreach ($options['named_genes'] as $g): ?>
                <option value="<?= e($g) ?>"></option>
                <?php endforeach; ?>
            </datalist>
        </div>
        <div class="field">
            <label for="from">Position from</label>
            <input id="from" name="from" inputmode="numeric" value="<?= e($filters['from']) ?>" placeholder="7570000">
        </div>
        <div class="field">
            <label for="to">to</label>
            <input id="to" name="to" inputmode="numeric" value="<?= e($filters['to']) ?>" placeholder="7590000">
        </div>
        <div class="field">
            <label for="type">Mutation type</label>
            <select id="type" name="type">
                <option value="">Any</option>
                <?php foreach ($options['types'] as $t): ?>
                <option value="<?= e($t) ?>" <?= $t === $filters['type'] ? 'selected' : '' ?>><?= e(ucfirst($t)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Filter</button>
        <a class="btn" href="<?= e(url_with(['chromosome' => null, 'gene' => null, 'from' => null, 'to' => null, 'type' => null, 'mpage' => null, 'mutation' => null, 'patient' => null])) ?>#explorer-heading">Clear</a>
    </form>

    <div class="explorer<?= $related ? '' : ' explorer-full' ?>">
        <div class="explorer-table">
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">Mutation</th>
                            <th scope="col">Gene</th>
                            <th scope="col">Type</th>
                            <th scope="col">Patient</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($mutations as $m): ?>
                        <?php $isSelected = (int)$m['mutation_id'] === $selectedMutation && (int)$m['patient_id'] === $selectedPatient; ?>
                        <tr class="<?= $isSelected ? 'selected' : '' ?>">
                            <td>
                                <a class="mono row-link" href="<?= e(url_with(['mutation' => $m['mutation_id'], 'patient' => $m['patient_id']])) ?>#explorer-heading"
                                   <?= $isSelected ? 'aria-current="true"' : '' ?>><?= e($variantName($m)) ?></a>
                            </td>
                            <td>
                                <?php if (is_named_gene($m['gene_symbol'])): ?>
                                <strong class="gene"><?= e($m['gene_symbol']) ?></strong>
                                <?php else: ?>
                                <span class="mono"><?= e($m['gene_symbol']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><span class="chip impact-<?= e(mutation_impact($m['mutation_type'])) ?>"><?= e(ucfirst($m['mutation_type'])) ?></span></td>
                            <td><a href="patient-detail.php?id=<?= e($m['patient_id']) ?>"><?= e($m['first_name'] . ' ' . $m['last_name']) ?></a></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$mutations): ?>
                        <tr><td colspan="4" class="empty">No mutations match these filters.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <nav class="pager" aria-label="Mutation pages">
                <span class="muted num">
                    <?php if ($mutationTotal): ?>
                    <?= number_format(($mutationPage - 1) * DASHBOARD_PAGE_SIZE + 1) ?>–<?= number_format(min($mutationTotal, $mutationPage * DASHBOARD_PAGE_SIZE)) ?> of <?= number_format($mutationTotal) ?>
                    <?php else: ?>0 mutations<?php endif; ?>
                </span>
                <span class="pager-links">
                    <?php if ($mutationPage > 1): ?>
                    <a class="btn" href="<?= e(url_with(['mpage' => $mutationPage - 1])) ?>#explorer-heading">Previous</a>
                    <?php endif; ?>
                    <?php if ($mutationPage < $mutationPages): ?>
                    <a class="btn" href="<?= e(url_with(['mpage' => $mutationPage + 1])) ?>#explorer-heading">Next</a>
                    <?php endif; ?>
                </span>
            </nav>
        </div>

        <?php if ($related): ?>
        <aside class="related" aria-label="Related patients">
            <h3 class="mono"><?= e($variantName($related['mutation'])) ?></h3>
            <p class="muted small-text">
                <?= is_named_gene($related['mutation']['gene_symbol']) ? '<em>' . e($related['mutation']['gene_symbol']) . '</em>' : e($related['mutation']['gene_symbol']) ?>
                · <?= e($related['mutation']['mutation_type']) ?>
            </p>

            <h3>Same mutation (<?= count($related['same_mutation']) ?>)</h3>
            <?= $relatedList($related['same_mutation']) ?>

            <h3>Same gene (<?= count($related['same_gene']) ?>)</h3>
            <?= $relatedList($related['same_gene']) ?>
        </aside>
        <?php endif; ?>
    </div>
</section>
