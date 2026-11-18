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
