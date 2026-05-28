<?php
// v2 augmentation layer. Loads augment.json once per request into a static
// cache. Each individual's augmentation is merged onto the tree-derived
// view at render time.
//
//   stam_augment_for($indi_id)  → array with keys email/facebook/linkedin/
//                                 instagram/bio/updated_at/updated_by
//                                 (any missing keys default to '' / null)
//   stam_augment_save($id, $fields, $updated_by)  — writes augment.json

require_once __DIR__ . '/jsonstore.php';

const STAM_AUGMENT_PATH = __DIR__ . '/../augment.json';

function stam_augment_load(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $d = stam_json_read(STAM_AUGMENT_PATH);
    $cache = $d['augmentations'] ?? [];
    return $cache;
}

function stam_augment_for(string $indi_id): array {
    $aug = stam_augment_load();
    $e = $aug[$indi_id] ?? [];
    return [
        'email'      => $e['email']      ?? '',
        'facebook'   => $e['facebook']   ?? '',
        'linkedin'   => $e['linkedin']   ?? '',
        'instagram'  => $e['instagram']  ?? '',
        'bio'        => $e['bio']        ?? '',
        'updated_at' => $e['updated_at'] ?? null,
        'updated_by' => $e['updated_by'] ?? null,
    ];
}

/** Returns true if at least one user-visible augmentation field is non-empty. */
function stam_augment_has_any(string $indi_id): bool {
    $a = stam_augment_for($indi_id);
    return $a['email'] !== ''
        || $a['facebook'] !== ''
        || $a['linkedin'] !== ''
        || $a['instagram'] !== ''
        || $a['bio'] !== '';
}

/**
 * Write augmentation for $indi_id. $fields is the validated, sanitized
 * association array; $updated_by is the editor's email.
 *
 * Note: callers should redirect (302) to a fresh GET after saving, since
 * stam_augment_load() caches statically within a request.
 */
function stam_augment_save(string $indi_id, array $fields, string $updated_by): void {
    $stamped = [
        'email'      => (string)($fields['email']     ?? ''),
        'facebook'   => (string)($fields['facebook']  ?? ''),
        'linkedin'   => (string)($fields['linkedin']  ?? ''),
        'instagram'  => (string)($fields['instagram'] ?? ''),
        'bio'        => (string)($fields['bio']       ?? ''),
        'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'updated_by' => $updated_by,
    ];
    stam_json_mutate(STAM_AUGMENT_PATH, function (array $s) use ($indi_id, $stamped) {
        if (!isset($s['augmentations'])) $s['augmentations'] = [];
        $s['augmentations'][$indi_id] = $stamped;
        return $s;
    });
}
