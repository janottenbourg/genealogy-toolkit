#!/usr/bin/env php
<?php
// One-shot CLI to seed the first admin account.
//
// Usage (interactive):
//   php tools/seed_admin.php
//
// Usage (flags, for CI/scripts):
//   php tools/seed_admin.php --email=admin@example.com --password=xxx --indi=I1
//
// Exit codes: 0 success, 2 input error, 3 file error.

declare(strict_types=1);

$root = dirname(__DIR__);
chdir($root);

require $root . '/lib/tree.php';
require $root . '/lib/users.php';
require $root . '/lib/validate.php';

// Parse --flag=val args.
$opts = ['email' => null, 'password' => null, 'indi' => null];
foreach (array_slice($argv, 1) as $a) {
    foreach ($opts as $k => $_) {
        if (str_starts_with($a, "--$k=")) {
            $opts[$k] = substr($a, strlen("--$k="));
        }
    }
}

function ask(string $prompt): string {
    fwrite(STDERR, $prompt);
    $line = fgets(STDIN);
    return $line === false ? '' : trim($line);
}
function ask_password(string $prompt): string {
    if (PHP_OS_FAMILY === 'Windows') {
        // No portable hidden-input on Windows from PHP. Just read line.
        return ask($prompt);
    }
    fwrite(STDERR, $prompt);
    system('stty -echo');
    $line = trim((string)fgets(STDIN));
    system('stty echo');
    fwrite(STDERR, "\n");
    return $line;
}

$email = $opts['email'] ?? ask("Admin email: ");
$pw    = $opts['password'] ?? ask_password("Admin password: ");
$indi  = $opts['indi']     ?? ask("Bind to INDI id [default: meta.root_id]: ");

[$ok, $email] = stam_v_email($email);
if (!$ok) { fwrite(STDERR, "$email\n"); exit(2); }

[$ok, $pw] = stam_v_password($pw, $email);
if (!$ok) { fwrite(STDERR, "$pw\n"); exit(2); }

if ($indi === '') $indi = stam_meta()['root_id'] ?? null;
if (!$indi)             { fwrite(STDERR, "No --indi given and no meta.root_id in tree.json\n"); exit(2); }
if (!stam_individual($indi)) { fwrite(STDERR, "INDI $indi not found in tree.json\n"); exit(2); }

if (stam_user_by_email($email))  { fwrite(STDERR, "Account already exists for $email\n"); exit(2); }
if (stam_user_by_indi($indi))    { fwrite(STDERR, "Person $indi already has an account\n"); exit(2); }

stam_user_create($email, $pw, $indi, 'admin');
fwrite(STDOUT, "Admin account created: $email → $indi\n");
exit(0);
