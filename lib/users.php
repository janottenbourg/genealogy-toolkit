<?php
/*
 * v2 account + permission helpers.
 *
 * Account record shape (in users.json["users"][email]):
 *   { password_hash, indi_id, role: "admin"|"user",
 *     created_at, last_login_at, reset_token, reset_expires_at }
 *
 * Session vars (set by authLogin):
 *   stam_user_id  — email (primary key)
 *   stam_indi_id  — bound INDI id (focal/hoofdpersoon)
 *   stam_role     — "admin" | "user"
 *   stam_loginTime
 */

require_once __DIR__ . '/jsonstore.php';
require_once __DIR__ . '/tree.php';

const STAM_USERS_PATH = __DIR__ . '/../users.json';

function stam_users_load(): array {
    $d = stam_json_read(STAM_USERS_PATH);
    return $d['users'] ?? [];
}

function stam_user_by_email(string $email): ?array {
    $email = strtolower(trim($email));
    $users = stam_users_load();
    return $users[$email] ?? null;
}

function stam_user_by_indi(string $indi_id): ?array {
    foreach (stam_users_load() as $email => $u) {
        if (($u['indi_id'] ?? null) === $indi_id) {
            return ['email' => $email] + $u;
        }
    }
    return null;
}

function currentUserEmail(): ?string {
    return $_SESSION['stam_user_id'] ?? null;
}

function currentRole(): ?string {
    return $_SESSION['stam_role'] ?? null;
}

function currentFocalId(): ?string {
    return $_SESSION['stam_indi_id'] ?? null;
}

/** Get the current user's full record from users.json (or null if not logged in). */
function currentUser(): ?array {
    $e = currentUserEmail();
    return $e ? stam_user_by_email($e) : null;
}

function requireAdmin(): void {
    if (currentRole() !== 'admin') {
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><meta charset="UTF-8"><title>Geen toegang</title>'
           . '<p style="font-family:sans-serif;padding:40px">Geen toegang.</p>';
        exit;
    }
}

/**
 * Single permission arbiter. Returns true iff the session user can edit
 * augmentation of $target_indi_id.
 *
 *   - Admin: always true for any existing INDI.
 *   - User: true iff target equals their own indi OR is first-degree relative
 *     (parent via FAMC, partner via FAMS, child via FAMS).
 *   - Anyone: false for an INDI that doesn't exist in tree.json.
 */
function canEdit(string $target_indi_id): bool {
    if (stam_individual($target_indi_id) === null) return false;

    $role  = currentRole();
    $focal = currentFocalId();
    if ($role === 'admin') return true;
    if ($role !== 'user' || $focal === null) return false;
    if ($target_indi_id === $focal) return true;

    foreach (stam_first_degree_of($focal) as $rid) {
        if ($rid === $target_indi_id) return true;
    }
    return false;
}

/**
 * First-degree relatives of $ind_id: parents (via parents_family) +
 * partners + children (via spouse_families). Returns an array of INDI ids
 * with no duplicates.
 */
function stam_first_degree_of(string $ind_id): array {
    $ind = stam_individual($ind_id);
    if (!$ind) return [];
    $out = [];

    // Parents
    if (!empty($ind['parents_family'])) {
        $pf = stam_family($ind['parents_family']);
        if ($pf) {
            foreach ([$pf['husband'] ?? null, $pf['wife'] ?? null] as $p) {
                if ($p && $p !== $ind_id) $out[$p] = true;
            }
        }
    }

    // Partners + children
    foreach ($ind['spouse_families'] ?? [] as $fid) {
        $f = stam_family($fid);
        if (!$f) continue;
        $partner = ($f['husband'] ?? null) === $ind_id ? ($f['wife'] ?? null) : ($f['husband'] ?? null);
        if ($partner) $out[$partner] = true;
        foreach ($f['children'] ?? [] as $cid) {
            if ($cid !== $ind_id) $out[$cid] = true;
        }
    }
    return array_keys($out);
}

