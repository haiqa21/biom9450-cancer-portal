<?php
/*
 * Shared page header: <head>, top bar and side navigation.
 * Not used by pages without the app shell (login, forgot password, logout).
 *
 * Usage, at the top of a page in public/pages/:
 *
 *     $pageTitle  = 'My results';                 // browser tab title
 *     $activePage = 'patient-dashboard';          // highlights the matching nav link
 *     $pageStyles = ['patient-dashboard.css'];    // optional, extra files in styles/
 *     $pageScripts = ['patient-dashboard.js'];    // optional, extra files in js/
 *     require __DIR__ . '/../../includes/header.php';
 *     ... page content ...
 *     require __DIR__ . '/../../includes/footer.php';
 *
 * The header works out who is signed in from $_SESSION and redirects to
 * login.php if nobody is. A page can set $currentUser itself first
 * (['user_id', 'name', 'role']) to skip the lookup, e.g. for placeholder data.
 */

require_once __DIR__ . '/helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($currentUser)) {
    if (empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit();
    }

    require_once __DIR__ . '/db.php';

    if ($_SESSION['role'] === 'patient') {
        $stmt = $pdo->prepare('SELECT first_name, last_name FROM patients WHERE user_id = ?');
        $stmt->execute([$_SESSION['user_id']]);
        $row = $stmt->fetch();
        $currentUser = [
            'user_id' => $_SESSION['user_id'],
            'name'    => $row ? $row['first_name'] . ' ' . $row['last_name'] : 'Patient',
            'role'    => 'patient',
        ];
    } else {
        $stmt = $pdo->prepare('SELECT first_name, last_name, professional_type FROM professionals WHERE user_id = ?');
        $stmt->execute([$_SESSION['user_id']]);
        $row = $stmt->fetch();
        $currentUser = [
            'user_id' => $_SESSION['user_id'],
            'name'    => $row ? 'Dr ' . $row['first_name'] . ' ' . $row['last_name'] : 'Professional',
            'role'    => $row['professional_type'] ?? 'oncologist',   // 'oncologist' or 'researcher'
        ];
    }
}

$pageTitle   = $pageTitle   ?? 'CancerInsight';
$activePage  = $activePage  ?? '';
$pageStyles  = $pageStyles  ?? [];
$pageScripts = $pageScripts ?? [];

// Side navigation for each role: [page file without .php, label, icon]
$navItems = [
    'patient' => [
        ['patient-dashboard', 'My results', 'user'],
    ],
    'oncologist' => [
        ['oncologist-dashboard', 'Dashboard', 'home'],
        ['new-patient', 'New patient', 'plus'],
        ['prediction', 'Predictions', 'target'],
    ],
    'researcher' => [
        ['researcher-dashboard', 'Dashboard', 'chart'],
        ['new-patient', 'New patient', 'plus'],
        ['prediction', 'Predictions', 'target'],
    ],
][$currentUser['role']] ?? [];

$icons = [
    'user'   => '<circle cx="12" cy="8" r="4"/><path d="M4 21c.8-4 4-6.5 8-6.5s7.2 2.5 8 6.5"/>',
    'home'   => '<path d="M3 11l9-7 9 7v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>',
    'plus'   => '<circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/>',
    'target' => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/>',
    'chart'  => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
    'logout' => '<path d="M15 4h4a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1h-4M10 17l5-5-5-5M15 12H3"/>',
];
$icon = function (string $name) use ($icons): string {
    return '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" '
         . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $icons[$name] . '</svg>';
};

$initials = implode('', array_map(
    fn($w) => strtoupper($w[0]),
    array_slice(explode(' ', str_replace('Dr ', '', $currentUser['name'])), 0, 2)
));
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= e($pageTitle) ?> | CancerInsight</title>

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

        <link rel="stylesheet" href="styles/app.css">
        <?php foreach ($pageStyles as $style): ?>
        <link rel="stylesheet" href="styles/<?= e($style) ?>">
        <?php endforeach; ?>
        <?php foreach ($pageScripts as $script): ?>
        <script src="js/<?= e($script) ?>" defer></script>
        <?php endforeach; ?>
    </head>
    <body>
        <a class="skip-link" href="#main">Skip to content</a>

        <header class="app-header">
            <a class="app-brand" href="<?= e(($navItems[0][0] ?? 'index') . '.php') ?>">
                <span class="brand-mark" aria-hidden="true"></span>
                <span>CancerInsight</span>
            </a>

            <div class="app-user">
                <span class="role-pill"><?= e(ucfirst($currentUser['role'])) ?></span>
                <span class="user-name"><?= e($currentUser['name']) ?></span>
                <span class="avatar" aria-hidden="true"><?= e($initials) ?></span>
            </div>
        </header>

        <div class="app-shell">
            <nav class="app-nav" aria-label="Main">
                <?php foreach ($navItems as [$page, $label, $iconName]): ?>
                <a href="<?= e($page) ?>.php" class="nav-link<?= $page === $activePage ? ' active' : '' ?>"
                   <?= $page === $activePage ? 'aria-current="page"' : '' ?>>
                    <?= $icon($iconName) ?>
                    <span><?= e($label) ?></span>
                </a>
                <?php endforeach; ?>

                <span class="nav-spacer"></span>

                <a href="logout.php" class="nav-link">
                    <?= $icon('logout') ?>
                    <span>Log out</span>
                </a>
            </nav>

            <main id="main" class="app-main">
