<?php
require_once __DIR__ . '/auth.php';
requireAuth();
require_once __DIR__ . '/lib/tree.php';
require_once __DIR__ . '/lib/users.php';
require_once __DIR__ . '/lib/augment.php';
require_once __DIR__ . '/lib/validate.php';

$active_nav = 'settings';
$me_id      = currentFocalId();
$me         = stam_individual($me_id ?? '');
$me_email   = currentUserEmail();

if (!$me) { http_response_code(500); echo 'Geen gekoppelde persoon gevonden.'; exit; }

$saved  = isset($_GET['saved']);
$errors = [];
$csrf   = csrfToken();

// Load current values (POST values override on form-bounce).
$current = stam_augment_for($me_id);
$field   = [
    'email'     => $current['email'],
    'mobile'    => $current['mobile'],
    'facebook'  => $current['facebook'],
    'linkedin'  => $current['linkedin'],
    'instagram' => $current['instagram'],
    'bio'       => $current['bio'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck($_POST['_csrf'] ?? null);

    // Re-validate even though canEdit($me) is always true for the focal —
    // defense in depth.
    if (!canEdit($me_id)) {
        http_response_code(403);
        echo 'Geen toegang.';
        exit;
    }

    $next = $field;
    [$ok, $v] = stam_v_email_optional($_POST['email'] ?? '');
    $ok ? $next['email'] = $v : $errors[] = $v;

    [$ok, $v] = stam_v_mobile_optional($_POST['mobile'] ?? '');
    $ok ? $next['mobile'] = $v : $errors[] = $v;

    [$ok, $v] = stam_v_facebook($_POST['facebook'] ?? '');
    $ok ? $next['facebook'] = $v : $errors[] = $v;

    [$ok, $v] = stam_v_linkedin($_POST['linkedin'] ?? '');
    $ok ? $next['linkedin'] = $v : $errors[] = $v;

    [$ok, $v] = stam_v_instagram($_POST['instagram'] ?? '');
    $ok ? $next['instagram'] = $v : $errors[] = $v;

    [$ok, $v] = stam_v_bio($_POST['bio'] ?? '');
    if ($ok) $next['bio'] = $v;

    if (!$errors) {
        stam_augment_save($me_id, $next, $me_email);
        header('Location: settings.php?saved=1');
        exit;
    } else {
        // Show the user's typed values back, not the saved ones.
        $field = $next;
    }
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Mijn profiel | Stamboom Ottenbourg</title>
<meta name="robots" content="noindex">
<link rel="stylesheet" href="style.css">
</head>
<body>
<?php include __DIR__ . '/menu.php'; ?>

<main class="page">
  <h1>Mijn profiel</h1>
  <p class="meta">Gekoppeld aan: <a href="persoon.php?id=<?= htmlspecialchars($me_id) ?>"><?= htmlspecialchars($me['name']['display']) ?></a></p>

  <?php if ($saved): ?><div class="toast">Opgeslagen.</div><?php endif; ?>
  <?php foreach ($errors as $e): ?>
    <div class="toast err"><?= htmlspecialchars($e) ?></div>
  <?php endforeach; ?>

  <form method="post" action="settings.php" class="edit-form">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
    <label for="email">E-mail (publiek voor familie)</label>
    <input id="email" name="email" type="email" value="<?= htmlspecialchars($field['email']) ?>">
    <label for="mobile">Mobiel</label>
    <input id="mobile" name="mobile" type="tel" value="<?= htmlspecialchars($field['mobile']) ?>">
    <label for="facebook">Facebook (https://…)</label>
    <input id="facebook" name="facebook" type="url" value="<?= htmlspecialchars($field['facebook']) ?>">
    <label for="linkedin">LinkedIn (https://…)</label>
    <input id="linkedin" name="linkedin" type="url" value="<?= htmlspecialchars($field['linkedin']) ?>">
    <label for="instagram">Instagram (https://…)</label>
    <input id="instagram" name="instagram" type="url" value="<?= htmlspecialchars($field['instagram']) ?>">
    <label for="bio">Over mij (max 4000 tekens)</label>
    <textarea id="bio" name="bio" maxlength="4096"><?= htmlspecialchars($field['bio']) ?></textarea>
    <div class="row">
      <button type="submit">Opslaan</button>
      <a class="person-card" href="persoon.php?id=<?= htmlspecialchars($me_id) ?>" style="width:auto;padding:8px 14px">Annuleren</a>
    </div>
  </form>
</main>
</body>
</html>
