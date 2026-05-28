<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/users.php';
require_once __DIR__ . '/lib/validate.php';

if (isAuthed()) { header('Location: home.php'); exit; }

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$record = $token !== '' ? stam_user_by_reset_token($token) : null;
$csrf = csrfToken();
$errors = [];

if (!$record) {
    http_response_code(410);
    ?>
    <!DOCTYPE html><html lang="nl"><head><meta charset="UTF-8"><title>Reset</title>
    <meta name="robots" content="noindex"><link rel="stylesheet" href="style.css"></head>
    <body class="login-page"><main class="login-card">
      <h1>Reset-link verlopen</h1>
      <div class="err">Deze reset-link is ongeldig of verlopen. Vraag de beheerder om een nieuwe.</div>
      <p class="subtitle"><a href="index.php">Terug naar inloggen</a></p>
    </main></body></html>
    <?php
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck($_POST['_csrf'] ?? null);
    $pw1 = $_POST['password']  ?? '';
    $pw2 = $_POST['password2'] ?? '';
    [$ok, $v] = stam_v_password($pw1, $record['email']);
    if (!$ok) $errors[] = $v;
    elseif ($pw1 !== $pw2) $errors[] = 'Wachtwoorden komen niet overeen.';

    if (!$errors) {
        stam_user_set_password($record['email'], $pw1);
        // Auto-login
        authLogin($record['email'], $pw1);
        header('Location: home.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Wachtwoord opnieuw instellen | Stamboom</title>
<meta name="robots" content="noindex">
<link rel="stylesheet" href="style.css">
</head>
<body class="login-page">
  <main class="login-card">
    <h1>Nieuw wachtwoord kiezen</h1>
    <p class="subtitle">Voor: <?= htmlspecialchars($record['email']) ?></p>
    <?php foreach ($errors as $e): ?><div class="err"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>
    <form method="post" action="reset.php" autocomplete="off">
      <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
      <label for="pw">Nieuw wachtwoord</label>
      <input id="pw" name="password" type="password" required autofocus>
      <label for="pw2">Wachtwoord herhalen</label>
      <input id="pw2" name="password2" type="password" required>
      <button type="submit">Opslaan</button>
    </form>
  </main>
</body>
</html>
