<?php
require_once __DIR__ . '/../auth.php';
requireAuth();
require_once __DIR__ . '/../lib/users.php';
require_once __DIR__ . '/../lib/validate.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
csrfCheck($_POST['_csrf'] ?? null);

[$ok, $email] = stam_v_email($_POST['email'] ?? '');
if (!$ok || !stam_user_by_email($email)) {
    header('Location: ../admin.php?status=error'); exit;
}
// Refuse to delete yourself or the last admin.
if ($email === currentUserEmail()) {
    header('Location: ../admin.php?status=error'); exit;
}
$users = stam_users_load();
$admins = array_filter($users, fn($u) => ($u['role'] ?? '') === 'admin');
if (count($admins) <= 1 && isset($users[$email]) && $users[$email]['role'] === 'admin') {
    header('Location: ../admin.php?status=error'); exit;
}

stam_user_delete($email);
header('Location: ../admin.php?status=deleted');
exit;
