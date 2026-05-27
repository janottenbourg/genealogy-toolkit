<?php
/*
 * Loads tree.json once per request into a static cache.
 * Provides lookup helpers used by every render path.
 */

function stam_load_tree(): array {
    static $tree = null;
    if ($tree !== null) return $tree;
    $path = __DIR__ . '/../tree.json';
    if (!is_readable($path)) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        echo "<!DOCTYPE html><meta charset=\"UTF-8\"><title>Stamboom · niet beschikbaar</title>"
           . "<p style=\"font-family:sans-serif;padding:40px\">Stamboom is even niet beschikbaar — "
           . "probeer over een paar minuten opnieuw.</p>";
        exit;
    }
    $tree = json_decode(file_get_contents($path), true);
    if (!is_array($tree)) {
        error_log("stamboom: tree.json failed to decode");
        http_response_code(500);
        exit;
    }
    return $tree;
}

function stam_individual(string $id): ?array {
    $t = stam_load_tree();
    return $t['individuals'][$id] ?? null;
}

function stam_family(string $id): ?array {
    $t = stam_load_tree();
    return $t['families'][$id] ?? null;
}

function stam_meta(): array {
    return stam_load_tree()['meta'];
}

/** Dutch display string for a date object {iso, raw, place}. Returns '—' for null. */
function stam_fmt_date(?array $d): string {
    if (!$d) return '—';
    $raw = $d['raw'] ?? null;
    if (!$raw) return '—';
    // Translate English month names from GEDCOM raw to Dutch.
    $map = [
        'JAN' => 'jan', 'FEB' => 'feb', 'MAR' => 'mrt', 'APR' => 'apr',
        'MAY' => 'mei', 'JUN' => 'jun', 'JUL' => 'jul', 'AUG' => 'aug',
        'SEP' => 'sep', 'OCT' => 'okt', 'NOV' => 'nov', 'DEC' => 'dec',
        'ABT' => '~',   'BEF' => 'vóór', 'AFT' => 'na', 'EST' => '~',
        'CAL' => '~',   'BET' => 'tussen', 'AND' => 'en',
    ];
    return strtr($raw, $map);
}

/** "Désiré Janssens (1910–1992)" — life span string. */
function stam_lifespan(array $ind): string {
    $b = $ind['birth']['iso'] ?? null;
    $d = $ind['death']['iso'] ?? null;
    $by = $b ? substr($b, 0, 4) : ($ind['birth']['raw'] ?? '');
    $dy = $d ? substr($d, 0, 4) : ($ind['death']['raw'] ?? '');
    if (!$by && !$dy) return '';
    return trim($by . '–' . $dy, '–');
}

/** Children of an individual via their spouse_families. Returns array of INDI ids. */
function stam_children_of(array $ind): array {
    $out = [];
    foreach ($ind['spouse_families'] ?? [] as $fid) {
        $f = stam_family($fid);
        if ($f) foreach ($f['children'] ?? [] as $cid) $out[] = $cid;
    }
    return $out;
}

/** Parents (husband + wife) of an individual via parents_family. */
function stam_parents_of(array $ind): array {
    if (empty($ind['parents_family'])) return [];
    $f = stam_family($ind['parents_family']);
    if (!$f) return [];
    return array_filter([$f['husband'] ?? null, $f['wife'] ?? null]);
}
