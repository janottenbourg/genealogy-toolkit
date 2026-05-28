<?php
require_once __DIR__ . '/auth.php';
requireAuth();
require_once __DIR__ . '/lib/users.php';
require_once __DIR__ . '/lib/tree.php';
require_once __DIR__ . '/lib/jsonstore.php';
requireAdmin();

$active_nav = 'admin';
$csrf = csrfToken();
$status = $_GET['status'] ?? null;
$link   = $_GET['link']   ?? null;
$tab    = $_GET['tab']    ?? 'accounts';
if (!in_array($tab, ['accounts', 'invites', 'tree', 'system'], true)) $tab = 'accounts';

$users  = stam_users_load();
$invites_data = stam_json_read(__DIR__ . '/invites.json');
$invites = $invites_data['invites'] ?? [];

$tree = stam_load_tree();
$meta = stam_meta();
$people = [];
foreach ($tree['individuals'] as $iid => $ind) {
    $people[$iid] = ($ind['name']['display'] ?? 'Onbekend')
                  . ($ind['birth']['iso'] ?? null ? ' (' . substr($ind['birth']['iso'], 0, 4)
                          . '–' . (substr($ind['death']['iso'] ?? '', 0, 4) ?: '?') . ')'
                  : '');
}
asort($people, SORT_STRING);

