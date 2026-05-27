<?php
/*
 * Shared auth helpers. Include at top of every protected page:
 *
 *     require_once __DIR__ . '/auth.php';
 *     requireAuth();
 *
 * Password hash lives in /stamboom/.password (gitignored). Reset with:
 *     php -r 'echo password_hash("YOUR-NEW-PASSWORD", PASSWORD_BCRYPT);' > .password
 */

function __stam_session_start(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start([
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
        ]);
    }
}

function isAuthed(): bool {
    __stam_session_start();
    return !empty($_SESSION['stam_authed']);
}

/** Redirect to login if not authenticated. Preserves the original URL in ?next=. */
function requireAuth(): void {
    if (isAuthed()) return;
    $next = $_SERVER['REQUEST_URI'] ?? '/';
    header('Location: index.php?next=' . urlencode($next));
    exit;
}

function loadPasswordHash(): ?string {
    $f = __DIR__ . '/.password';
    if (!file_exists($f)) return null;
    $h = trim(file_get_contents($f));
    return $h !== '' ? $h : null;
}

function authLogin(string $password): bool {
    __stam_session_start();
    $hash = loadPasswordHash();
    if ($hash === null) return false;
    if (!password_verify($password, $hash)) return false;
    session_regenerate_id(true);
    $_SESSION['stam_authed']    = true;
    $_SESSION['stam_loginTime'] = time();
    return true;
}

function authLogout(): void {
    __stam_session_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
                  $p['path'], $p['domain'] ?? '', $p['secure'] ?? false, $p['httponly'] ?? true);
    }
    session_destroy();
}
