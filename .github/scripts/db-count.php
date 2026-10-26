<?php

/**
 * Tiny helper for the installer smoke test: run a COUNT query against the
 * SQLite database and print the result (-1 when the query fails).
 *
 * Usage: php db-count.php <path-to.sqlite> "<sql>"
 */
$path = $argv[1] ?? '';
$sql = $argv[2] ?? '';

try {
    $pdo = new PDO('sqlite:'.$path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo (int) $pdo->query($sql)->fetchColumn();
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage().PHP_EOL);

    echo -1;
}