function tab_link(string $slug, string $label, string $current): string {
    $cls = $slug === $current ? 'class="admin-tab active"' : 'class="admin-tab"';
    return "<a $cls href=\"admin.php?tab=$slug\">$label</a>";
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Beheer | Stamboom Ottenbourg</title>
<meta name="robots" content="noindex">
<link rel="stylesheet" href="style.css">
</head>
<body>
<?php include __DIR__ . '/menu.php'; ?>

<main class="page">
  <h1>Beheer</h1>

  <?php if ($status === 'invited'): ?><div class="toast">Uitnodiging aangemaakt.</div><?php endif; ?>
  <?php if ($status === 'revoked'): ?><div class="toast">Uitnodiging verwijderd.</div><?php endif; ?>
  <?php if ($status === 'role'):    ?><div class="toast">Rol bijgewerkt.</div><?php endif; ?>
  <?php if ($status === 'deleted'): ?><div class="toast">Account verwijderd.</div><?php endif; ?>
  <?php if ($status === 'reset'):   ?><div class="toast">Reset-link aangemaakt.</div><?php endif; ?>
  <?php if ($link): ?>
    <div class="toast">
      Stuur deze link out-of-band naar de persoon:<br>
      <code><?= htmlspecialchars($link) ?></code>
    </div>
  <?php endif; ?>

  <nav class="admin-tabs">
    <?= tab_link('accounts', 'Accounts (' . count($users) . ')', $tab) ?>
    <?= tab_link('invites',  'Uitnodigingen (' . count($invites) . ')', $tab) ?>
    <?= tab_link('tree',     'Stamboom-data', $tab) ?>
    <?= tab_link('system',   'Systeem', $tab) ?>
  </nav>

  <?php if ($tab === 'accounts'): ?>
    <section class="admin-section">
      <h2>Accounts</h2>
      <?php if (!$users): ?>
        <p class="meta">Geen accounts.</p>
      <?php else: ?>
        <table class="admin-table">
          <tr><th>E-mail</th><th>Persoon</th><th>Rol</th><th>Aangemaakt</th><th>Laatste login</th><th>Acties</th></tr>
          <?php foreach ($users as $email => $u): ?>
            <?php $p = stam_individual($u['indi_id']); ?>
            <tr>
              <td><?= htmlspecialchars($email) ?></td>
              <td><a href="persoon.php?id=<?= htmlspecialchars($u['indi_id']) ?>"><?= htmlspecialchars($p['name']['display'] ?? $u['indi_id']) ?></a></td>
              <td><?= htmlspecialchars($u['role']) ?></td>
              <td><?= htmlspecialchars(substr($u['created_at'] ?? '', 0, 10)) ?></td>
              <td><?= htmlspecialchars(substr($u['last_login_at'] ?? '', 0, 10) ?: '—') ?></td>
              <td class="actions">
                <form method="post" action="admin/role.php">
                  <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                  <input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">
                  <input type="hidden" name="role" value="<?= $u['role'] === 'admin' ? 'user' : 'admin' ?>">
                  <button type="submit">Maak <?= $u['role'] === 'admin' ? 'user' : 'admin' ?></button>
                </form>
                <form method="post" action="admin/reset.php">
                  <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                  <input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">
                  <button type="submit">Reset wachtwoord</button>
                </form>
                <form method="post" action="admin/delete.php" onsubmit="return confirm('Account verwijderen?')">
                  <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                  <input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">
                  <button type="submit" class="danger">Verwijder</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>
    </section>

  <?php elseif ($tab === 'invites'): ?>
    <section class="admin-section">
      <h2>Nieuwe uitnodiging</h2>
      <form method="post" action="admin/invite.php">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
        <select name="indi_id" required style="background:var(--panel2);color:var(--text);border:1px solid var(--border);padding:6px;border-radius:6px">
          <option value="">— kies persoon —</option>
          <?php foreach ($people as $iid => $label): ?>
            <option value="<?= htmlspecialchars($iid) ?>"><?= htmlspecialchars($label) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" style="background:var(--accent);color:#fff;border:none;padding:6px 14px;border-radius:6px;font-weight:600;cursor:pointer">Stuur uitnodiging</button>
      </form>
    </section>

    <section class="admin-section">
      <h2>Openstaande uitnodigingen</h2>
      <?php if (!$invites): ?>
        <p class="meta">Geen openstaande uitnodigingen.</p>
      <?php else: ?>
        <table class="admin-table">
          <tr><th>Persoon</th><th>Verstuurd door</th><th>Verloopt</th><th>Link</th><th>Acties</th></tr>
          <?php foreach ($invites as $tok => $inv): ?>
            <?php $p = stam_individual($inv['indi_id']); ?>
            <tr>
              <td><?= htmlspecialchars($p['name']['display'] ?? $inv['indi_id']) ?></td>
              <td><?= htmlspecialchars($inv['created_by']) ?></td>
              <td><?= htmlspecialchars(substr($inv['expires_at'], 0, 10)) ?></td>
              <td><code style="font-size:11px">/signup.php?token=<?= htmlspecialchars(substr($tok, 0, 12)) ?>…</code></td>
              <td class="actions">
                <form method="post" action="admin/revoke_invite.php">
                  <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                  <input type="hidden" name="token" value="<?= htmlspecialchars($tok) ?>">
                  <button type="submit" class="danger">Verwijder</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>
    </section>

  <?php elseif ($tab === 'tree'): ?>
    <section class="admin-section">
      <h2>Stamboom-data</h2>
      <dl style="display:grid;grid-template-columns:200px 1fr;gap:6px 16px;max-width:600px">
        <dt>Bronbestand</dt><dd><code><?= htmlspecialchars($meta['source'] ?? '?') ?></code></dd>
        <dt>SHA-256</dt><dd><code style="font-size:11px"><?= htmlspecialchars(substr($meta['source_sha256'] ?? '?', 0, 16)) ?>…</code></dd>
        <dt>Bestand gewijzigd</dt><dd><?= htmlspecialchars(substr($meta['source_mtime'] ?? '?', 0, 10)) ?></dd>
        <dt>tree.json gebouwd</dt><dd><?= htmlspecialchars(substr($meta['built_at'] ?? '?', 0, 10)) ?></dd>
        <dt>Personen</dt><dd><strong><?= (int)($meta['individuals'] ?? 0) ?></strong></dd>
        <dt>Gezinnen</dt><dd><strong><?= (int)($meta['families'] ?? 0) ?></strong></dd>
        <dt>Stamouder</dt><dd>
          <?php $r = stam_individual($meta['root_id'] ?? ''); ?>
          <?= $r ? '<a href="persoon.php?id=' . htmlspecialchars($r['id']) . '">' . htmlspecialchars($r['name']['display']) . '</a>' : '—' ?>
        </dd>
      </dl>
      <h3 style="margin-top:24px">Stamboom verversen</h3>
      <p>Twee keer per jaar (of na grote wijzigingen) exporteer je een verse GEDCOM uit Geneanet en upload je die naar de server. Recept staat in <code>CLAUDE.md</code> sectie "GEDCOM update".</p>
    </section>

  <?php elseif ($tab === 'system'): ?>
    <section class="admin-section">
      <h2>Systeem</h2>
      <dl style="display:grid;grid-template-columns:200px 1fr;gap:6px 16px;max-width:600px">
        <dt>Versie</dt><dd>v2.1</dd>
        <dt>Repo</dt><dd><a target="_blank" rel="noopener noreferrer" href="https://github.com/janottenbourg/genealogy-toolkit">janottenbourg/genealogy-toolkit</a></dd>
        <dt>Hostname</dt><dd><?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? '?') ?></dd>
        <dt>PHP versie</dt><dd><?= htmlspecialchars(PHP_VERSION) ?></dd>
      </dl>
      <h3 style="margin-top:24px">Nuttige links</h3>
      <ul>
        <li><a target="_blank" rel="noopener noreferrer" href="https://github.com/janottenbourg/genealogy-toolkit/blob/main/CLAUDE.md">CLAUDE.md (project conventies)</a></li>
        <li><a target="_blank" rel="noopener noreferrer" href="https://github.com/janottenbourg/genealogy-toolkit/actions">CI runs</a></li>
        <li><a target="_blank" rel="noopener noreferrer" href="https://github.com/janottenbourg/genealogy-toolkit/releases">Releases (v1.0, v2.0)</a></li>
      </ul>
    </section>
  <?php endif; ?>
</main>

<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
