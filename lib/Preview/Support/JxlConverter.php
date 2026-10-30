<?php

namespace OCA\CameraRawPreviews\Preview\Support;

/**
 * Converts embedded JPEG XL bytes to JPEG with the bundled `camerarawpreviews`
 * binary.
 *
 * The binary's `--jxl <path>` mode decodes a bare JPEG XL codestream or a JXL
 * container and writes a baseline JPEG to stdout. The candidate bytes are
 * handed over in a short-lived owner-only temp file (the binary reads a path,
 * not stdin) which is removed in a `finally` block whether the conversion
 * succeeds, fails or times out.
 *
 * A non-empty CRP_CLI_PATH is an exclusive override: it is used instead of the
 * bundled binary even when it is unusable, which disables JXL conversion. That
 * lets tests substitute a fake converter. The libraw fallback keeps its own
 * resolution order (see RawCliRenderer).
 */
class JxlConverter
{
    /** Don't hang a preview request forever on a wedged decoder. */
    private const DEFAULT_TIMEOUT_SECONDS = 30;

    /**
     * Convert JPEG XL bytes to JPEG bytes.
     *
     * Returns null on any failure — binary missing/not executable, timeout,
     * non-zero exit, empty output, or output that is not a JPEG.
     *
     * @param string $jxlBytes Raw JPEG XL container or codestream bytes.
     * @return string|null JPEG bytes, or null if no conversion happened.
     */
    public static function convert(string $jxlBytes): ?string
    {
        if ($jxlBytes === '' || !function_exists('proc_open')) {
            return null;
        }

        $bin = self::binaryPath();
        if ($bin === null) {
            return null;
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'crp-jxl-');
        if ($tmpPath === false) {
            return null;
        }

        try {
            // tempnam() already creates the file owner-only; chmod makes that
            // explicit rather than relying on the platform's default mode.
            @chmod($tmpPath, 0600);
            if (@file_put_contents($tmpPath, $jxlBytes) !== strlen($jxlBytes)) {
                return null;
            }
            return self::run($bin, $tmpPath);
        } finally {
            @unlink($tmpPath);
        }
    }

    /**
     * Run the converter and collect stdout, killing it at the deadline.
     */
    private static function run(string $bin, string $jxlPath): ?string
    {
        $descriptors = [
            0 => ['pipe', 'r'], // stdin (unused)
            1 => ['pipe', 'w'], // stdout — the JPEG
            2 => ['pipe', 'w'], // stderr — diagnostics, ignored
        ];

        // Pass argv as an array so there is no shell and nothing to quote/escape.
        $proc = @proc_open([$bin, '--jxl', $jxlPath], $descriptors, $pipes);
        if (!is_resource($proc)) {
            return null;
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $deadline = microtime(true) + self::timeoutSeconds();
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

        // Trust only a real, parseable JPEG. getResolution() rejects non-JPEG
        // output as well as single-channel (raw sensor) JPEGs.
        return JpegValidator::getResolution($stdout) !== null ? $stdout : null;
    }

    /**
     * Resolve the converter binary, or null when it is unusable.
     *
     * CRP_CLI_PATH is exclusive: when set, the bundled binary is not consulted
     * at all, even if the override points nowhere. Without it, the bundled
     * binary for the host architecture is used.
     */
    private static function binaryPath(): ?string
    {
        $env = getenv('CRP_CLI_PATH');
        if (is_string($env) && $env !== '') {
            $path = trim($env);
        } else {
            $appRoot = dirname(__DIR__, 3); // lib/Preview/Support → app root
            $path = $appRoot . '/bin/rs-fallback-linux-' . self::hostArch();
        }

        return (is_file($path) && is_executable($path)) ? $path : null;
    }

    /**
     * Timeout in seconds. CRP_JXL_TIMEOUT_SECONDS exists so tests can exercise
     * the timeout path quickly; non-numeric or non-positive values fall back to
     * the production default.
     */
    private static function timeoutSeconds(): float
    {
        $env = getenv('CRP_JXL_TIMEOUT_SECONDS');
        if (is_string($env) && $env !== '' && is_numeric($env) && (float)$env > 0) {
            return (float)$env;
        }
        return self::DEFAULT_TIMEOUT_SECONDS;
    }

    private static function hostArch(): string
    {
        $raw = strtolower(trim((string)php_uname('m')));
        return match ($raw) {
            'x86_64', 'amd64' => 'x86_64',
            'aarch64', 'arm64' => 'aarch64',
            default => $raw,
        };
    }
}
