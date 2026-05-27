<?php
require_once __DIR__ . '/auth.php';
if (isAuthed()) { header('Location: home.php'); exit; }
$err  = isset($_GET['err']) ? 'Ongeldig wachtwoord.' : null;
$next = $_GET['next'] ?? 'home.php';
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Stamboom Ottenbourg | Inloggen</title>
<meta name="robots" content="noindex">
<link rel="stylesheet" href="style.css">
</head>
<body class="login-page">
  <main class="login-card">
    <h1>Stamboom <span class="accent">Ottenbourg</span></h1>
    <p class="subtitle">Meld je aan om de familiestamboom te bekijken</p>
    <?php if ($err): ?><div class="err"><?= htmlspecialchars($err) ?></div><?php endif; ?>
    <form method="post" action="login.php" autocomplete="on">
      <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">
      <label for="pw">Wachtwoord</label>
      <input id="pw" name="password" type="password" autofocus required>
      <button type="submit">Inloggen</button>
    </form>
  </main>
</body>
</html>
