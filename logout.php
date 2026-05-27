<?php
require_once __DIR__ . '/auth.php';
authLogout();
header('Location: index.php');
exit;
