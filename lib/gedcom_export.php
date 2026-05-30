<?php
/*
 * Server-side bridge to tools/export_augment.py. Produces an augmented GEDCOM
 * in the system temp dir; the caller streams it and deletes it. No HTTP, no
 * auth, no output here — that is the endpoint's job.
 */

/**
 * Find a working Python 3 interpreter. Validates that `<cand> --version`
 * actually prints "Python 3." — this rejects the Windows Store stub alias
 * (which exits but prints "Python was not found"). Result is cached.
 */
function stam_python_bin(): string {
    static $bin = null;
    if ($bin !== null) return $bin;
    foreach (['/usr/bin/python3', 'python3', 'python', 'py'] as $cand) {
        $descr = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $p = @proc_open([$cand, '--version'], $descr, $pipes);
        if (!is_resource($p)) continue;
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $code = proc_close($p);
        if ($code === 0 && preg_match('/^Python 3\./', ltrim($out))) {
            $bin = $cand;
            return $bin;
        }
    }
    $bin = '/usr/bin/python3';  // sensible production fallback
    return $bin;
}

/**
 * Run the export tool. Returns [true, $tmpPath] on success (caller must
 * unlink) or [false, $dutchError] on failure (no temp file leaked).
 */
function stam_export_augmented_to_tmp(string $gedPath, string $augmentPath,
                                      string $toolPath, ?string $python = null): array {
    foreach (['GEDCOM' => $gedPath, 'augmentatie' => $augmentPath, 'export-tool' => $toolPath] as $what => $path) {
        if (!is_file($path) || !is_readable($path)) {
            return [false, "Exportbron niet gevonden of onleesbaar ($what)."];
        }
    }
    $python = $python ?? stam_python_bin();
    $tmp = tempnam(sys_get_temp_dir(), 'stamboom_ged_');
    if ($tmp === false) {
        return [false, 'Kon geen tijdelijk bestand aanmaken.'];
    }

    $cmd = [$python, $toolPath, $gedPath, '--augment', $augmentPath, '--out', $tmp];
    $descr = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descr, $pipes);
    if (!is_resource($proc)) {
        @unlink($tmp);
        return [false, 'Kon het export-proces niet starten.'];
    }
    stream_get_contents($pipes[1]); fclose($pipes[1]);   // tool prints a summary; ignore
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $code = proc_close($proc);

    if ($code !== 0 || !is_file($tmp) || filesize($tmp) === 0) {
        @unlink($tmp);
        return [false, 'Export mislukt (exitcode ' . $code . '): ' . trim($stderr)];
    }
    return [true, $tmp];
}
