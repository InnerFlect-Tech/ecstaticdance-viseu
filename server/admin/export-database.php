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

/**
 * @param mixed $val
 */
function admin_backup_sql_value(mixed $val): string {
    if ($val === null) {
        return 'NULL';
    }
    if (is_bool($val)) {
        return $val ? '1' : '0';
    }
    $s = (string) $val;

    return "'" . str_replace(["\\", "'", "\n", "\r"], ['\\\\', "\\'", '\\n', '\\r'], $s) . "'";
}

/**
 * @param list<string> $tables
 * @return list<string>
 */
function admin_backup_sort_tables(array $tables): array {
    $rank = static function (string $t): int {
        return match ($t) {
            'events' => 0,
            'tickets' => 1,
            'link_registrations' => 2,
            default => 9,
        };
    };
    usort(
        $tables,
        static function (string $a, string $b) use ($rank): int {
            $c = $rank($a) <=> $rank($b);

            return $c !== 0 ? $c : strcmp($a, $b);
        }
    );

    return $tables;
}

/** @return list<string> */
function admin_backup_mysql_tables(PDO $pdo): array {
    /** @var list<string> */
    $all = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

    return admin_backup_sort_tables($all);
}

/**
 * @return list<string>
 */
function admin_backup_pgsql_tables(PDO $pdo): array {
    $stmt = $pdo->query(
        "SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname = current_schema() ORDER BY tablename"
    );
    /** @var list<string|false>|false */
    $raw = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
    $names = [];
    foreach ($raw as $n) {
        if (is_string($n) && $n !== '') {
            $names[] = $n;
        }
    }

    return admin_backup_sort_tables($names);
}

/**
 * @return list<string>
 */
function admin_backup_sqlite_tables(PDO $pdo): array {
    $stmt = $pdo->query(
        "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
    );
    /** @var list<string|false>|false */
    $raw = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
    /** @var list<string> $names */
    $names = [];
    foreach ($raw as $n) {
        if (is_string($n) && $n !== '') {
            $names[] = $n;
        }
    }

    return admin_backup_sort_tables($names);
}

/**
 * @return list<string>
 */
function admin_backup_column_names(PDO $pdo, string $driver, string $table): array {
    if ($driver === 'mysql') {
        $t        = str_replace('`', '', $table);
        $stmt     = $pdo->query('SHOW COLUMNS FROM `' . $t . '`');
        $cols     = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $cols[] = (string) $row['Field'];
        }

        return $cols;
    }
    if ($driver === 'pgsql') {
        $stmt = $pdo->prepare(
            "SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? ORDER BY ordinal_position"
        );
        $stmt->execute([$table]);
        /** @var list<string|false>|false */
        $raw = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $cols = [];
        foreach ($raw as $col) {
            if (is_string($col) && $col !== '') {
                $cols[] = $col;
            }
        }
        return $cols;
    }
    $tq  = '"' . str_replace('"', '""', $table) . '"';
    $stmt = $pdo->query('PRAGMA table_info(' . $tq . ')');
    $cols = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $cols[] = (string) $row['name'];
    }

    return $cols;
}

function admin_backup_quote_table_mysql(string $table): string {
    return '`' . str_replace('`', '``', $table) . '`';
}

function admin_backup_quote_table_sqlite(string $table): string {
    return '"' . str_replace('"', '""', $table) . '"';
}

function admin_backup_quote_table_pgsql(string $table): string {
    return '"' . str_replace('"', '""', $table) . '"';
}

function admin_backup_stream_table_mysql(PDO $pdo, string $table): void {
    $qTable = admin_backup_quote_table_mysql($table);
    $create = $pdo->query('SHOW CREATE TABLE ' . $qTable)->fetch(PDO::FETCH_ASSOC);
    $ddl    = $create['Create Table'] ?? null;
    if (!is_string($ddl) || $ddl === '') {
        return;
    }
    echo "\n-- ── Table {$table} ──\n";
    echo 'DROP TABLE IF EXISTS ' . $qTable . ";\n";
    echo $ddl . ";\n\n";

    $cols = admin_backup_column_names($pdo, 'mysql', $table);
    if ($cols === []) {
        return;
    }
    $colList = implode(', ', array_map(
        static fn (string $c): string => admin_backup_quote_table_mysql($c),
        $cols
    ));
    $batch = 400;
    $off   = 0;
    while (true) {
        $sql = 'SELECT * FROM ' . $qTable . ' LIMIT ' . (int) $batch . ' OFFSET ' . (int) $off;
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            break;
        }
        foreach ($rows as $row) {
            $vals = [];
            foreach ($cols as $c) {
                $vals[] = admin_backup_sql_value($row[$c] ?? null);
            }
            echo 'INSERT INTO ' . $qTable . ' (' . $colList . ') VALUES (' . implode(', ', $vals) . ");\n";
        }
        $off += $batch;
    }
    echo "\n";
}

