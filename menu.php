<?php
// Top nav, included on every authenticated page.
// Optionally set $active_nav = 'home'|'boom'|'lijst'|'zoek' before including.
$active = $active_nav ?? '';
?>
<nav class="topnav">
  <a href="home.php"   <?= $active==='home'  ? 'aria-current="page"' : '' ?>>Home</a>
  <a href="boom.php"   <?= $active==='boom'  ? 'aria-current="page"' : '' ?>>Stamboom</a>
  <a href="lijst.php"  <?= $active==='lijst' ? 'aria-current="page"' : '' ?>>Personen</a>
  <a href="zoek.php"   <?= $active==='zoek'  ? 'aria-current="page"' : '' ?>>Zoek</a>
  <a href="logout.php" class="logout">Uitloggen</a>
</nav>
