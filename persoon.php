<?php
require_once __DIR__ . '/auth.php';
requireAuth();
require_once __DIR__ . '/lib/tree.php';

$id  = $_GET['id'] ?? '';
$ind = stam_individual($id);
if (!$ind) { include __DIR__ . '/404.php'; exit; }

$active_nav = 'lijst';

// Build relative blocks.
$parents = array_map('stam_individual', stam_parents_of($ind));
$parents = array_filter($parents);

$partners_and_kids = [];
foreach ($ind['spouse_families'] ?? [] as $fid) {
    $f = stam_family($fid);
    if (!$f) continue;
    $partner_id = ($f['husband'] ?? null) === $ind['id'] ? ($f['wife'] ?? null) : ($f['husband'] ?? null);
    $partner = $partner_id ? stam_individual($partner_id) : null;
    $kids = array_filter(array_map('stam_individual', $f['children'] ?? []));
    $partners_and_kids[] = ['family' => $f, 'partner' => $partner, 'children' => $kids];
}

$siblings = [];
if (!empty($ind['parents_family'])) {
    $pf = stam_family($ind['parents_family']);
    foreach ($pf['children'] ?? [] as $cid) {
        if ($cid === $ind['id']) continue;
        $s = stam_individual($cid);
        if ($s) $siblings[] = $s;
    }
}

function person_card_link(array $p): string {
    $name = htmlspecialchars($p['name']['display'] ?? 'Onbekend');
    $life = htmlspecialchars(stam_lifespan($p));
    return '<a class="person-card" href="persoon.php?id=' . htmlspecialchars($p['id']) . '">'
         . '<span class="name">' . $name . '</span>'
         . '<span class="dates">' . $life . '</span>'
         . '</a>';
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($ind['name']['display'] ?? 'Onbekend') ?> | Stamboom Ottenbourg</title>
<meta name="robots" content="noindex">
<link rel="stylesheet" href="style.css">
</head>
<body>
<?php include __DIR__ . '/menu.php'; ?>

<main class="page person-detail">
  <h1><?= htmlspecialchars($ind['name']['display'] ?? 'Onbekend') ?></h1>
  <p class="meta">
    <?= htmlspecialchars(stam_lifespan($ind)) ?>
    <?php if (!empty($ind['cycle'])): ?>
      · <span style="color:var(--danger)">⚠ deze persoon zit in een stamboom-cyclus</span>
    <?php endif; ?>
  </p>

  <dl>
    <dt>Geboren</dt>
    <dd>
      <?= htmlspecialchars(stam_fmt_date($ind['birth'] ?? null)) ?>
      <?php if (!empty($ind['birth']['place'])): ?>
        <br><span class="meta"><?= htmlspecialchars($ind['birth']['place']) ?></span>
      <?php endif; ?>
    </dd>
    <dt>Overleden</dt>
    <dd>
      <?= htmlspecialchars(stam_fmt_date($ind['death'] ?? null)) ?>
      <?php if (!empty($ind['death']['place'])): ?>
        <br><span class="meta"><?= htmlspecialchars($ind['death']['place']) ?></span>
      <?php endif; ?>
    </dd>
    <dt>Geslacht</dt>
    <dd><?= htmlspecialchars(['M'=>'man','F'=>'vrouw','U'=>'onbekend'][$ind['sex'] ?? 'U'] ?? 'onbekend') ?></dd>
  </dl>

  <?php if ($parents): ?>
  <section>
    <h2>Ouders</h2>
    <div class="rel-list">
      <?php foreach ($parents as $p) echo person_card_link($p); ?>
    </div>
  </section>
  <?php endif; ?>

  <?php foreach ($partners_and_kids as $pk): ?>
  <section>
    <h2>Partner<?= $pk['partner'] ? '' : ' (onbekend)' ?></h2>
    <div class="rel-list">
      <?php if ($pk['partner']) echo person_card_link($pk['partner']); ?>
    </div>
    <p class="meta">
      Huwelijk: <?= htmlspecialchars(stam_fmt_date($pk['family']['marriage'] ?? null)) ?>
      <?php if (!empty($pk['family']['marriage']['place'])): ?>
        · <?= htmlspecialchars($pk['family']['marriage']['place']) ?>
      <?php endif; ?>
    </p>
    <?php if ($pk['children']): ?>
      <h3>Kinderen</h3>
      <div class="rel-list">
        <?php foreach ($pk['children'] as $c) echo person_card_link($c); ?>
      </div>
    <?php endif; ?>
  </section>
  <?php endforeach; ?>

  <?php if ($siblings): ?>
  <section>
    <h2>Broers en zussen</h2>
    <div class="rel-list">
      <?php foreach ($siblings as $s) echo person_card_link($s); ?>
    </div>
  </section>
  <?php endif; ?>

  <p><a href="boom.php?id=<?= htmlspecialchars($ind['id']) ?>">→ Toon in stamboom-weergave</a></p>
</main>
</body>
</html>
