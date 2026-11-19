<?php

require_once __DIR__ . '/../../includes/helpers.php';   // e(), query_with(), hidden_query_inputs()

// ---------------------------------------------------------------------------
// Signed-in user
// ---------------------------------------------------------------------------
// Setting $currentUser makes header.php skip its database lookup.
// TODO: delete this line and add a researcher-only check from the database.
$currentUser = ['user_id' => 0, 'name' => 'Dr Placeholder', 'role' => 'researcher'];

// ---------------------------------------------------------------------------
// Settings read by includes/header.php
// ---------------------------------------------------------------------------
$pageTitle  = 'Doctors';                      // browser tab: "Doctors | CancerInsight"
$activePage = 'research-doctors';             // highlights "Doctors" once it is in the side nav
$pageStyles = ['research-doctors.css'];

const DOCTORS_PER_PAGE = 20;

// ---------------------------------------------------------------------------
// Sortable columns
// ---------------------------------------------------------------------------
// URL key => [column label, default direction when first clicked].
// Numbers sort high-to-low first (most patients, best match % at the top);
// text sorts A-Z first. Only keys in this list are accepted from the URL, and
// later the key picks a fixed ORDER BY clause, so the URL never reaches SQL.
$columns = [
    'name'        => ['Doctor', 'asc'],
    'speciality'  => ['Speciality', 'asc'],
    'institution' => ['Institution', 'asc'],
    'patients'    => ['Patients', 'desc'],
    'reviews'     => ['Reviews done', 'desc'],
    'awaiting'    => ['Awaiting review', 'desc'],
    'doctor_pct'  => ['Initial diagnosis matched report', 'desc'],
    'model_pct'   => ['Model matched report', 'desc'],
];

// ---------------------------------------------------------------------------
// Read the GET parameters
// ---------------------------------------------------------------------------
$sort = array_key_exists($_GET['sort'] ?? '', $columns) ? $_GET['sort'] : 'name';
$dir  = ($_GET['dir'] ?? '') === 'desc' ? 'desc' : (($_GET['dir'] ?? '') === 'asc' ? 'asc' : $columns[$sort][1]);

$search      = trim($_GET['q'] ?? '');
$speciality  = trim($_GET['speciality'] ?? '');
$institution = trim($_GET['institution'] ?? '');
$doctorPage  = max(1, (int)($_GET['page'] ?? 1));   

// Dropdown options.
// TODO: SELECT DISTINCT speciality / institution FROM professionals
//       WHERE professional_type = 'oncologist' ORDER BY 1
// Until then only "All" is offered; a value not in the list is ignored.
$specialityOptions  = [];
$institutionOptions = [];
if (!in_array($speciality, $specialityOptions, true))   { $speciality = ''; }
if (!in_array($institution, $institutionOptions, true)) { $institution = ''; }

// ---------------------------------------------------------------------------
// Header cell for a sortable column
// ---------------------------------------------------------------------------
// Clicking the current sort column flips the direction; clicking another
// column sorts by it in its default direction. Sorting goes back to page 1.
$sortHeader = function (string $key) use ($columns, $sort, $dir): string {
    [$label, $defaultDir] = $columns[$key];
    $isCurrent = $key === $sort;
    $nextDir   = $isCurrent ? ($dir === 'asc' ? 'desc' : 'asc') : $defaultDir;
    $ariaSort  = $isCurrent ? ($dir === 'asc' ? 'ascending' : 'descending') : 'none';
    $arrow     = $isCurrent ? '<span class="sort-arrow" aria-hidden="true">' . ($dir === 'asc' ? '▲' : '▼') . '</span>' : '';

    return '<th scope="col" aria-sort="' . $ariaSort . '">'
         . '<a class="sort-link" href="' . e(query_with(['sort' => $key, 'dir' => $nextDir, 'page' => null])) . '#doctors-table-heading">'
         . e($label) . $arrow . '</a></th>';
};

require __DIR__ . '/../../includes/header.php';
?>

<!-- ===================================================================
     1. Header
     =================================================================== -->
<div class="page-header">
    <div>
        <h1>Doctors</h1>
        <!-- TODO: COUNT(*) of oncologists -->
        <p><span class="num">—</span> oncologists</p>
    </div>
</div>

<!-- ===================================================================
     2. Filters
     One GET form; it carries the current sort so filtering keeps the order.
     The chart and the table both follow these filters.
     =================================================================== -->
