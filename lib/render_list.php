<?php
require_once __DIR__ . '/tree.php';

/**
 * Render an alphabetical index of all individuals, grouped by surname.
 * Each surname group is a <details> block; expanded by default for the
 * letter the user's `?letter=` query selects (else first letter).
 */
function stam_render_alpha_index(?string $selected_letter = null): string {
    $t = stam_load_tree();
    $by = $t['indexes']['by_surname'] ?? [];
    ksort($by, SORT_STRING);

    if ($selected_letter !== null) {
        $selected_letter = strtoupper(substr($selected_letter, 0, 1));
    }

    $h = '<div class="tree-list">';

    // Letter jump-bar
    $letters = [];
    foreach (array_keys($by) as $sn) {
        $L = strtoupper(substr($sn, 0, 1));
        $letters[$L] = true;
    }
    $h .= '<p class="meta">Spring naar: ';
    foreach (range('A', 'Z') as $L) {
        if (isset($letters[$L])) {
            $h .= '<a href="?letter=' . $L . '#letter-' . $L . '">' . $L . '</a> ';
        } else {
            $h .= '<span style="color:var(--muted)">' . $L . '</span> ';
        }
    }
    $h .= '</p>';

    $prev_letter = null;
    foreach ($by as $surname => $ids) {
        $L = strtoupper(substr($surname, 0, 1));
        if ($L !== $prev_letter) {
            $h .= '<h2 id="letter-' . $L . '">' . htmlspecialchars($L) . '</h2>';
            $prev_letter = $L;
        }
        $open = ($selected_letter === $L) ? ' open' : '';
        $h .= '<details' . $open . '><summary>'
            . htmlspecialchars($surname) . ' <span class="meta">(' . count($ids) . ')</span>'
            . '</summary><ul>';
        // Sort by display name within surname
        usort($ids, function($a, $b) {
            $na = stam_individual($a)['name']['display'] ?? '';
            $nb = stam_individual($b)['name']['display'] ?? '';
            return strcmp($na, $nb);
        });
        foreach ($ids as $iid) {
            $ind = stam_individual($iid);
            if (!$ind) continue;
            $h .= '<li><a href="persoon.php?id=' . htmlspecialchars($iid) . '">'
                . htmlspecialchars($ind['name']['display'] ?? 'Onbekend') . '</a> '
                . '<span class="meta">' . htmlspecialchars(stam_lifespan($ind)) . '</span></li>';
        }
        $h .= '</ul></details>';
    }

    $h .= '</div>';
    return $h;
}

/**
 * Render a descendant tree centered on $root_id as nested <details>.
 * Used as the mobile/accessibility fallback to the hourglass.
 */
function stam_render_descendant_list(string $root_id, int $depth = 5): string {
    $ind = stam_individual($root_id);
    if (!$ind) return '';
    return '<div class="tree-list">' . _stam_dlist($ind, $depth) . '</div>';
}

function _stam_dlist(array $ind, int $depth): string {
    $name  = htmlspecialchars($ind['name']['display'] ?? 'Onbekend');
    $life  = htmlspecialchars(stam_lifespan($ind));
    $kids  = $depth > 0 ? array_filter(array_map('stam_individual', stam_children_of($ind))) : [];
    if (!$kids) {
        return '<div><a href="persoon.php?id=' . htmlspecialchars($ind['id']) . '">'
             . $name . '</a> <span class="meta">' . $life . '</span></div>';
    }
    $h = '<details open><summary>'
       . '<a href="persoon.php?id=' . htmlspecialchars($ind['id']) . '">' . $name . '</a> '
       . '<span class="meta">' . $life . '</span></summary>';
    foreach ($kids as $k) $h .= _stam_dlist($k, $depth - 1);
    $h .= '</details>';
    return $h;
}
