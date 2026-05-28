<?php
require_once __DIR__ . '/auth.php';
requireAuth();
require_once __DIR__ . '/lib/tree.php';

function strftime_compat(string $iso): string {
    $t = strtotime($iso);
    if ($t === false) return $iso;
    $months = ['', 'januari','februari','maart','april','mei','juni',
                   'juli','augustus','september','oktober','november','december'];
    return date('j', $t) . ' ' . $months[(int)date('n', $t)] . ' ' . date('Y', $t);
}

$meta   = stam_meta();
$focal  = stam_individual(currentFocalId() ?? '');
$root   = stam_individual($meta['root_id'] ?? '');
$active_nav = 'home';

$built_at_nl = $meta['built_at'] ?? '?';
if ($built_at_nl !== '?') $built_at_nl = strftime_compat($built_at_nl);
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Stamboom Ottenbourg</title>
<meta name="robots" content="noindex">
<link rel="stylesheet" href="style.css">
</head>
<body>
<?php include __DIR__ . '/menu.php'; ?>

<main class="page">
  <?php if ($focal): ?>
    <h1>Welkom, <?= htmlspecialchars($focal['name']['display'] ?? 'familielid') ?></h1>
    <p>
      <a class="person-card focal" href="boom.php?id=<?= htmlspecialchars($focal['id']) ?>">
        <span class="name"><?= htmlspecialchars($focal['name']['display'] ?? 'Onbekend') ?></span>
        <span class="dates"><?= htmlspecialchars(stam_lifespan($focal)) ?></span>
      </a>
    </p>
    <p><a href="boom.php?id=<?= htmlspecialchars($focal['id']) ?>">→ Open jouw stamboom-weergave</a></p>
  <?php else: ?>
    <h1>Stamboom Ottenbourg</h1>
  <?php endif; ?>

  <p>Deze familiestamboom telt <strong><?= (int)($meta['individuals'] ?? 0) ?></strong>
     personen verspreid over <strong><?= (int)($meta['families'] ?? 0) ?></strong> gezinnen.</p>
</main>

<footer class="build-info">
  Stamboom-data: <?= htmlspecialchars($meta['source'] ?? '?') ?>
  · gegenereerd op <?= htmlspecialchars($built_at_nl) ?>
</footer>
</body>
</html>
