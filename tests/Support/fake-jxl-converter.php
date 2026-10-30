#!/usr/bin/env php
<?php

/**
 * Fake stand-in for the bundled `camerarawpreviews` binary, used only by the
 * unit tests. It is never shipped and never touches production code.
 *
 * Behaviour is driven by environment variables so one script can cover every
 * failure mode of the JXL conversion path:
 *
 *   FAKE_JXL_MODE     success (default) | fail | crash | empty | notjpeg |
 *                     old | validate | sleep
 *   FAKE_JXL_JPEG     path to the JPEG the fake should write on success
 *   FAKE_JXL_MARKER   optional path; a JSON record is written for every
 *                     `--jxl` invocation so tests can prove the converter was
 *                     (or was not) called and inspect the temp file it received
 *   FAKE_JXL_SLEEP    seconds to sleep in `sleep` mode (default 10); used by the
 *                     timeout test, which shortens the timeout via
 *                     CRP_JXL_TIMEOUT_SECONDS so the suite stays fast
 *
 * Invocation forms understood:
 *   fake --jxl <path>       JXL conversion (the new mode)
 *   fake --jxl=<path>       same, equals form
 *   fake <path>             legacy libraw render: this fake always fails
 *   fake --version          version probe
 */

$argv = $_SERVER['argv'] ?? [];
$behavior = getenv('FAKE_JXL_MODE') ?: 'success';
$marker = getenv('FAKE_JXL_MARKER') ?: '';
$jpegPath = getenv('FAKE_JXL_JPEG') ?: '';

$first = $argv[1] ?? '';

if ($first === '--version' || $first === '-V') {
    echo "camerarawpreviews 0.0.0-fake\n";
    exit(0);
}

$jxlPath = null;
if ($first === '--jxl') {
    $jxlPath = $argv[2] ?? null;
} elseif (str_starts_with($first, '--jxl=')) {
    $jxlPath = substr($first, strlen('--jxl='));
}

if ($jxlPath === null) {
    // Legacy `<bin> <input>` libraw invocation: this fake never renders RAWs.
    fwrite(STDERR, "fake: libraw rendering is not implemented\n");
    exit(2);
}

$data = @file_get_contents($jxlPath);
if ($data === false) {
    fwrite(STDERR, "fake: cannot read {$jxlPath}\n");
    exit(2);
}

if ($marker !== '') {
    $stat = @stat($jxlPath);
    file_put_contents($marker, json_encode([
        'sha1' => sha1($data),
        'size' => strlen($data),
        'path' => $jxlPath,
        'mode' => $stat !== false ? ($stat['mode'] & 0777) : null,
        'argv' => $argv,
        'behavior' => $behavior,
    ], JSON_UNESCAPED_SLASHES));
}

switch ($behavior) {
    case 'fail':
        fwrite(STDERR, "fake: forced non-zero exit\n");
        exit(1);
    case 'crash':
        fwrite(STDERR, "fake: forced crash\n");
        exit(139);
    case 'empty':
        exit(0);
    case 'notjpeg':
        fwrite(STDOUT, "this is definitely not a JPEG\n");
        exit(0);
    case 'old':
        // Simulates a binary built before --jxl existed.
        fwrite(STDERR, "fake: unknown option --jxl\n");
        exit(3);
    case 'validate':
        $isContainer = str_starts_with($data, "\x00\x00\x00\x0CJXL \x0D\x0A\x87\x0A");
        $isCodestream = str_starts_with($data, "\xFF\x0A");
        // A truncated stream keeps the magic but cannot be decoded. Requiring
        // a plausible minimum size lets the tests exercise a real decode
        // failure without the fake pretending to be a full decoder.
        if (!$isContainer && !$isCodestream) {
            fwrite(STDERR, "fake: input is not a JXL container or codestream\n");
            exit(2);
        }
        if (strlen($data) < 1000) {
            fwrite(STDERR, "fake: input is truncated\n");
            exit(2);
        }
        break;
    case 'sleep':
        // The marker above is already written, so the test can still prove the
        // converter was started. Sleep well past the (shortened) timeout so the
        // PHP side has to kill this child.
        sleep(max(1, (int)(getenv('FAKE_JXL_SLEEP') ?: 10)));
        break;
    case 'success':
        break;
    default:
        fwrite(STDERR, "fake: unknown FAKE_JXL_MODE {$behavior}\n");
        exit(3);
}

if ($jpegPath === '' || !is_file($jpegPath)) {
    fwrite(STDERR, "fake: FAKE_JXL_JPEG is not a file\n");
    exit(3);
}

$jpeg = file_get_contents($jpegPath);
if ($jpeg === false || $jpeg === '') {
    fwrite(STDERR, "fake: cannot read JPEG fixture\n");
    exit(3);
}

fwrite(STDOUT, $jpeg);
exit(0);
