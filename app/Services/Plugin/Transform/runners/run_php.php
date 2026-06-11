<?php

declare(strict_types=1);

/*
 * Trusted runner executed inside the PHP sandbox.
 *
 * I/O contract (shared across languages):
 *   - argv[1]: unique sentinel marker that prefixes the result on stdout
 *   - argv[2]: absolute path to the user's code file
 *   - stdin:   JSON-encoded input payload
 *   - stdout:  <marker><result JSON>  (user echo/noise is buffered and discarded)
 *   - stderr:  diagnostics; non-zero exit signals failure
 */

$marker = $argv[1] ?? '';
$userPath = $argv[2] ?? '';

if ($marker === '' || $userPath === '' || ! is_file($userPath)) {
    fwrite(STDERR, "runner misconfigured\n");
    exit(1);
}

$raw = stream_get_contents(STDIN);
if ($raw === false || $raw === '') {
    $raw = 'null';
}

try {
    $input = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    fwrite(STDERR, 'failed to decode input JSON: '.$e->getMessage()."\n");
    exit(1);
}

ob_start();
require $userPath;
ob_end_clean();

if (function_exists('run')) {
    $fn = 'run';
} elseif (function_exists('transform')) {
    $fn = 'transform';
} else {
    fwrite(STDERR, "run() is not defined\n");
    exit(1);
}

try {
    ob_start();
    $output = $fn($input);
    ob_end_clean();
} catch (Throwable $e) {
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n");
    exit(1);
}

$json = json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    fwrite(STDERR, 'json_encode failed: '.json_last_error_msg()."\n");
    exit(1);
}

fwrite(STDOUT, $marker.$json);
