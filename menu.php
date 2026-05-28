<?php
// Top nav, included on every authenticated page.
// Optionally set $active_nav before including:
//   'home' | 'boom' | 'zoek' | 'lijst' | 'settings' | 'admin'
$active = $active_nav ?? '';
$role   = currentRole();
?>
<nav class="topnav">
  <a href="home.php"     <?= $active==='home'     ? 'aria-current="page"' : '' ?>>Home</a>
  <a href="boom.php"     <?= $active==='boom'     ? 'aria-current="page"' : '' ?>>Stamboom</a>
  <a href="zoek.php"     <?= $active==='zoek'     ? 'aria-current="page"' : '' ?>>Zoek</a>
  <a href="lijst.php"    <?= $active==='lijst'    ? 'aria-current="page"' : '' ?>>Personen</a>
  <a href="settings.php" <?= $active==='settings' ? 'aria-current="page"' : '' ?>>Mijn Profiel</a>
  <?php if ($role === 'admin'): ?>
    <a href="admin.php"  <?= $active==='admin'    ? 'aria-current="page"' : '' ?>>Beheer</a>
  <?php endif; ?>
  <a href="logout.php" class="logout">Uitloggen</a>
</nav>
