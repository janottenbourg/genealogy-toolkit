<?php
require_once __DIR__ . '/auth.php';
$pw   = $_POST['password'] ?? '';
$next = $_POST['next'] ?? 'home.php';
// Only allow same-origin relative paths in ?next= to avoid open redirect.
if (!preg_match('#^[a-zA-Z0-9_\-./?=&%]+$#', $next) || str_starts_with($next, '//') || str_starts_with($next, 'http')) {
    $next = 'home.php';
}
if ($pw !== '' && authLogin($pw)) {
    header('Location: ' . $next);
    exit;
}
usleep(500000);  // slow down brute force
header('Location: index.php?err=1&next=' . urlencode($next));
exit;
