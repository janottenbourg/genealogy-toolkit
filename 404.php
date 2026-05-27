<?php
require_once __DIR__ . '/auth.php';
requireAuth();
http_response_code(404);
$active_nav = '';
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Niet gevonden | Stamboom Ottenbourg</title>
<meta name="robots" content="noindex">
<link rel="stylesheet" href="style.css">
</head>
<body>
<?php include __DIR__ . '/menu.php'; ?>
<main class="page">
  <h1>Persoon niet gevonden</h1>
  <p>Deze persoon staat niet (meer) in de stamboom. Misschien is de stamboom opnieuw geëxporteerd
     en zijn de ID's veranderd.</p>
  <p>Probeer te zoeken op naam: <a href="zoek.php">→ Zoeken</a>.</p>
</main>
</body>
</html>
