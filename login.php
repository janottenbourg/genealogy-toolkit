<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/validate.php';

csrfCheck($_POST['_csrf'] ?? null);

$email = $_POST['email']    ?? '';
$pw    = $_POST['password'] ?? '';
$next  = $_POST['next']     ?? 'home.php';

// Only allow same-origin relative paths in ?next= to avoid open redirect.
if (!preg_match('#^[a-zA-Z0-9_\-./?=&%]+$#', $next) || str_starts_with($next, '//') || str_starts_with($next, 'http')) {
    $next = 'home.php';
}

[$email_ok, $email_clean] = stam_v_email($email);
if ($email_ok && $pw !== '' && authLogin($email_clean, $pw)) {
    header('Location: ' . $next);
    exit;
}
usleep(500000);  // slow brute force
header('Location: index.php?err=1&next=' . urlencode($next));
exit;