function admin_backup_stream_table_sqlite(PDO $pdo, string $table): void {
    $qTable = admin_backup_quote_table_sqlite($table);
    $ddl    = $pdo->query(
        'SELECT sql FROM sqlite_master WHERE type = \'table\' AND name = ' . $pdo->quote($table)
    )->fetchColumn();
    if (!is_string($ddl) || trim($ddl) === '') {
        return;
    }
    echo "\n-- ── Table {$table} ──\n";
    echo 'DROP TABLE IF EXISTS ' . $qTable . ";\n";
    echo $ddl . ";\n\n";

    $cols = admin_backup_column_names($pdo, 'sqlite', $table);
    if ($cols === []) {
        return;
    }
    $colList = implode(', ', array_map(
        static fn (string $c): string => admin_backup_quote_table_sqlite($c),
        $cols
    ));
    $batch = 400;
    $off   = 0;
    while (true) {
        $sql  = 'SELECT * FROM ' . $qTable . ' LIMIT ' . (int) $batch . ' OFFSET ' . (int) $off;
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            break;
        }
        foreach ($rows as $row) {
            $vals = [];
            foreach ($cols as $c) {
                $vals[] = admin_backup_sql_value($row[$c] ?? null);
            }
            echo 'INSERT INTO ' . $qTable . ' (' . $colList . ') VALUES (' . implode(', ', $vals) . ");\n";
        }
        $off += $batch;
    }
    echo "\n";
}

function admin_backup_stream_table_pgsql(PDO $pdo, string $table): void {
    $qTable = admin_backup_quote_table_pgsql($table);
    $colStmt = $pdo->prepare(
        "SELECT column_name, data_type, is_nullable, column_default
         FROM information_schema.columns
         WHERE table_schema = current_schema()
           AND table_name = ?
         ORDER BY ordinal_position"
    );
    $colStmt->execute([$table]);
    $colDefs = [];
    while ($row = $colStmt->fetch(PDO::FETCH_ASSOC)) {
        $colName = admin_backup_quote_table_pgsql((string) ($row['column_name'] ?? ''));
        $dataType = (string) ($row['data_type'] ?? 'text');
        $nullable = ((string) ($row['is_nullable'] ?? 'YES')) === 'YES' ? '' : ' NOT NULL';
        $default = $row['column_default'];
        $defaultSql = '';
        if ($default !== null && $default !== '') {
            $defaultSql = ' DEFAULT ' . (string) $default;
        }
        $colDefs[] = '  ' . $colName . ' ' . $dataType . $defaultSql . $nullable;
    }
    if ($colDefs === []) {
        return;
    }
    $ddl = "CREATE TABLE {$qTable} (\n" . implode(",\n", $colDefs) . "\n)";
    echo "\n-- ── Table {$table} ──\n";
    echo 'DROP TABLE IF EXISTS ' . $qTable . " CASCADE;\n";
    echo $ddl . ";\n\n";

    $cols = admin_backup_column_names($pdo, 'pgsql', $table);
    if ($cols === []) {
        return;
    }
    $colList = implode(', ', array_map(
        static fn (string $c): string => admin_backup_quote_table_pgsql($c),
        $cols
    ));
    $batch = 400;
    $off   = 0;
    while (true) {
        $sql  = 'SELECT * FROM ' . $qTable . ' LIMIT ' . (int) $batch . ' OFFSET ' . (int) $off;
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            break;
        }
        foreach ($rows as $row) {
            $vals = [];
            foreach ($cols as $c) {
                $vals[] = admin_backup_sql_value($row[$c] ?? null);
            }
            echo 'INSERT INTO ' . $qTable . ' (' . $colList . ') VALUES (' . implode(', ', $vals) . ");\n";
        }
        $off += $batch;
    }
    echo "\n";
}

function admin_backup_stream_pdo(PDO $pdo, string $sectionLabel): void {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    echo "\n-- ═══════════════════════════════════════════════════════\n";
    echo '-- ' . $sectionLabel . "\n";
    echo "-- ═══════════════════════════════════════════════════════\n";

    if ($driver === 'mysql') {
        echo "SET NAMES utf8mb4;\n";
        echo "SET FOREIGN_KEY_CHECKS = 0;\n";
        foreach (admin_backup_mysql_tables($pdo) as $t) {
            admin_backup_stream_table_mysql($pdo, $t);
        }
        echo "SET FOREIGN_KEY_CHECKS = 1;\n";

        return;
    }

    if ($driver === 'sqlite') {
        echo "PRAGMA foreign_keys = OFF;\n";
        foreach (admin_backup_sqlite_tables($pdo) as $t) {
            admin_backup_stream_table_sqlite($pdo, $t);
        }
        echo "PRAGMA foreign_keys = ON;\n";

        return;
    }
    if ($driver === 'pgsql') {
        echo "SET search_path = public;\n";
        foreach (admin_backup_pgsql_tables($pdo) as $t) {
            admin_backup_stream_table_pgsql($pdo, $t);
        }

        return;
    }

    echo '-- Driver não suportado: ' . htmlspecialchars($driver, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n";
}

function admin_realpath_or_empty(string $path): string {
    $rp = realpath($path);

    return is_string($rp) ? $rp : '';
}

$mainDriver = db_driver();
$useSqliteMain = $mainDriver === 'sqlite';

$mainSqliteResolved = '';
if ($useSqliteMain) {
    $mainSqliteResolved = admin_realpath_or_empty(
        defined('MAIN_DB_SQLITE_PATH') && MAIN_DB_SQLITE_PATH !== ''
            ? (string) MAIN_DB_SQLITE_PATH
            : dirname(__DIR__) . '/data/events-tickets.sqlite'
    );
}

$fname = 'edv-backup-' . gmdate('Y-m-d-His') . 'Z.sql';

header('Content-Type: application/sql; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

echo "-- Ecstatic Dance Viseu — backup SQL\n";
echo '-- Gerado em (UTC): ' . gmdate('Y-m-d H:i:s') . "\n";
echo "-- Importação: phpMyAdmin → Importar, ou `mysql ... < backup.sql`\n\n";

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
