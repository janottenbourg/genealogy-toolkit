<?php
require_once __DIR__ . '/../auth.php';
requireAuth();
require_once __DIR__ . '/../lib/users.php';
require_once __DIR__ . '/../lib/jsonstore.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo 'POST only'; exit;
}
csrfCheck($_POST['_csrf'] ?? null);

$tok = $_POST['token'] ?? '';
if ($tok !== '') {
    stam_json_mutate(__DIR__ . '/../invites.json', function (array $s) use ($tok) {
        unset($s['invites'][$tok]);
        return $s;
    });
}
header('Location: ../admin.php?status=revoked');
exit;
