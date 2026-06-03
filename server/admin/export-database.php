<?php
declare(strict_types=1);
/**
 * export-database.php — Download SQL backup (admin only).
 * Works without mysqldump; streams CREATE + INSERT for restore in phpMyAdmin or CLI mysql.
 */

require_once __DIR__ . '/auth.php';
require_admin_session();

require_once __DIR__ . '/../api/helpers.php';
require_once __DIR__ . '/../api/link-common.php';
require_once __DIR__ . '/database-backup-lib.php';

$mainDriver = db_driver();
$useSqliteMain = $mainDriver === 'sqlite';

$mainSqliteResolved = '';
if ($useSqliteMain) {
    $mainSqliteResolved = admin_realpath_or_empty(admin_backup_sqlite_main_path());
}

$fname = 'edv-backup-' . gmdate('Y-m-d-His') . 'Z.sql';

header('Content-Type: application/sql; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

echo "-- Ecstatic Dance Viseu — backup SQL\n";
echo '-- Gerado em (UTC): ' . gmdate('Y-m-d H:i:s') . "\n";
echo "-- Importação: Admin → Importar backup, ou phpMyAdmin / CLI\n\n";

if ($useSqliteMain) {
    admin_backup_stream_pdo(db(), 'Eventos e bilhetes (ficheiro SQLite principal)');
} else {
    $label = $mainDriver === 'pgsql'
        ? 'Base PostgreSQL/Supabase (eventos, bilhetes e reservas /links se na mesma base)'
        : 'Base MySQL/MariaDB (eventos, bilhetes e reservas /links se na mesma base)';
    admin_backup_stream_pdo(db(), $label);
}

$linkBackend = link_registration_backend();
if ($linkBackend === 'json') {
    echo "\n-- Reservas /links: modo JSON local — exporta manualmente o ficheiro em LINK_JSON_PATH se precisares.\n";
    exit;
}

if (link_is_sqlite()) {
    $linkPath = admin_realpath_or_empty((string) LINK_SQLITE_PATH);
    if ($linkPath !== '' && is_readable(LINK_SQLITE_PATH)) {
        if ($useSqliteMain && $linkPath === $mainSqliteResolved) {
            echo "\n-- Reservas /links: mesmo ficheiro SQLite que a base principal — já incluído acima.\n";
        } else {
            admin_backup_stream_pdo(link_api_db(), 'Reservas /links (SQLite separado)');
        }
    }
} elseif ($useSqliteMain && !LINK_USE_JSON) {
    admin_backup_stream_pdo(link_api_db(), 'Reservas /links (SQL externo — cópia quando a base principal é SQLite)');
}

exit;
