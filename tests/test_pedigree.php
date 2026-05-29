<?php
// Asserts lib/render_pedigree.php output for a known focal in sample.ged.
// Run: php tests/test_pedigree.php
declare(strict_types=1);

$root = dirname(__DIR__);
chdir($root);
$build = (PHP_OS_FAMILY === 'Windows') ? "py build.py sample.ged" : "python3 build.py sample.ged";
shell_exec("$build > /dev/null 2>&1");

require __DIR__ . '/../lib/tree.php';
require __DIR__ . '/../lib/render_pedigree.php';

$pass = 0; $fail = 0;
function check(bool $c, string $l): void {
    global $pass, $fail;
    if ($c) { echo "  PASS  $l\n"; $pass++; } else { echo "  FAIL  $l\n"; $fail++; }
}

// I9 Pieter: parents I3 Marcel + I8 Elise; via I3 → grandparents I1 Désiré + I2 Hélène.
$html = stam_render_pedigree('I9', 5);
check(str_contains($html, 'Pieter'),  'focal Pieter present');
check(str_contains($html, 'Marcel'),  'father Marcel present');
check(str_contains($html, 'Elise'),   'mother Elise present');
check(str_contains($html, 'Désiré'),  'grandfather Désiré present');
check(str_contains($html, 'Hélène'),  'grandmother Hélène present');
check(str_contains($html, 'persoon.php?id=I3'), 'links to person pages');
check(str_contains($html, 'class="pedigree"'),  'has pedigree wrapper');

// Unknown focal → empty string (caller handles 404 separately)
check(stam_render_pedigree('I999', 5) === '', 'unknown focal → empty');

echo "\nSummary: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
