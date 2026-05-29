<?php
require_once __DIR__ . '/auth.php';
requireAuth();
require_once __DIR__ . '/lib/tree.php';
require_once __DIR__ . '/lib/famtree.php';

header('Content-Type: application/json; charset=utf-8');
echo json_encode(stam_famtree_data(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
