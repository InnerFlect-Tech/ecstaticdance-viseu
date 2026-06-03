<?php
declare(strict_types=1);
/**
 * Shared SQL backup export + restore (used by export-database.php and import-database.php).
 */

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

/** @return list<string> */
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

/** @return list<string> */
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

/** @return list<string> */
function admin_backup_column_names(PDO $pdo, string $driver, string $table): array {
    if ($driver === 'mysql') {
        $t    = str_replace('`', '', $table);
        $stmt = $pdo->query('SHOW COLUMNS FROM `' . $t . '`');
        $cols = [];
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
    $tq   = '"' . str_replace('"', '""', $table) . '"';
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
    $qTable  = admin_backup_quote_table_pgsql($table);
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
        $colName    = admin_backup_quote_table_pgsql((string) ($row['column_name'] ?? ''));
        $dataType   = (string) ($row['data_type'] ?? 'text');
        $nullable   = ((string) ($row['is_nullable'] ?? 'YES')) === 'YES' ? '' : ' NOT NULL';
        $default    = $row['column_default'];
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

function admin_backup_strip_sql_comments(string $sql): string {
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql) ?? $sql;

    return preg_replace('/--[^\n]*(\n|$)/', "\n", $sql) ?? $sql;
}

/**
 * @return list<string>
 */
function admin_backup_split_sql_statements(string $sql): array {
    $sql         = admin_backup_strip_sql_comments($sql);
    $statements  = [];
    $buf         = '';
    $inString    = false;
    $escapeNext  = false;
    $quoteChar   = '';
    $len         = strlen($sql);

    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];
        if ($inString) {
            $buf .= $c;
            if ($escapeNext) {
                $escapeNext = false;
                continue;
            }
            if ($c === '\\') {
                $escapeNext = true;
                continue;
            }
            if ($c === $quoteChar) {
                $inString = false;
            }
            continue;
        }
        if ($c === "'" || $c === '"') {
            $inString  = true;
            $quoteChar = $c;
            $buf .= $c;
            continue;
        }
        if ($c === ';') {
            $stmt = trim($buf);
            if ($stmt !== '') {
                $statements[] = $stmt;
            }
            $buf = '';
            continue;
        }
        $buf .= $c;
    }

    $tail = trim($buf);
    if ($tail !== '') {
        $statements[] = $tail;
    }

    return $statements;
}

/**
 * @return list<array{label: string, sql: string}>
 */
function admin_backup_split_import_sections(string $fullSql): array {
    $fullSql = str_replace("\r\n", "\n", $fullSql);
    if (!preg_match_all(
        '/\n-- ═{10,}\n-- ([^\n]+)\n-- ═{10,}\n/u',
        $fullSql,
        $matches,
        PREG_OFFSET_CAPTURE
    ) || $matches[0] === []) {
        return [['label' => 'backup', 'sql' => $fullSql]];
    }

    /** @var list<array{label: string, sql: string}> $sections */
    $sections = [];
    $n        = count($matches[0]);
    for ($i = 0; $i < $n; $i++) {
        $label     = trim((string) $matches[1][$i][0]);
        $bodyStart = (int) $matches[0][$i][1] + strlen((string) $matches[0][$i][0]);
        $bodyEnd   = $i + 1 < $n ? (int) $matches[0][$i + 1][1] : strlen($fullSql);
        $body      = substr($fullSql, $bodyStart, $bodyEnd - $bodyStart);
        if ($label === '' || trim($body) === '') {
            continue;
        }
        if (stripos($label, 'já incluído') !== false) {
            continue;
        }
        if (stripos($body, 'modo JSON local') !== false) {
            continue;
        }
        $sections[] = ['label' => $label, 'sql' => $body];
    }

    if ($sections === []) {
        return [['label' => 'backup', 'sql' => $fullSql]];
    }

    return $sections;
}

function admin_backup_section_target(string $label): string {
    if (stripos($label, 'links') !== false || stripos($label, 'Reservas') !== false) {
        return 'link';
    }

    return 'main';
}

function admin_backup_sqlite_main_path(): string {
    if (defined('MAIN_DB_SQLITE_PATH') && MAIN_DB_SQLITE_PATH !== '') {
        return (string) MAIN_DB_SQLITE_PATH;
    }

    return dirname(__DIR__) . '/data/events-tickets.sqlite';
}

function admin_backup_driver_matches_sql(string $sql, string $driver): bool {
    $hasSqlite = (bool) preg_match('/AUTOINCREMENT|INTEGER PRIMARY KEY/i', $sql);
    $hasMysql  = (bool) preg_match('/ENGINE\s*=\s*InnoDB|`events`/i', $sql);
    $hasPgsql  = (bool) preg_match('/SERIAL PRIMARY KEY|::/i', $sql);

    if ($driver === 'sqlite') {
        return $hasSqlite || (!$hasMysql && !$hasPgsql);
    }
    if ($driver === 'mysql') {
        return $hasMysql || (!$hasSqlite && !$hasPgsql);
    }
    if ($driver === 'pgsql') {
        return $hasPgsql || (!$hasSqlite && !$hasMysql);
    }

    return false;
}

/**
 * @return array{ok: bool, label: string, executed: int, skipped: int, error: string|null}
 */
