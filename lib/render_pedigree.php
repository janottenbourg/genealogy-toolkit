<?php
// Horizontal ancestor pedigree (B1): focal at left, ancestors branch rightward.
// A strict binary tree (2 parent slots each) → reliable nested-flex layout.
require_once __DIR__ . '/tree.php';

function stam_render_pedigree(string $focal_id, int $generations = 5): string {
    if (stam_individual($focal_id) === null) return '';
    return '<div class="pedigree">' . _stam_ped_node($focal_id, max(0, $generations)) . '</div>';
}

function _stam_ped_card(?string $id): string {
    if ($id === null) return '<span class="ped-card unknown">Onbekend</span>';
    $ind = stam_individual($id);
    if (!$ind) return '<span class="ped-card unknown">Onbekend</span>';
    return '<a class="ped-card" href="persoon.php?id=' . htmlspecialchars($id) . '">'
         . '<span class="name">' . htmlspecialchars($ind['name']['display'] ?? 'Onbekend') . '</span>'
         . '<span class="dates">' . htmlspecialchars(stam_lifespan($ind)) . '</span>'
         . '</a>';
}

function _stam_ped_node(?string $id, int $depth): string {
    $card = _stam_ped_card($id);
    if ($id === null || $depth <= 0) {
        return '<div class="ped-node">' . $card . '</div>';
    }
    $ind = stam_individual($id);
    $father = $mother = null;
    if ($ind && !empty($ind['parents_family'])) {
        $pf = stam_family($ind['parents_family']);
        if ($pf) { $father = $pf['husband'] ?? null; $mother = $pf['wife'] ?? null; }
    }
    if ($father === null && $mother === null) {
        return '<div class="ped-node">' . $card . '</div>';
    }
    return '<div class="ped-node">' . $card
         . '<div class="ped-parents">'
         . _stam_ped_node($father, $depth - 1)
         . _stam_ped_node($mother, $depth - 1)
         . '</div></div>';
}
