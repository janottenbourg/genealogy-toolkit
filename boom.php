<?php
require_once __DIR__ . '/auth.php';
requireAuth();
require_once __DIR__ . '/lib/tree.php';

$meta = stam_meta();
$id   = $_GET['id'] ?? ($meta['root_id'] ?? '');
$ind  = stam_individual($id);
if (!$ind) { include __DIR__ . '/404.php'; exit; }
$active_nav = 'boom';
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Stamboom · <?= htmlspecialchars($ind['name']['display'] ?? 'Onbekend') ?></title>
<meta name="robots" content="noindex">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/family-chart@0.9.0/dist/styles/family-chart.css">
<link rel="stylesheet" href="style.css">
</head>
<body>
<?php include __DIR__ . '/menu.php'; ?>

<main class="page">
  <h1>Stamboom</h1>
  <p>
    <a href="persoon.php?id=<?= htmlspecialchars($id) ?>">→ Persoonspagina</a> ·
    <a href="voorouders.php?id=<?= htmlspecialchars($id) ?>">→ Voorouders (stamreeks)</a>
  </p>
  <p class="meta">Sleep om te schuiven · scroll om te zoomen · klik een kaartje om te hercentreren.</p>
  <div id="famtree-current" class="meta" style="margin:8px 0"></div>
  <div id="famtree" class="f3" data-main="<?= htmlspecialchars($id) ?>"></div>
  <noscript>
    <p>Voor de interactieve stamboom is JavaScript nodig. Bekijk de
       <a href="lijst.php">Personen-lijst</a> of de
       <a href="voorouders.php?id=<?= htmlspecialchars($id) ?>">voorouders</a>.</p>
  </noscript>
</main>

<script src="https://cdn.jsdelivr.net/npm/d3@7"></script>
<script src="https://cdn.jsdelivr.net/npm/family-chart@0.9.0/dist/family-chart.min.js"></script>
<script src="js/familytree.js"></script>
</body>
</html>
