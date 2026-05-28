<?php
require_once __DIR__ . '/auth.php';
requireAuth();
require_once __DIR__ . '/lib/tree.php';

$meta   = stam_meta();
$focal  = stam_individual(currentFocalId() ?? '');
$active_nav = 'home';
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

<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