function admin_backup_import_sql(PDO $pdo, string $sql, string $label): array {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if (!admin_backup_driver_matches_sql($sql, $driver)) {
        return [
            'ok'       => false,
            'label'    => $label,
            'executed' => 0,
            'skipped'  => 0,
            'error'    => 'O ficheiro SQL não corresponde ao motor desta instalação (' . $driver . '). Exporta e importa no mesmo tipo de base.',
        ];
    }

    $statements = admin_backup_split_sql_statements($sql);
    $executed   = 0;
    $skipped    = 0;

    try {
        if ($driver === 'sqlite') {
            $pdo->exec('PRAGMA foreign_keys = OFF');
        }
        $pdo->beginTransaction();

        foreach ($statements as $stmt) {
            $upper = strtoupper(ltrim($stmt));
            if ($upper === '') {
                continue;
            }
            if (str_starts_with($upper, 'PRAGMA FOREIGN_KEYS')
                || str_starts_with($upper, 'SET FOREIGN_KEY_CHECKS')
                || str_starts_with($upper, 'SET NAMES')
                || str_starts_with($upper, 'SET SEARCH_PATH')) {
                $pdo->exec($stmt);
                $executed++;
                continue;
            }
            if (preg_match('/^--/', $stmt)) {
                $skipped++;
                continue;
            }
            $pdo->exec($stmt);
            $executed++;
        }

        $pdo->commit();
        if ($driver === 'sqlite') {
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return [
            'ok'       => false,
            'label'    => $label,
            'executed' => $executed,
            'skipped'  => $skipped,
            'error'    => $e->getMessage(),
        ];
    }

    return [
        'ok'       => true,
        'label'    => $label,
        'executed' => $executed,
        'skipped'  => $skipped,
        'error'    => null,
    ];
}

/**
 * @return array{ok: bool, message: string, sections: list<array<string, mixed>>}
 */
function admin_backup_import_uploaded_sql(string $fullSql): array {
    require_once __DIR__ . '/../api/link-common.php';

    $sections = admin_backup_split_import_sections($fullSql);
    /** @var list<array<string, mixed>> $results */
    $results = [];

    $mainSqlite = admin_realpath_or_empty(admin_backup_sqlite_main_path());
    $linkSqlite = '';
    if (function_exists('link_is_sqlite') && link_is_sqlite()) {
        $linkSqlite = admin_realpath_or_empty((string) LINK_SQLITE_PATH);
    }

    foreach ($sections as $sec) {
        $label  = $sec['label'];
        $sql    = $sec['sql'];
        $target = admin_backup_section_target($label);

        if ($target === 'link') {
            if (link_registration_backend() === 'json') {
                $results[] = [
                    'ok'      => true,
                    'label'   => $label,
                    'skipped' => true,
                    'message' => 'Reservas /links em modo JSON — secção ignorada.',
                ];
                continue;
            }
            if ($mainSqlite !== '' && $linkSqlite !== '' && $mainSqlite === $linkSqlite) {
                $target = 'main';
            }
        }

        try {
            $pdo = $target === 'link' ? link_api_db() : db();
        } catch (Throwable $e) {
            $results[] = [
                'ok'    => false,
                'label' => $label,
                'error' => $e->getMessage(),
            ];
            continue;
        }

        $results[] = admin_backup_import_sql($pdo, $sql, $label);
    }

    $allOk = true;
    foreach ($results as $r) {
        if (($r['ok'] ?? false) !== true) {
            $allOk = false;
            break;
        }
    }

    $messages = [];
    foreach ($results as $r) {
        if (!empty($r['skipped'])) {
            $messages[] = (string) ($r['message'] ?? $r['label']);
            continue;
        }
        if (($r['ok'] ?? false) === true) {
            $messages[] = sprintf(
                '%s: %d comandos executados.',
                (string) ($r['label'] ?? 'Secção'),
                (int) ($r['executed'] ?? 0)
            );
        } else {
            $messages[] = sprintf(
                '%s: falhou — %s',
                (string) ($r['label'] ?? 'Secção'),
                (string) ($r['error'] ?? 'erro desconhecido')
            );
        }
    }

    return [
        'ok'       => $allOk,
        'message'  => implode(' ', $messages),
        'sections' => $results,
    ];
}

/**
 * Replace main SQLite file from upload (SQLite deployments only).
 *
 * @return array{ok: bool, message: string}
 */
function admin_backup_import_sqlite_file(string $tmpPath): array {
    if (db_driver() !== 'sqlite') {
        return ['ok' => false, 'message' => 'Substituição de ficheiro .sqlite só está disponível quando a base principal é SQLite.'];
    }

    $dest = admin_backup_sqlite_main_path();
    $dir  = dirname($dest);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return ['ok' => false, 'message' => 'Não foi possível criar o directório da base de dados.'];
    }

    $probe = new PDO('sqlite:' . $tmpPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $probe->query('SELECT 1 FROM sqlite_master LIMIT 1');

    if (is_file($dest)) {
        $bak = $dest . '.bak-' . gmdate('Y-m-d-His') . 'Z';
        if (!@copy($dest, $bak)) {
            return ['ok' => false, 'message' => 'Não foi possível criar cópia de segurança antes de substituir.'];
        }
    }

    if (!@copy($tmpPath, $dest)) {
        return ['ok' => false, 'message' => 'Não foi possível gravar o ficheiro SQLite.'];
    }

    return [
        'ok'      => true,
        'message' => 'Ficheiro SQLite substituído. Recarrega a página para usar os novos dados.',
    ];
}