<form class="filters" method="get" action="">
    <?= hidden_query_inputs(['q', 'speciality', 'institution', 'page']) ?>
    <div class="field">
        <label for="q">Search by name</label>
        <input id="q" name="q" value="<?= e($search) ?>" placeholder="e.g. Chen">
    </div>
    <div class="field">
        <label for="speciality">Speciality</label>
        <select id="speciality" name="speciality">
            <option value="">All</option>
            <?php foreach ($specialityOptions as $s): ?>
            <option <?= $s === $speciality ? 'selected' : '' ?>><?= e($s) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="institution">Institution</label>
        <select id="institution" name="institution">
            <option value="">All</option>
            <?php foreach ($institutionOptions as $i): ?>
            <option <?= $i === $institution ? 'selected' : '' ?>><?= e($i) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn btn-primary">Filter</button>
    <a class="btn" href="<?= e(query_with(['q' => null, 'speciality' => null, 'institution' => null, 'page' => null])) ?>">Clear</a>
</form>

<!-- ===================================================================
     3. Doctor accuracy (dumbbell chart)
     One row per doctor: their initial-diagnosis accuracy and the model's
     accuracy on the SAME released cases, so the comparison is fair.
     =================================================================== -->
<section class="card" aria-labelledby="accuracy-heading">
    <div class="card-header">
        <h2 id="accuracy-heading">Initial diagnosis vs model</h2>
        <span class="muted small-text">Share of released reports each matched</span>
    </div>

    <div id="chart-doctor-accuracy" class="chart-slot" role="img"
         aria-label="For each doctor, how often their initial diagnosis and the model's top prediction matched the final report">
        <!-- Fallback table. TODO: one row per doctor with at least one released report. -->
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th scope="col">Doctor</th>
                        <th scope="col">Initial diagnosis</th>
                        <th scope="col">Model</th>
                        <th scope="col">Released reports</th>
                    </tr>
                </thead>
                <tbody>
                    <?php for ($i = 0; $i < 3; $i++): ?>
                    <tr>
                        <td><a href="research-doctor.php?id=1">Dr —</a></td>
                        <td class="num">—</td>
                        <td class="num">—</td>
                        <td class="num">—</td>
                    </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>
    </div>
    <!-- Shape: {"doctors":[{"id":7,"name":"Dr Chen","doctor_pct":78.0,"model_pct":84.5,"n":31}, ...]}
         Empty until real data exists. -->
    <script type="application/json" id="chart-doctor-accuracy-data"><?= json_encode(
        ['doctors' => []],
        JSON_HEX_TAG | JSON_HEX_AMP
    ) ?></script>
    <p class="muted small-text chart-note">Doctors with fewer than 5 released reports are shown faded: too few cases to compare.</p>
</section>

<!-- ===================================================================
     4. Doctors table
     Click a header to sort; click again to reverse. 20 per page.
     =================================================================== -->
<section class="card" aria-labelledby="doctors-table-heading">
    <div class="card-header">
        <h2 id="doctors-table-heading">All doctors</h2>
    </div>

    <div class="table-wrap">
        <table class="doctors-table">
            <thead>
                <tr>
                    <?php foreach (array_keys($columns) as $key): ?>
                    <?= $sortHeader($key) ?>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <!-- TODO: foreach over DOCTORS_PER_PAGE rows, ORDER BY the chosen column.
                     Percentages show "—" when the doctor has no released reports yet.
                     Empty state: <tr><td colspan="8" class="empty">No doctors match these filters.</td></tr> -->
                <?php for ($i = 0; $i < 4; $i++): ?>
                <tr>
                    <td><a class="row-link" href="research-doctor.php?id=1">Dr —</a></td>
                    <td>—</td>
                    <td>—</td>
                    <td class="num">—</td>
                    <td class="num">—</td>
                    <td class="num">—</td>
                    <td class="num">—</td>
                    <td class="num">—</td>
                </tr>
                <?php endfor; ?>
            </tbody>
        </table>
    </div>

    <!-- Pager: changes ?page and keeps the filters and sort.
         TODO: real totals, and show Next only when page < ceil(total / DOCTORS_PER_PAGE). -->
    <nav class="pager" aria-label="Doctor pages">
        <span class="muted num">— of — doctors</span>
        <span class="pager-links">
            <?php if ($doctorPage > 1): ?>
            <a class="btn" href="<?= e(query_with(['page' => $doctorPage - 1])) ?>#doctors-table-heading">Previous</a>
            <?php endif; ?>
            <a class="btn" href="<?= e(query_with(['page' => $doctorPage + 1])) ?>#doctors-table-heading">Next</a>
        </span>
    </nav>
</section>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
