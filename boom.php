<?php
require_once __DIR__ . '/auth.php';
requireAuth();
require_once __DIR__ . '/lib/tree.php';
require_once __DIR__ . '/lib/render_hourglass.php';

$meta = stam_meta();
$id   = $_GET['id'] ?? ($meta['root_id'] ?? '');
$ind  = stam_individual($id);
if (!$ind) { include __DIR__ . '/404.php'; exit; }

$gen_up   = (int)($_GET['gen_up']   ?? 3);
$gen_down = (int)($_GET['gen_down'] ?? 3);

$active_nav = 'boom';
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Stamboom · <?= htmlspecialchars($ind['name']['display'] ?? 'Onbekend') ?></title>
<meta name="robots" content="noindex">
<link rel="stylesheet" href="style.css">
</head>
<body>
<?php include __DIR__ . '/menu.php'; ?>

<main class="page">
  <h1><?= htmlspecialchars($ind['name']['display'] ?? 'Onbekend') ?></h1>
  <p>
    <a href="persoon.php?id=<?= htmlspecialchars($id) ?>">→ Persoonspagina</a>
    · Voorouders: <a href="?id=<?= htmlspecialchars($id) ?>&gen_up=2&gen_down=<?= $gen_down ?>">2</a>
      <a href="?id=<?= htmlspecialchars($id) ?>&gen_up=3&gen_down=<?= $gen_down ?>">3</a>
      <a href="?id=<?= htmlspecialchars($id) ?>&gen_up=4&gen_down=<?= $gen_down ?>">4</a>
      <a href="?id=<?= htmlspecialchars($id) ?>&gen_up=5&gen_down=<?= $gen_down ?>">5</a>
    · Nazaten: <a href="?id=<?= htmlspecialchars($id) ?>&gen_up=<?= $gen_up ?>&gen_down=2">2</a>
      <a href="?id=<?= htmlspecialchars($id) ?>&gen_up=<?= $gen_up ?>&gen_down=3">3</a>
      <a href="?id=<?= htmlspecialchars($id) ?>&gen_up=<?= $gen_up ?>&gen_down=4">4</a>
      <a href="?id=<?= htmlspecialchars($id) ?>&gen_up=<?= $gen_up ?>&gen_down=5">5</a>
  </p>

  <?= stam_render_hourglass($id, $gen_up, $gen_down) ?>
</main>
</body>
</html>
