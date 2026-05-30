<?php
/*
 * Unit tests for lib/gedcom_export.php::stam_export_augmented_to_tmp().
 * Runs the real tools/export_augment.py against sample.ged + a temp augment.
 * Run:  php tests/test_gedcom_export.php   (workstation: /c/php/php.exe ...)
 */
declare(strict_types=1);

$root = dirname(__DIR__);
chdir($root);
require __DIR__ . '/../lib/gedcom_export.php';

$pass = 0; $fail = 0;
function check(bool $cond, string $label): void {
    global $pass, $fail;
    if ($cond) { echo "  PASS  $label\n"; $pass++; }
    else       { echo "  FAIL  $label\n"; $fail++; }
}

$tool = $root . '/tools/export_augment.py';
$ged  = $root . '/sample.ged';

$aug = tempnam(sys_get_temp_dir(), 'aug_');
file_put_contents($aug, json_encode(['augmentations' => [
    'I7' => ['email' => 'jan@ottenbourg.com', 'facebook' => 'https://facebook.com/x'],
]]));

echo "==> happy path\n";
[$ok, $res] = stam_export_augmented_to_tmp($ged, $aug, $tool);
check($ok === true, 'returns ok');
check($ok && is_string($res) && is_file($res), 'temp output file exists');
if ($ok && is_file($res)) {
    $out = file_get_contents($res);
    check(strpos($out, '-- stamboom-augment begin --') !== false, 'contains begin marker');
    check(strpos($out, 'E-mail: jan@@ottenbourg.com') !== false, 'contains @@-escaped email');
    check(strpos($out, '0 HEAD') !== false, 'still a GEDCOM (0 HEAD present)');
    @unlink($res);
}

echo "==> error path: missing GEDCOM\n";
[$ok2, $err2] = stam_export_augmented_to_tmp($root . '/does_not_exist.ged', $aug, $tool);
check($ok2 === false, 'missing ged → not ok');
check(is_string($err2) && $err2 !== '', 'missing ged → non-empty Dutch error');

@unlink($aug);
echo "\nSummary: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
