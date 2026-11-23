<?php
// Small helpers shared by every page.

/** Escape text for HTML output. Use on every value that comes from the database or the user. */
function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/** 2026-11-12 -> 12 Nov 2026 */
function format_date(?string $date): string
{
    if (!$date) {
        return '';
    }
    return date('j M Y', strtotime($date));
}

/** Whole years between a birthdate and today. */
function age_from_birthdate(string $birthdate): int
{
    return (new DateTime($birthdate))->diff(new DateTime('today'))->y;
}

/**
 * Group a mutation_type into an impact level, used for the coloured chips.
 * high: likely to disrupt the protein; moderate: changes the protein;
 * low: unlikely to change the protein; modifier: outside the coding sequence.
 *
 * Accepts both the TCGA/MAF Variant_Classification values stored by the import
 * (Missense_Mutation, Frame_Shift_Del, 3'UTR ...) and the older lowercase names
 * used by the placeholder data (missense, frameshift ...).
 */
function mutation_impact(string $type): string
{
    $type = strtolower(str_replace('_', ' ', $type));
    $high = ['nonsense', 'nonsense mutation', 'frameshift', 'frame shift del', 'frame shift ins',
             'splice site', 'splice acceptor', 'splice donor', 'translation start site',
             'nonstop mutation', 'start lost', 'stop lost'];
    $moderate = ['missense', 'missense mutation', 'in frame del', 'in frame ins',
                 'inframe deletion', 'inframe insertion'];
    $low = ['silent', 'synonymous', 'splice region', "5' utr start gain"];

    if (in_array($type, $high, true)) {
        return 'high';
    }
    if (in_array($type, $moderate, true)) {
        return 'moderate';
    }
    if (in_array($type, $low, true)) {
        return 'low';
    }
    return 'modifier';     // 3'UTR, 5'UTR, Intron, RNA, flanking regions ...
}

/** Readable mutation type: Missense_Mutation -> Missense, Frame_Shift_Del -> Frameshift deletion. */
function mutation_label(string $type): string
{
    $labels = [
        'Missense_Mutation' => 'Missense', 'Nonsense_Mutation' => 'Nonsense', 'Nonstop_Mutation' => 'Nonstop',
        'Frame_Shift_Del' => 'Frameshift deletion', 'Frame_Shift_Ins' => 'Frameshift insertion',
        'In_Frame_Del' => 'In-frame deletion', 'In_Frame_Ins' => 'In-frame insertion',
        'Splice_Site' => 'Splice site', 'Translation_Start_Site' => 'Start site', 'Silent' => 'Silent',
        "3'UTR" => "3' UTR", "5'UTR" => "5' UTR", "3'Flank" => "3' flank", "5'Flank" => "5' flank",
        'Intron' => 'Intron', 'RNA' => 'Non-coding RNA', 'IGR' => 'Intergenic',
    ];
    return $labels[$type] ?? ucfirst(str_replace('_', ' ', $type));
}

/** True for gene symbols like TP53; false for placeholders such as "?", "Unknown" or Ensembl IDs. */
function is_named_gene(string $symbol): bool
{
    return $symbol !== '' && $symbol !== '?' && $symbol !== 'Unknown' && strpos($symbol, 'ENSG') !== 0;
}

/*
 * URL helpers for pages built from several GET forms (filters, sorting, pagers).
 * Each form must keep the other forms' values, or using one would reset the rest.
 * Named differently from url_with() in dashboard-data.php so both files can be
 * included on the same page.
 */

/** Current query string with some parameters changed; null or '' removes one. Returns "?a=1&b=2". */
function query_with(array $changes): string
{
    $params = array_merge($_GET, $changes);
    $params = array_filter($params, fn($v) => $v !== null && $v !== '');
    return '?' . http_build_query($params);
}

/** Hidden inputs carrying every current GET value except $except, for a GET form to keep the page's other state. */
function hidden_query_inputs(array $except): string
{
    $html = '';
    foreach ($_GET as $key => $value) {
        if (in_array($key, $except, true)) {
            continue;
        }
        $name = is_array($value) ? $key . '[]' : $key;
        foreach ((array)$value as $v) {
            $html .= '<input type="hidden" name="' . e($name) . '" value="' . e($v) . '">';
        }
    }
    return $html;
}

/** 2026-03-14 -> Mar 2026. Researcher pages show month + year only, never a full date. */
function format_month(?string $date): string
{
    if (!$date) {
        return '';
    }
    return date('M Y', strtotime($date));
}

/*
 * Lists used by the researcher pages (cancer types, review statuses, models) live in
 * includes/research.php, because they come from the database.
 */

/**
 * The session's CSRF token, created on first use. Every POST form includes it
 * as a hidden field and the handler compares it with hash_equals(), so another
 * site can't submit the form on a signed-in user's behalf.
 */
function csrf_token(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Longest feedback comment accepted, in characters (the form's counter uses the same number). */
const FEEDBACK_COMMENT_MAX = 2000;
