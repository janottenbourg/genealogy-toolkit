<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/jsonstore.php';
require_once __DIR__ . '/lib/tree.php';
require_once __DIR__ . '/lib/users.php';
require_once __DIR__ . '/lib/validate.php';

const STAM_INVITES_PATH = __DIR__ . '/invites.json';

function stam_invite_get(string $token): ?array {
    $d = stam_json_read(STAM_INVITES_PATH);
    $i = $d['invites'][$token] ?? null;
    if (!$i) return null;
    if (($i['expires_at'] ?? '') < gmdate('Y-m-d\TH:i:s\Z')) return null;
    return $i + ['token' => $token];
}

function stam_invite_consume(string $token): void {
    stam_json_mutate(STAM_INVITES_PATH, function (array $s) use ($token) {
        unset($s['invites'][$token]);
        return $s;
    });
}

if (isAuthed()) { header('Location: home.php'); exit; }

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$invite = $token !== '' ? stam_invite_get($token) : null;

if (!$invite) {
    http_response_code(410);
    $msg = 'Deze uitnodiging is ongeldig of verlopen. Vraag de beheerder om een nieuwe.';
    require __DIR__ . '/signup_error.php_inline';
    exit;
}

$person = stam_individual($invite['indi_id']);
if (!$person) {
    http_response_code(410);
    $msg = 'De persoon van deze uitnodiging staat niet (meer) in de stamboom.';
    require __DIR__ . '/signup_error.php_inline';
    exit;
}

if (stam_user_by_indi($invite['indi_id'])) {
    http_response_code(410);
    $msg = 'Deze persoon heeft al een account.';
    require __DIR__ . '/signup_error.php_inline';
    exit;
}

$errors = [];
$email_val = '';
$csrf = csrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck($_POST['_csrf'] ?? null);
    [$ok_e, $email_val] = stam_v_email($_POST['email'] ?? '');
    if (!$ok_e) $errors[] = $email_val;

    $pw1 = $_POST['password']  ?? '';
    $pw2 = $_POST['password2'] ?? '';
    [$ok_p, $pw_clean] = stam_v_password($pw1, $email_val);
    if (!$ok_p) $errors[] = $pw_clean;
    elseif ($pw1 !== $pw2) $errors[] = 'Wachtwoorden komen niet overeen.';

    if ($ok_e && stam_user_by_email($email_val)) {
        $errors[] = 'Er bestaat al een account met dit e-mailadres.';
    }

    if (!$errors) {
        stam_user_create($email_val, $pw1, $invite['indi_id'], 'user');
        stam_invite_consume($token);
        authLogin($email_val, $pw1);
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
<title>Account aanmaken | Stamboom Ottenbourg</title>
<meta name="robots" content="noindex">
<link rel="stylesheet" href="style.css">
</head>
<body class="login-page">
  <main class="login-card">
    <h1>Welkom, <?= htmlspecialchars($person['name']['display'] ?? 'familielid') ?></h1>
    <p class="subtitle">Kies een e-mailadres en een wachtwoord om in te loggen.</p>
    <?php foreach ($errors as $e): ?>
      <div class="err"><?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>
    <form method="post" action="signup.php" autocomplete="off">
      <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
      <label for="email">E-mail</label>
      <input id="email" name="email" type="email" value="<?= htmlspecialchars($email_val) ?>" required autofocus>
      <label for="pw">Wachtwoord (≥ 8 tekens)</label>
      <input id="pw" name="password" type="password" required>
      <label for="pw2">Wachtwoord herhalen</label>
      <input id="pw2" name="password2" type="password" required>
      <button type="submit">Account aanmaken</button>
    </form>
  </main>
</body>
</html>
