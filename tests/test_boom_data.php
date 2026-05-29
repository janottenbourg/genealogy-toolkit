<?php
// Asserts lib/famtree.php::stam_famtree_data() against sample.ged.
// Run: php tests/test_boom_data.php
declare(strict_types=1);

$root = dirname(__DIR__);
chdir($root);
$build = (PHP_OS_FAMILY === 'Windows') ? "py build.py sample.ged" : "python3 build.py sample.ged";
shell_exec("$build > /dev/null 2>&1");

require __DIR__ . '/../lib/tree.php';
require __DIR__ . '/../lib/famtree.php';

$pass = 0; $fail = 0;
function check(bool $c, string $l): void {
    global $pass, $fail;
    if ($c) { echo "  PASS  $l\n"; $pass++; } else { echo "  FAIL  $l\n"; $fail++; }
}

$data = stam_famtree_data();
$by = [];
foreach ($data as $n) $by[$n['id']] = $n;

check(count($data) === 15, 'array length 15');
check(isset($by['I3']), 'I3 present');

$m = $by['I3'];
check(($m['rels']['father'] ?? null) === 'I1', 'I3 father = I1');
check(($m['rels']['mother'] ?? null) === 'I2', 'I3 mother = I2');
$sp = $m['rels']['spouses']; sort($sp);
check($sp === ['I6', 'I8'], 'I3 spouses = I6,I8');
$ch = $m['rels']['children']; sort($ch);
check($ch === ['I10', 'I7', 'I9'], 'I3 children = I7,I9,I10');
check(($m['data']['name'] ?? '') === 'Marcel Janssens', 'I3 data.name');
check(($m['data']['gender'] ?? '') === 'M', 'I3 gender M');

// I1 (Désiré) has no parents_family in the fixture → no father/mother keys.
$d = $by['I1'];
check(!isset($d['rels']['father']) && !isset($d['rels']['mother']), 'I1 has no parent keys');
check(($d['data']['name'] ?? '') === 'Désiré Janssens', 'I1 diacritics preserved');

echo "\nSummary: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
