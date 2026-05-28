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

$token = stam_user_issue_reset($email);
$origin = (($_SERVER['HTTPS'] ?? '') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$link = "$origin/reset.php?token=$token";
header('Location: ../admin.php?status=reset&link=' . urlencode($link));
exit;
