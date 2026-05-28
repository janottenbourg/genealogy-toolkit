<?php
require_once __DIR__ . '/../auth.php';
requireAuth();
require_once __DIR__ . '/../lib/users.php';
require_once __DIR__ . '/../lib/tree.php';
require_once __DIR__ . '/../lib/jsonstore.php';
require_once __DIR__ . '/../lib/validate.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo 'POST only'; exit;
}
csrfCheck($_POST['_csrf'] ?? null);

[$ok, $indi] = stam_v_indi_id($_POST['indi_id'] ?? '');
if (!$ok) { header('Location: ../admin.php?status=error'); exit; }
if (!stam_individual($indi))      { header('Location: ../admin.php?status=error'); exit; }
if (stam_user_by_indi($indi))     { header('Location: ../admin.php?status=error'); exit; }

$token = 'tok_' . bin2hex(random_bytes(16));
$now   = gmdate('Y-m-d\TH:i:s\Z');
$exp   = gmdate('Y-m-d\TH:i:s\Z', time() + 14 * 24 * 3600);

stam_json_mutate(__DIR__ . '/../invites.json', function (array $s) use ($token, $indi, $now, $exp) {
    if (!isset($s['invites'])) $s['invites'] = [];
    $s['invites'][$token] = [
        'indi_id'    => $indi,
        'created_by' => currentUserEmail() ?? '?',
        'created_at' => $now,
        'expires_at' => $exp,
    ];
    return $s;
});

$origin = (($_SERVER['HTTPS'] ?? '') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$link = "$origin/signup.php?token=$token";
header('Location: ../admin.php?status=invited&link=' . urlencode($link));
exit;
