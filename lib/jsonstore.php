<?php
/*
 * Locked, atomic read-modify-write for JSON state files.
 *
 *   stam_json_read($path)               — returns array or [] if file missing
 *   stam_json_mutate($path, $mutate)    — load, call $mutate($state), atomic write
 *
 * Uses flock(LOCK_EX) and tmp+rename for crash safety.
 */

function stam_json_read(string $path): array {
    if (!is_readable($path)) return [];
    $raw = file_get_contents($path);
    if ($raw === '' || $raw === false) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Mutate a JSON file under an exclusive lock. The callable receives the
 * decoded array and must return the new array to persist. If it returns
 * null, the file is left untouched.
 *
 *     stam_json_mutate($path, function (array $state): array {
 *         $state['users']['x@y'] = […];
 *         return $state;
 *     });
 */
function stam_json_mutate(string $path, callable $mutate): array {
    // Ensure the file exists so flock has something to grab.
    if (!file_exists($path)) {
        file_put_contents($path, "{}\n");
        @chmod($path, 0640);
    }

    $fp = fopen($path, 'c+');
    if ($fp === false) {
        throw new RuntimeException("stam_json_mutate: cannot open $path");
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        throw new RuntimeException("stam_json_mutate: cannot lock $path");
    }
    try {
        rewind($fp);
        $raw = stream_get_contents($fp);
        $state = ($raw === '' || $raw === false) ? [] : (json_decode($raw, true) ?: []);

        $new = $mutate($state);
        if ($new === null) return $state;

        $encoded = json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($encoded === false) {
            throw new RuntimeException("stam_json_mutate: json_encode failed");
        }
        $tmp = $path . '.new';
        if (file_put_contents($tmp, $encoded . "\n") === false) {
            throw new RuntimeException("stam_json_mutate: write tmp failed");
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("stam_json_mutate: rename failed");
        }
        @chmod($path, 0640);
        return $new;
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
