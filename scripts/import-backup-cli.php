#!/usr/bin/env php
<?php
declare(strict_types=1);
/**
 * CLI restore from edv-backup-*.sql (export-database.php format).
 *
 * Usage: php scripts/import-backup-cli.php path/to/backup.sql
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from CLI only.\n");
    exit(1);
}

$path = $argv[1] ?? '';
if ($path === '' || !is_readable($path)) {
    fwrite(STDERR, "Usage: php scripts/import-backup-cli.php <backup.sql>\n");
    exit(1);
}

$root = dirname(__DIR__);
$config = $root . '/server/api/config.php';
if (!is_readable($config)) {
    fwrite(STDERR, "Missing server/api/config.php — copy from config.example.php first.\n");
    exit(1);
}

require_once $config;
require_once $root . '/server/api/helpers.php';
require_once $root . '/server/admin/database-backup-lib.php';

$sql = file_get_contents($path);
if (!is_string($sql) || trim($sql) === '') {
    fwrite(STDERR, "Backup file is empty.\n");
    exit(1);
}

$result = admin_backup_import_uploaded_sql($sql);
$ok = (bool) ($result['ok'] ?? false);
echo ($ok ? "OK: " : "FAILED: ") . ($result['message'] ?? '') . "\n";
exit($ok ? 0 : 1);
