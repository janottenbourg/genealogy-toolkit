<?php
require_once __DIR__ . '/tree.php';

/**
 * Render an HTML fragment for the hourglass view centered on $focal_id.
 *
 *   $gen_up   — ancestor generations to show (0..5, default 3)
 *   $gen_down — descendant generations to show (0..5, default 3)
 *
 * Returns a string (does not echo).
 */
function stam_render_hourglass(string $focal_id, int $gen_up = 3, int $gen_down = 3): string {
    $gen_up   = max(0, min(5, $gen_up));
    $gen_down = max(0, min(5, $gen_down));

    $focal = stam_individual($focal_id);
    if (!$focal) return '';

    // Collect ancestor generations: $anc[1] = parents, $anc[2] = grandparents, …
    // Slot count per generation is fixed at 2^k so the grid layout is stable.
    $anc = [];
    $anc[0] = [$focal_id];
    for ($g = 1; $g <= $gen_up; $g++) {
        $prev = $anc[$g - 1];
        $row  = [];
        foreach ($prev as $cid) {
            if ($cid === null) {
                $row[] = null; $row[] = null;
                continue;
            }
            $ind = stam_individual($cid);
            if (!$ind || empty($ind['parents_family'])) {
                $row[] = null; $row[] = null;
                continue;
            }
            $fam = stam_family($ind['parents_family']);
            $row[] = $fam['husband'] ?? null;
            $row[] = $fam['wife'] ?? null;
        }
        $anc[$g] = $row;
    }

    // Collect descendant generations. Just children of $focal recursively.
    $desc = [];
    $desc[0] = [$focal_id];
    for ($g = 1; $g <= $gen_down; $g++) {
        $row = [];
        foreach ($desc[$g - 1] as $pid) {
            if ($pid === null) continue;
            $p = stam_individual($pid);
            if (!$p) continue;
            foreach (stam_children_of($p) as $kid) $row[] = $kid;
        }
        $desc[$g] = $row;
    }

    // Render.
    $h = '<div class="hourglass">';
    // Ancestors top → focal
    for ($g = $gen_up; $g >= 1; $g--) {
        $h .= '<div class="gen-row gen-anc-' . $g . '">';
        foreach ($anc[$g] as $aid) {
            $h .= stam_render_card($aid);
        }
        $h .= '</div>';
    }
    // Focal row (with partner inline if any)
    $h .= '<div class="gen-row gen-focal">';
    $h .= stam_render_card($focal_id, true);
    foreach ($focal['spouse_families'] ?? [] as $fid) {
        $fam = stam_family($fid);
        if (!$fam) continue;
        $partner_id = ($fam['husband'] ?? null) === $focal_id ? ($fam['wife'] ?? null) : ($fam['husband'] ?? null);
        if ($partner_id) $h .= stam_render_card($partner_id);
    }
    $h .= '</div>';
    // Descendants
    for ($g = 1; $g <= $gen_down; $g++) {
        if (!$desc[$g]) break;
        $h .= '<div class="gen-row gen-desc-' . $g . '">';
        foreach ($desc[$g] as $cid) {
            $h .= stam_render_card($cid);
        }
        $h .= '</div>';
    }
    $h .= '</div>';
    return $h;
}

function stam_render_card(?string $id, bool $focal = false): string {
    if ($id === null) {
        return '<span class="person-card unknown">Onbekend</span>';
    }
    $ind = stam_individual($id);
    if (!$ind) return '<span class="person-card unknown">Onbekend</span>';
    $cls = 'person-card' . ($focal ? ' focal' : '');
    return '<a class="' . $cls . '" href="boom.php?id=' . htmlspecialchars($id) . '">'
         . '<span class="name">' . htmlspecialchars($ind['name']['display'] ?? 'Onbekend') . '</span>'
         . '<span class="dates">' . htmlspecialchars(stam_lifespan($ind)) . '</span>'
         . '</a>';
}
