<?php
require_once __DIR__ . '/auth.php';
requireAuth();
require_once __DIR__ . '/lib/tree.php';
require_once __DIR__ . '/lib/render_list.php';

$meta   = stam_meta();
$letter = $_GET['letter'] ?? null;
$tree_root = $_GET['root'] ?? null;
$active_nav = 'lijst';
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Personen | Stamboom Ottenbourg</title>
<meta name="robots" content="noindex">
<link rel="stylesheet" href="style.css">
</head>
<body>
<?php include __DIR__ . '/menu.php'; ?>

<main class="page">
  <h1>Personen</h1>
  <p class="meta"><?= (int)($meta['individuals'] ?? 0) ?> personen.
     Klik op een achternaam om de personen uit te klappen.
     Of toon de <a href="?root=<?= htmlspecialchars($meta['root_id'] ?? '') ?>">afstammingslijst vanaf de stamouder</a>.</p>

  <?php if ($tree_root): ?>
    <h2>Afstammingslijst</h2>
    <?= stam_render_descendant_list($tree_root, 6) ?>
    <p><a href="lijst.php">← Terug naar alfabetische lijst</a></p>
  <?php else: ?>
    <?= stam_render_alpha_index($letter) ?>
  <?php endif; ?>
</main>

<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
