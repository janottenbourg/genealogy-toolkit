<?php
/*
 * Admin-only: stream a GEDCOM with augmentation merged in. POST + CSRF.
 * Runs tools/export_augment.py against the current source GEDCOM (the one
 * tree.json was built from) + augment.json, streams the result, deletes it.
 */
require_once __DIR__ . '/../auth.php';
requireAuth();
require_once __DIR__ . '/../lib/users.php';
requireAdmin();
require_once __DIR__ . '/../lib/tree.php';
require_once __DIR__ . '/../lib/gedcom_export.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: admin.php?tab=tree');
    exit;
}
csrfCheck($_POST['_csrf'] ?? null);

$root    = dirname(__DIR__);
$src     = basename((string)(stam_meta()['source'] ?? 'jottenbourg.ged'));
$ged     = $root . '/' . $src;
$augment = $root . '/augment.json';
$tool    = $root . '/tools/export_augment.py';

[$ok, $res] = stam_export_augmented_to_tmp($ged, $augment, $tool);
if (!$ok) {
    error_log('stamboom GEDCOM export failed: ' . $res);
    header('Location: admin.php?tab=tree&status=export_error');
    exit;
}

$fname = 'jottenbourg_augmented_' . gmdate('Y-m-d') . '.ged';
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Content-Length: ' . filesize($res));
readfile($res);
@unlink($res);
exit;
