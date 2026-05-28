<?php
// Per-field validators. Each returns [$ok, $sanitized_value_or_dutch_error_string].
//
//     [$ok, $val] = stam_v_email($input);
//     if (!$ok) { /* $val is a Dutch error */ }

/** Email: trim + lowercase. */
function stam_v_email(?string $s): array {
    $s = strtolower(trim((string)$s));
    if ($s === '') return [false, 'E-mailadres is verplicht.'];
    if (strlen($s) > 254) return [false, 'E-mailadres is te lang.'];
    if (!filter_var($s, FILTER_VALIDATE_EMAIL)) return [false, 'Ongeldig e-mailadres.'];
    return [true, $s];
}

/** Password for signup/reset. Returns the raw string on success (will be hashed by caller). */
function stam_v_password(?string $s, ?string $email = null): array {
    $s = (string)$s;
    if (strlen($s) < 8)  return [false, 'Wachtwoord moet minstens 8 tekens lang zijn.'];
    if (strlen($s) > 256) return [false, 'Wachtwoord is te lang (max 256 tekens).'];
    if ($email !== null && strtolower($s) === strtolower($email)) {
        return [false, 'Wachtwoord mag niet gelijk zijn aan je e-mailadres.'];
    }
    return [true, $s];
}

/** Validate a social URL. $domains is the set of acceptable hostnames. */
function stam_v_social_url(?string $s, array $domains, string $service): array {
    $s = trim((string)$s);
    if ($s === '') return [true, '']; // Empty is OK — the field is optional.
    $p = @parse_url($s);
    if (!$p || empty($p['scheme']) || empty($p['host'])) {
        return [false, "Ongeldige $service-link."];
    }
    if ($p['scheme'] !== 'https') {
        return [false, "$service-link moet beginnen met https://."];
    }
    $host = strtolower($p['host']);
    foreach ($domains as $d) {
        if ($host === $d || str_ends_with($host, '.' . $d)) {
            return [true, $s];
        }
    }
    return [false, "$service-link moet naar " . $domains[0] . " wijzen."];
}

function stam_v_facebook(?string $s): array {
    return stam_v_social_url($s, ['facebook.com', 'fb.com'], 'Facebook');
}
function stam_v_linkedin(?string $s): array {
    return stam_v_social_url($s, ['linkedin.com'], 'LinkedIn');
}
function stam_v_instagram(?string $s): array {
    return stam_v_social_url($s, ['instagram.com'], 'Instagram');
}

/** Bio: plain text, ≤4096 chars. Truncate-with-warn rather than reject. */
function stam_v_bio(?string $s): array {
    $s = (string)$s;
    // Strip control chars except \n and \t.
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
    if (mb_strlen($s) > 4096) {
        $s = mb_substr($s, 0, 4096);
        return [true, $s];   // Truncated; caller can choose to surface a warning.
    }
    return [true, $s];
}

/** Indi id (e.g. "I123") — must look like @I\d+@ stripped of @s. */
function stam_v_indi_id(?string $s): array {
    $s = trim((string)$s);
    if (!preg_match('/^[A-Z]\d{1,8}$/', $s)) {
        return [false, 'Ongeldig persoon-ID.'];
    }
    return [true, $s];
}

/** Optional URL fields treat empty as valid; required ones must reject empty. */
function stam_v_email_optional(?string $s): array {
    $s = strtolower(trim((string)$s));
    if ($s === '') return [true, ''];
    return stam_v_email($s);
}

/** Mobile/phone (optional). Permissive: empty OK, else 7-25 chars of digits/spaces/+/-/parens. */
function stam_v_mobile_optional(?string $s): array {
    $s = trim((string)$s);
    if ($s === '') return [true, ''];
    if (!preg_match('/^[\d\s+()\-]{7,25}$/', $s)) {
        return [false, 'Ongeldig telefoonnummer (gebruik cijfers, spaties, +, -, of haakjes).'];
    }
    return [true, $s];
}
