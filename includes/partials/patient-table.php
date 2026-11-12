<?php
// Patients card shared by the oncologist and researcher dashboards.
// Needs: $patients, $patientTotal, $patientPage, $patientSearch, $patientCancer, $options
$patientPages = max(1, (int)ceil($patientTotal / DASHBOARD_PAGE_SIZE));
?>
<section class="card" aria-labelledby="patients-heading">
    <div class="card-header">
        <h2 id="patients-heading">Patients</h2>
        <form class="filters" method="get" action="#patients-heading">
            <?php foreach (['chromosome', 'gene', 'from', 'to', 'type', 'mpage', 'mutation', 'patient'] as $keep): ?>
                <?php if (isset($_GET[$keep]) && $_GET[$keep] !== ''): ?>
                <input type="hidden" name="<?= e($keep) ?>" value="<?= e($_GET[$keep]) ?>">
                <?php endif; ?>
            <?php endforeach; ?>
            <?php foreach ($_GET['genes'] ?? [] as $g): ?>
            <input type="hidden" name="genes[]" value="<?= e($g) ?>">
            <?php endforeach; ?>
            <div class="field">
                <label for="q">Search</label>
                <input id="q" name="q" value="<?= e($patientSearch) ?>" placeholder="Name or patient ID">
            </div>
            <div class="field">
                <label for="cancer">Cancer type</label>
                <select id="cancer" name="cancer">
                    <option value="">All</option>
                    <?php foreach ($options['cancer_types'] as $c): ?>
                    <option <?= $c === $patientCancer ? 'selected' : '' ?>><?= e($c) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn">Apply</button>
        </form>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th scope="col">Patient</th>
                    <th scope="col">Age / sex</th>
                    <th scope="col">Cancer type</th>
                    <th scope="col">Stage</th>
                    <th scope="col">Status</th>
                    <th scope="col">Mutations</th>
                    <th scope="col">Latest prediction</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($patients as $p): ?>
                <tr>
                    <td>
                        <a class="row-link" href="patient-detail.php?id=<?= e($p['patient_id']) ?>"><?= e($p['first_name'] . ' ' . $p['last_name']) ?></a>
                        <div class="muted small-text">ID <?= e($p['patient_id']) ?></div>
                    </td>
                    <td><?= e(age_from_birthdate($p['birthdate'])) ?> · <?= e(ucfirst($p['sex'])) ?></td>
                    <td>
                        <?php if ($p['cancer_name']): ?>
                        <span class="chip"><span class="dot dot-<?= e(strtolower($p['cancer_name'])) ?>" aria-hidden="true"></span><?= e($p['cancer_name']) ?></span>
                        <?php else: ?>
                        <span class="muted">None recorded</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $p['stage'] && $p['stage'] !== 'unknown' ? e($p['stage']) : '<span class="muted">Unknown</span>' ?></td>
                    <td>
                        <?php if ($p['status']): ?>
                        <span class="chip <?= $p['status'] === 'active' ? 'chip-success' : '' ?>"><?= e(ucfirst($p['status'])) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="num"><?= e($p['mutation_count']) ?></td>
                    <td>
                        <?php if ($p['prediction']): ?>
                            <?php $match = $p['prediction']['cancer'] === $p['cancer_name']; ?>
                            <span class="chip <?= $match ? 'chip-success' : 'chip-error' ?>"
                                  title="<?= $match ? 'Matches the diagnosis' : 'Does not match the diagnosis' ?>">
                                <?= e($p['prediction']['cancer']) ?> · <?= round($p['prediction']['probability'] * 100) ?>%
                            </span>
                        <?php else: ?>
                            <span class="muted">Not run</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$patients): ?>
                <tr><td colspan="7" class="empty">No patients match this search.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <nav class="pager" aria-label="Patient pages">
        <span class="muted num">
            <?php if ($patientTotal): ?>
            <?= ($patientPage - 1) * DASHBOARD_PAGE_SIZE + 1 ?>–<?= min($patientTotal, $patientPage * DASHBOARD_PAGE_SIZE) ?> of <?= $patientTotal ?>
            <?php else: ?>0 patients<?php endif; ?>
        </span>
        <span class="pager-links">
            <?php if ($patientPage > 1): ?>
            <a class="btn" href="<?= e(url_with(['ppage' => $patientPage - 1])) ?>#patients-heading">Previous</a>
            <?php endif; ?>
            <?php if ($patientPage < $patientPages): ?>
            <a class="btn" href="<?= e(url_with(['ppage' => $patientPage + 1])) ?>#patients-heading">Next</a>
            <?php endif; ?>
        </span>
    </nav>
</section>
