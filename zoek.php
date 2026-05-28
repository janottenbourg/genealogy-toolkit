<?php
require_once __DIR__ . '/auth.php';
requireAuth();
require_once __DIR__ . '/lib/tree.php';

function stam_strip_diacritics_php(string $s): string {
    // Try iconv first (best); fall back to a manual map.
    if (function_exists('iconv')) {
        $out = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($out !== false) return strtolower($out);
    }
    $map = [
        'À'=>'a','Á'=>'a','Â'=>'a','Ã'=>'a','Ä'=>'a','Å'=>'a',
        'È'=>'e','É'=>'e','Ê'=>'e','Ë'=>'e',
        'Ì'=>'i','Í'=>'i','Î'=>'i','Ï'=>'i',
        'Ò'=>'o','Ó'=>'o','Ô'=>'o','Õ'=>'o','Ö'=>'o',
        'Ù'=>'u','Ú'=>'u','Û'=>'u','Ü'=>'u',
        'Ç'=>'c','Ñ'=>'n',
        'à'=>'a','á'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a',
        'è'=>'e','é'=>'e','ê'=>'e','ë'=>'e',
        'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i',
        'ò'=>'o','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o',
        'ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u',
        'ç'=>'c','ñ'=>'n',
    ];
    return strtolower(strtr($s, $map));
}

$q = trim($_GET['q'] ?? '');
$active_nav = 'zoek';

$results = [];
if ($q !== '') {
    $tree = stam_load_tree();
    $needle = stam_strip_diacritics_php($q);
    foreach ($tree['indexes']['name_search'] ?? [] as $entry) {
        if (stripos($entry['k'], $needle) !== false) {
            $ind = stam_individual($entry['id']);
            if ($ind) $results[] = $ind;
        }
    }
    // Limit & sort by surname
    usort($results, function($a, $b) {
        return strcmp($a['name']['surname'] ?? '', $b['name']['surname'] ?? '')
            ?: strcmp($a['name']['display'] ?? '', $b['name']['display'] ?? '');
    });
    $results = array_slice($results, 0, 200);
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Zoeken | Stamboom Ottenbourg</title>
<meta name="robots" content="noindex">
<link rel="stylesheet" href="style.css">
</head>
<body>
<?php include __DIR__ . '/menu.php'; ?>

<main class="page">
  <h1>Zoeken</h1>
  <form class="search-form" method="get" action="zoek.php">
    <input type="text" name="q" value="<?= htmlspecialchars($q) ?>"
           placeholder="Naam (voor- of achter-, accenten optioneel)…" autofocus>
    <button type="submit">Zoeken</button>
  </form>

  <?php if ($q === ''): ?>
    <p class="meta">Tik een (gedeeltelijke) naam in. Accenten worden genegeerd.</p>
  <?php elseif (!$results): ?>
    <p>Geen resultaten voor <strong><?= htmlspecialchars($q) ?></strong>.</p>
  <?php else: ?>
    <p class="meta"><?= count($results) ?> resultaten voor <strong><?= htmlspecialchars($q) ?></strong>:</p>
    <div class="search-results rel-list">
      <?php foreach ($results as $p): ?>
        <a class="person-card" href="persoon.php?id=<?= htmlspecialchars($p['id']) ?>">
          <span class="name"><?= htmlspecialchars($p['name']['display'] ?? 'Onbekend') ?></span>
          <span class="dates"><?= htmlspecialchars(stam_lifespan($p)) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</main>

<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
