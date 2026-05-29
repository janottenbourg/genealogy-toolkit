<?php
// Build the family-chart data array from tree.json.
// Node shape: { id, data:{name,dates,gender}, rels:{parents[],spouses[],children[]} }
// Only name/dates/sex/relationships — NO augmentation (email/socials/bio).

require_once __DIR__ . '/tree.php';

function stam_famtree_data(): array {
    $t = stam_load_tree();
    $out = [];
    foreach ($t['individuals'] as $id => $ind) {
        $rels = ['parents' => [], 'spouses' => [], 'children' => []];

        if (!empty($ind['parents_family'])) {
            $pf = stam_family($ind['parents_family']);
            if ($pf) {
                if (!empty($pf['husband'])) $rels['parents'][] = $pf['husband'];
                if (!empty($pf['wife']))    $rels['parents'][] = $pf['wife'];
            }
        }

        $children = [];
        foreach ($ind['spouse_families'] ?? [] as $fid) {
            $f = stam_family($fid);
            if (!$f) continue;
            $partner = (($f['husband'] ?? null) === $id) ? ($f['wife'] ?? null) : ($f['husband'] ?? null);
            if ($partner) $rels['spouses'][] = $partner;
            foreach ($f['children'] ?? [] as $cid) $children[$cid] = true;
        }
        $rels['children'] = array_keys($children);

        $sex = $ind['sex'] ?? 'U';
        $out[] = [
            'id'   => $id,
            'data' => [
                'name'   => $ind['name']['display'] ?? 'Onbekend',
                'dates'  => stam_lifespan($ind),
                'gender' => ($sex === 'F') ? 'F' : 'M', // family-chart wants M/F; U→M (visual only)
            ],
            'rels' => $rels,
        ];
    }
    return $out;
}
