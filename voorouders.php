<?php
require_once __DIR__ . '/auth.php';
requireAuth();
require_once __DIR__ . '/lib/tree.php';
require_once __DIR__ . '/lib/render_pedigree.php';

$id  = $_GET['id'] ?? '';
$ind = stam_individual($id);
if (!$ind) { include __DIR__ . '/404.php'; exit; }
$active_nav = 'boom';
$gens = (int)($_GET['gen'] ?? 5);
$gens = max(2, min(7, $gens));
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Voorouders · <?= htmlspecialchars($ind['name']['display'] ?? 'Onbekend') ?></title>
<meta name="robots" content="noindex">
<link rel="stylesheet" href="style.css">
</head>
<body>
<?php include __DIR__ . '/menu.php'; ?>

<main class="page">
  <h1>Voorouders van <?= htmlspecialchars($ind['name']['display'] ?? 'Onbekend') ?></h1>
  <p>
    <a href="boom.php?id=<?= htmlspecialchars($id) ?>">→ Interactieve stamboom</a> ·
    <a href="persoon.php?id=<?= htmlspecialchars($id) ?>">→ Persoonspagina</a> ·
    Generaties:
    <?php foreach ([3,4,5,6] as $g): ?>
      <a href="?id=<?= htmlspecialchars($id) ?>&gen=<?= $g ?>"><?= $g ?></a>
    <?php endforeach; ?>
  </p>
  <?= stam_render_pedigree($id, $gens) ?>
</main>

<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
