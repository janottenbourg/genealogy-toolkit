<?php
/*
 * Standalone permission tests for lib/users.php::canEdit().
 * Loads a fixture tree.json, simulates session state, asserts.
 *
 * Run:  php tests/test_can_edit.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
chdir($root);

// Build a fresh tree.json from sample.ged for the test.
$build = "py build.py sample.ged";
if (PHP_OS_FAMILY !== 'Windows') $build = "python3 build.py sample.ged";
shell_exec("$build > /dev/null 2>&1");

require __DIR__ . '/../lib/tree.php';
require __DIR__ . '/../lib/users.php';

$pass = 0; $fail = 0;
function check(bool $cond, string $label): void {
    global $pass, $fail;
    if ($cond) { echo "  PASS  $label\n"; $pass++; }
    else       { echo "  FAIL  $label\n"; $fail++; }
}

// --- Setup: session as Pieter (I9 in sample.ged — child of Marcel I3 and Elise I8) ---
session_id('test'); session_start();
$_SESSION['stam_user_id']  = 'pieter@test';
$_SESSION['stam_indi_id']  = 'I9';
$_SESSION['stam_role']     = 'user';

echo "==> User Pieter (I9) — first-degree = parents Marcel(I3) + Elise(I8) only (no kids, no partner in sample)\n";
check(canEdit('I9'),  'canEdit self (I9)');
check(canEdit('I3'),  'canEdit parent Marcel (I3)');
check(canEdit('I8'),  'canEdit parent Elise (I8)');
check(!canEdit('I10'),'!canEdit sibling Sophie (I10)');
check(!canEdit('I1'), '!canEdit grandparent Désiré (I1)');
check(!canEdit('I4'), '!canEdit aunt Anna (I4)');
check(!canEdit('I11'),'!canEdit unrelated Albert (I11)');

// --- Now Marcel (I3) — has parents I1+I2, partners I6+I8, children I7+I9+I10 ---
$_SESSION['stam_indi_id'] = 'I3';
echo "==> User Marcel (I3) — first-degree = parents I1,I2; partners I6,I8; children I7,I9,I10\n";
check(canEdit('I3'),  'canEdit self (I3)');
check(canEdit('I1'),  'canEdit parent I1');
check(canEdit('I2'),  'canEdit parent I2');
check(canEdit('I6'),  'canEdit partner I6');
check(canEdit('I8'),  'canEdit partner I8');
check(canEdit('I7'),  'canEdit child I7');
check(canEdit('I9'),  'canEdit child I9');
check(canEdit('I10'), 'canEdit child I10');
check(!canEdit('I4'), '!canEdit sibling Anna (I4)');
check(!canEdit('I11'),'!canEdit unrelated I11');

// --- Admin — can edit everyone ---
$_SESSION['stam_role'] = 'admin';
$_SESSION['stam_indi_id'] = 'I1';
echo "==> Admin (I1)\n";
check(canEdit('I1'),  'admin canEdit self');
check(canEdit('I11'), 'admin canEdit unrelated');
check(canEdit('I15'), 'admin canEdit minimal record');
check(!canEdit('I999'),'!canEdit non-existent person');

echo "\nSummary: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
