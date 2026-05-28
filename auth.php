<?php
// v2 auth helpers. Pattern from /fin/, extended for per-user email+pwd login.
//
// Session vars after login (set by lib/users.php::authLogin):
//   stam_user_id   — email (primary key in users.json)
//   stam_indi_id   — bound INDI id (focal/hoofdpersoon)
//   stam_role      — "admin" | "user"
//   stam_loginTime

require_once __DIR__ . '/lib/users.php';

function __stam_session_start(): void {
    if (session_status() === PHP_SESSION_NONE) {
        $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
               || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
               || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        session_start([
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
            'cookie_secure'   => $secure,
        ]);
    }
}

function isAuthed(): bool {
    __stam_session_start();
    return !empty($_SESSION['stam_user_id']);
}

/** Redirect to login if not authenticated. Preserves the original URL in ?next=. */
function requireAuth(): void {
    if (isAuthed()) return;
    $next = $_SERVER['REQUEST_URI'] ?? '/';
    header('Location: index.php?next=' . urlencode($next));
    exit;
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

/**
 * Generate or fetch the per-session CSRF token. Forms include it as a hidden
 * field named `_csrf`; POST handlers compare with hash_equals.
 */
function csrfToken(): string {
    __stam_session_start();
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['_csrf'];
}

function csrfCheck(?string $supplied): void {
    __stam_session_start();
    $expected = $_SESSION['_csrf'] ?? '';
    if (!is_string($supplied) || $supplied === '' || !hash_equals($expected, $supplied)) {
        http_response_code(403);
        echo '<!DOCTYPE html><meta charset="UTF-8"><title>Geen toegang</title>'
           . '<p style="font-family:sans-serif;padding:40px">CSRF-controle mislukt.</p>';
        exit;
    }
}
