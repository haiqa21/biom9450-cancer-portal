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
 */
function mutation_impact(string $type): string
{
    $type = strtolower($type);
    $high = ['nonsense', 'frameshift', 'splice acceptor', 'splice donor', 'start lost', 'stop lost'];
    $moderate = ['missense', 'inframe deletion', 'inframe insertion'];
    $low = ['synonymous', 'splice region', "5' utr start gain"];

    if (in_array($type, $high, true)) {
        return 'high';
    }
    if (in_array($type, $moderate, true)) {
        return 'moderate';
    }
    if (in_array($type, $low, true)) {
        return 'low';
    }
    return 'modifier';
}

/** True for HGNC symbols like TP53, false for Ensembl IDs used when no symbol is known. */
function is_named_gene(string $symbol): bool
{
    return strpos($symbol, 'ENSG') !== 0;
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
 * Fixed lists shared by the researcher pages, so every page uses the same
 * order and the same labels. Keys are the values stored in the database.
 */

/** The six cancer types in the one order every chart and legend uses (colours: --cancer-* in app.css). */
const CANCER_TYPES = ['Breast', 'Brain', 'Liver', 'Prostate', 'Blood', 'Pancreas'];

/** predictions.status => label, in workflow order (chip colours: .status-* in app.css). */
const PREDICTION_STATUSES = [
    'awaiting_diagnosis' => 'Awaiting diagnosis',
    'pending_review'     => 'Pending review',
    'reviewed'           => 'Reviewed',
    'report_draft'       => 'Report draft',
    'released'           => 'Released',
];

/** feedback.agreement => label: how a doctor or researcher rated the model (chip colours: .agreement-* in app.css). */
const AGREEMENT_OPTIONS = [
    'agree'    => 'Agree',
    'partly'   => 'Partly agree',
    'disagree' => 'Disagree',
];

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

/** Reasons a reviewer can give for flagging a sample's data quality (feedback.data_quality_note). */
const DATA_QUALITY_REASONS = [
    'low_quality'   => 'Low sample quality',
    'few_mutations' => 'Too few mutations',
    'contamination' => 'Possible contamination',
    'other'         => 'Other',
];

/** Longest feedback comment accepted, in characters (the form's counter uses the same number). */
const FEEDBACK_COMMENT_MAX = 2000;
