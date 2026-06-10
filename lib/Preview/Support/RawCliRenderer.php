<?php

namespace OCA\CameraRawPreviews\Preview\Support;

/**
 * Runs the `camerarawpreviews` CLI — a native binary that renders a JPEG
 * preview from a true RAW using libraw (via the Rust raw_preview_rs crate).
 * It is the last-resort fallback used only when the pure-PHP pipeline
 * (embedded JPEG / MRW / InDesign / Imagick TIFF) could not produce anything.
 *
 * The binary is bundled with the app in <app-root>/bin/ for each supported
 * architecture. Until a usable binary is found, every call here returns null
 * without doing anything, so the app behaves exactly as before.
 *
 * Invocation contract: `camerarawpreviews <input>` writes the JPEG preview to
 * stdout and exits 0; a non-zero exit means no preview. The JPEG it returns is
 * already upright — libraw bakes in the orientation — so callers must NOT
 * orient it again.
 */
class RawCliRenderer
{
    /** Don't hang a preview request forever on a wedged decoder. */
    private const TIMEOUT_SECONDS = 30;

    /**
     * Render a preview for $filePath by running the CLI.
     *
     * Returns the JPEG bytes on success, or null on any failure — binary not
     * installed/executable, timeout, non-zero exit, empty output, or a result
     * that isn't actually a JPEG.
     *
     * @param string $filePath Absolute path to the source RAW.
     * @return string|null Upright JPEG bytes, or null if no preview was produced.
     */
    public static function renderPreview(string $filePath): ?string
    {
        $bin = self::binaryPath();
        if ($bin === null || !function_exists('proc_open')) {
            return null;
        }
        if (!is_file($filePath) || !is_readable($filePath)) {
            return null;
        }

        $descriptors = [
            0 => ['pipe', 'r'], // stdin (unused)
            1 => ['pipe', 'w'], // stdout — the JPEG
            2 => ['pipe', 'w'], // stderr — diagnostics, ignored
        ];

        // Pass argv as an array so there is no shell and nothing to quote/escape.
        $proc = @proc_open([$bin, $filePath], $descriptors, $pipes);
        if (!is_resource($proc)) {
            return null;
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;
        // The first proc_get_status() that observes the child as stopped is the
        // only one that reports its true exit code; proc_close() afterwards
        // returns -1. So capture the code here rather than from proc_close.
        $exit = -1;
        $timedOut = false;

        while (true) {
            $status = proc_get_status($proc);
            $chunk = stream_get_contents($pipes[1]);
            if ($chunk !== false && $chunk !== '') {
                $stdout .= $chunk;
            }
            // Drain stderr so the child can't block on a full pipe.
            @stream_get_contents($pipes[2]);

            if (!$status['running']) {
                $exit = $status['exitcode'];
                break;
            }
            if (microtime(true) >= $deadline) {
                proc_terminate($proc, 9);
                $timedOut = true;
                break;
            }
            usleep(20000); // 20ms
        }

        // Final drain after exit.
        $tail = stream_get_contents($pipes[1]);
        if ($tail !== false && $tail !== '') {
            $stdout .= $tail;
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        if ($timedOut || $exit !== 0 || $stdout === '') {
            return null;
        }

        // Sanity-check the result really is a JPEG (SOI marker).
        if (substr($stdout, 0, 2) !== "\xFF\xD8") {
            return null;
        }

        return $stdout;
    }

    /**
     * Resolve a usable binary path, or null when none is found/executable.
     *
     * Resolution order (first usable wins):
     *   1. Bundled binary in <app-root>/bin/camerarawpreviews-linux-<arch>
     *   2. CRP_CLI_PATH environment variable (useful for development/testing)
     */
    public static function binaryPath(): ?string
    {
        foreach (self::candidates() as $path) {
            if (is_file($path) && is_executable($path)) {
                return $path;
            }
        }
        return null;
    }

    /**
     * @return string[]
     */
    private static function candidates(): array
    {
        $raw = strtolower(trim((string)php_uname('m')));
        $arch = match ($raw) {
            'x86_64', 'amd64'   => 'x86_64',
            'aarch64', 'arm64'  => 'aarch64',
            default             => $raw,
        };
        $candidates = [];

        // 1. Bundled binary shipped with the app.
        $appRoot = dirname(__DIR__, 3); // lib/Preview/Support → app root
        $candidates[] = $appRoot . '/bin/rs-fallback-linux-' . $arch;

        // 2. Developer/testing override via environment variable.
        $env = getenv('CRP_CLI_PATH');
        if (is_string($env) && $env !== '') {
            $candidates[] = trim($env);
        }

        return $candidates;
    }
}
