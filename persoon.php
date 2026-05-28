<?php
require_once __DIR__ . '/auth.php';
requireAuth();
require_once __DIR__ . '/lib/tree.php';
require_once __DIR__ . '/lib/users.php';
require_once __DIR__ . '/lib/augment.php';
require_once __DIR__ . '/lib/validate.php';

$id  = $_GET['id'] ?? '';
$ind = stam_individual($id);
if (!$ind) { include __DIR__ . '/404.php'; exit; }

$active_nav = 'lijst';
$edit_mode  = isset($_GET['edit']) || ($_SERVER['REQUEST_METHOD'] === 'POST');
$can_edit   = canEdit($id);
$saved      = isset($_GET['saved']);
$errors     = [];
$csrf       = csrfToken();

// Current augmentation (always — needed in both read + edit modes)
$augment = stam_augment_for($id);
$field   = [
    'email'     => $augment['email'],
    'mobile'    => $augment['mobile'],
    'facebook'  => $augment['facebook'],
    'linkedin'  => $augment['linkedin'],
    'instagram' => $augment['instagram'],
    'bio'       => $augment['bio'],
];

if ($edit_mode && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck($_POST['_csrf'] ?? null);
    if (!$can_edit) {
        http_response_code(403);
        echo '<!DOCTYPE html><meta charset="UTF-8"><title>Geen toegang</title>'
           . '<p style="font-family:sans-serif;padding:40px">Je hebt geen toegang om deze persoon te bewerken.</p>';
        error_log("stamboom: forbidden edit POST: user=" . (currentUserEmail() ?? '?') . " target=$id");
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
        stam_augment_save($id, $next, currentUserEmail() ?? '?');
        header('Location: persoon.php?id=' . urlencode($id) . '&saved=1');
        exit;
    } else {
        $field = $next;
    }
}

// Relative blocks (only needed in read mode, but cheap)
$parents = array_filter(array_map('stam_individual', stam_parents_of($ind)));
$partners_and_kids = [];
foreach ($ind['spouse_families'] ?? [] as $fid) {
    $f = stam_family($fid);
    if (!$f) continue;
    $partner_id = ($f['husband'] ?? null) === $ind['id'] ? ($f['wife'] ?? null) : ($f['husband'] ?? null);
    $partner = $partner_id ? stam_individual($partner_id) : null;
    $kids = array_filter(array_map('stam_individual', $f['children'] ?? []));
    $partners_and_kids[] = ['family' => $f, 'partner' => $partner, 'children' => $kids];
}
$siblings = [];
if (!empty($ind['parents_family'])) {
    $pf = stam_family($ind['parents_family']);
    foreach ($pf['children'] ?? [] as $cid) {
        if ($cid === $ind['id']) continue;
        $s = stam_individual($cid);
        if ($s) $siblings[] = $s;
    }
}