/**
 * Verify pwd, set session. Returns true on success.
 * Updates last_login_at on success.
 */
function authLogin(string $email, string $password): bool {
    $email = strtolower(trim($email));
    $user  = stam_user_by_email($email);
    if (!$user) return false;
    if (!password_verify($password, $user['password_hash'])) return false;

    __stam_session_start();
    session_regenerate_id(true);
    $_SESSION['stam_user_id']  = $email;
    $_SESSION['stam_indi_id']  = $user['indi_id'];
    $_SESSION['stam_role']     = $user['role'];
    $_SESSION['stam_loginTime'] = time();

    // Update last_login_at (best-effort, non-fatal).
    try {
        stam_json_mutate(STAM_USERS_PATH, function (array $s) use ($email) {
            if (isset($s['users'][$email])) {
                $s['users'][$email]['last_login_at'] = gmdate('Y-m-d\TH:i:s\Z');
            }
            return $s;
        });
    } catch (Throwable $e) {
        error_log("stamboom: last_login_at update failed: " . $e->getMessage());
    }
    return true;
}

/** Create a user record. Caller is expected to have validated inputs already. */
function stam_user_create(string $email, string $password, string $indi_id, string $role): void {
    $email = strtolower(trim($email));
    $hash  = password_hash($password, PASSWORD_BCRYPT);
    stam_json_mutate(STAM_USERS_PATH, function (array $s) use ($email, $hash, $indi_id, $role) {
        if (!isset($s['users'])) $s['users'] = [];
        $s['users'][$email] = [
            'password_hash'    => $hash,
            'indi_id'          => $indi_id,
            'role'             => $role,
            'created_at'       => gmdate('Y-m-d\TH:i:s\Z'),
            'last_login_at'    => null,
            'reset_token'      => null,
            'reset_expires_at' => null,
        ];
        return $s;
    });
}

function stam_user_delete(string $email): void {
    $email = strtolower(trim($email));
    stam_json_mutate(STAM_USERS_PATH, function (array $s) use ($email) {
        unset($s['users'][$email]);
        return $s;
    });
}

function stam_user_set_role(string $email, string $role): void {
    $email = strtolower(trim($email));
    stam_json_mutate(STAM_USERS_PATH, function (array $s) use ($email, $role) {
        if (isset($s['users'][$email])) $s['users'][$email]['role'] = $role;
        return $s;
    });
}

function stam_user_set_password(string $email, string $new_password): void {
    $email = strtolower(trim($email));
    $hash  = password_hash($new_password, PASSWORD_BCRYPT);
    stam_json_mutate(STAM_USERS_PATH, function (array $s) use ($email, $hash) {
        if (isset($s['users'][$email])) {
            $s['users'][$email]['password_hash']    = $hash;
            $s['users'][$email]['reset_token']      = null;
            $s['users'][$email]['reset_expires_at'] = null;
        }
        return $s;
    });
}

/** Returns the new token. */
function stam_user_issue_reset(string $email, int $hours = 24): string {
    $email = strtolower(trim($email));
    $token = 'rst_' . bin2hex(random_bytes(16));
    $expires = gmdate('Y-m-d\TH:i:s\Z', time() + $hours * 3600);
    stam_json_mutate(STAM_USERS_PATH, function (array $s) use ($email, $token, $expires) {
        if (isset($s['users'][$email])) {
            $s['users'][$email]['reset_token']      = $token;
            $s['users'][$email]['reset_expires_at'] = $expires;
        }
        return $s;
    });
    return $token;
}

function stam_user_by_reset_token(string $token): ?array {
    $now = gmdate('Y-m-d\TH:i:s\Z');
    foreach (stam_users_load() as $email => $u) {
        if (!hash_equals((string)($u['reset_token'] ?? ''), $token)) continue;
        if (($u['reset_expires_at'] ?? '') < $now) continue;
        return ['email' => $email] + $u;
    }
    return null;
}