function person_card_link(array $p): string {
    $name = htmlspecialchars($p['name']['display'] ?? 'Onbekend');
    $life = htmlspecialchars(stam_lifespan($p));
    return '<a class="person-card" href="persoon.php?id=' . htmlspecialchars($p['id']) . '">'
         . '<span class="name">' . $name . '</span>'
         . '<span class="dates">' . $life . '</span>'
         . '</a>';
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($ind['name']['display'] ?? 'Onbekend') ?> | Stamboom Ottenbourg</title>
<meta name="robots" content="noindex">
<link rel="stylesheet" href="style.css">
</head>
<body>
<?php include __DIR__ . '/menu.php'; ?>

<main class="page person-detail">
  <h1>
    <?= htmlspecialchars($ind['name']['display'] ?? 'Onbekend') ?>
    <?php if ($can_edit && !$edit_mode): ?>
      <a class="person-card" href="?id=<?= htmlspecialchars($id) ?>&edit=1" style="float:right;width:auto;padding:6px 12px;font-size:13px">Bewerk gegevens</a>
    <?php endif; ?>
  </h1>
  <p class="meta">
    <?= htmlspecialchars(stam_lifespan($ind)) ?>
    <?php if (!empty($ind['cycle'])): ?>
      · <span style="color:var(--danger)">⚠ deze persoon zit in een stamboom-cyclus</span>
    <?php endif; ?>
  </p>

  <?php if ($saved): ?><div class="toast">Opgeslagen.</div><?php endif; ?>
  <?php foreach ($errors as $e): ?><div class="toast err"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

  <dl>
    <dt>Geboren</dt>
    <dd>
      <?= htmlspecialchars(stam_fmt_date($ind['birth'] ?? null)) ?>
      <?php if (!empty($ind['birth']['place'])): ?>
        <br><span class="meta"><?= htmlspecialchars($ind['birth']['place']) ?></span>
      <?php endif; ?>
    </dd>
    <dt>Overleden</dt>
    <dd>
      <?= htmlspecialchars(stam_fmt_date($ind['death'] ?? null)) ?>
      <?php if (!empty($ind['death']['place'])): ?>
        <br><span class="meta"><?= htmlspecialchars($ind['death']['place']) ?></span>
      <?php endif; ?>
    </dd>
    <dt>Geslacht</dt>
    <dd><?= htmlspecialchars(['M'=>'man','F'=>'vrouw','U'=>'onbekend'][$ind['sex'] ?? 'U'] ?? 'onbekend') ?></dd>
  </dl>

  <?php if ($edit_mode && $can_edit): ?>
    <h2>Persoonlijke gegevens bewerken</h2>
    <form method="post" action="persoon.php?id=<?= htmlspecialchars($id) ?>&edit=1" class="edit-form">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
      <label for="email">E-mail</label>
      <input id="email" name="email" type="email" value="<?= htmlspecialchars($field['email']) ?>">
      <label for="mobile">Mobiel</label>
      <input id="mobile" name="mobile" type="tel" value="<?= htmlspecialchars($field['mobile']) ?>">
      <label for="facebook">Facebook</label>
      <input id="facebook" name="facebook" type="url" value="<?= htmlspecialchars($field['facebook']) ?>">
      <label for="linkedin">LinkedIn</label>
      <input id="linkedin" name="linkedin" type="url" value="<?= htmlspecialchars($field['linkedin']) ?>">
      <label for="instagram">Instagram</label>
      <input id="instagram" name="instagram" type="url" value="<?= htmlspecialchars($field['instagram']) ?>">
      <label for="bio">Over deze persoon</label>
      <textarea id="bio" name="bio" maxlength="4096"><?= htmlspecialchars($field['bio']) ?></textarea>
      <div class="row">
        <button type="submit">Opslaan</button>
        <a class="person-card" href="persoon.php?id=<?= htmlspecialchars($id) ?>" style="width:auto;padding:8px 14px">Annuleren</a>
      </div>
    </form>
  <?php else: ?>
    <?php if (stam_augment_has_any($id)): ?>
      <div class="augment-block">
        <h3>Persoonlijke gegevens</h3>
        <dl>
          <?php if ($field['email']):     ?><dt>E-mail</dt><dd><a href="mailto:<?= htmlspecialchars($field['email']) ?>"><?= htmlspecialchars($field['email']) ?></a></dd><?php endif; ?>
          <?php if ($field['mobile']):    ?><dt>Mobiel</dt><dd><a href="tel:<?= htmlspecialchars(preg_replace('/\s+/', '', $field['mobile'])) ?>"><?= htmlspecialchars($field['mobile']) ?></a></dd><?php endif; ?>
          <?php if ($field['facebook']):  ?><dt>Facebook</dt><dd><a target="_blank" rel="noopener noreferrer" href="<?= htmlspecialchars($field['facebook']) ?>"><?= htmlspecialchars($field['facebook']) ?></a></dd><?php endif; ?>
          <?php if ($field['linkedin']):  ?><dt>LinkedIn</dt><dd><a target="_blank" rel="noopener noreferrer" href="<?= htmlspecialchars($field['linkedin']) ?>"><?= htmlspecialchars($field['linkedin']) ?></a></dd><?php endif; ?>
          <?php if ($field['instagram']): ?><dt>Instagram</dt><dd><a target="_blank" rel="noopener noreferrer" href="<?= htmlspecialchars($field['instagram']) ?>"><?= htmlspecialchars($field['instagram']) ?></a></dd><?php endif; ?>
          <?php if ($field['bio']):       ?><dt>Over</dt><dd class="bio"><?= nl2br(htmlspecialchars($field['bio'])) ?></dd><?php endif; ?>
        </dl>
        <?php if ($augment['updated_at']): ?>
          <p class="audit-footer">Laatst bijgewerkt door <?= htmlspecialchars($augment['updated_by'] ?? '?') ?> op <?= htmlspecialchars(substr($augment['updated_at'], 0, 10)) ?>.</p>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($parents): ?>
      <section><h2>Ouders</h2><div class="rel-list">
        <?php foreach ($parents as $p) echo person_card_link($p); ?>
      </div></section>
    <?php endif; ?>

    <?php foreach ($partners_and_kids as $pk): ?>
      <section>
        <h2>Partner<?= $pk['partner'] ? '' : ' (onbekend)' ?></h2>
        <div class="rel-list">
          <?php if ($pk['partner']) echo person_card_link($pk['partner']); ?>
        </div>
        <p class="meta">
          Huwelijk: <?= htmlspecialchars(stam_fmt_date($pk['family']['marriage'] ?? null)) ?>
          <?php if (!empty($pk['family']['marriage']['place'])): ?>· <?= htmlspecialchars($pk['family']['marriage']['place']) ?><?php endif; ?>
        </p>
        <?php if ($pk['children']): ?>
          <h3>Kinderen</h3>
          <div class="rel-list">
            <?php foreach ($pk['children'] as $c) echo person_card_link($c); ?>
          </div>
        <?php endif; ?>
      </section>
    <?php endforeach; ?>

    <?php if ($siblings): ?>
      <section><h2>Broers en zussen</h2><div class="rel-list">
        <?php foreach ($siblings as $s) echo person_card_link($s); ?>
      </div></section>
    <?php endif; ?>

    <p><a href="boom.php?id=<?= htmlspecialchars($id) ?>">→ Toon in stamboom-weergave</a></p>
  <?php endif; ?>
</main>
</body>
</html>
