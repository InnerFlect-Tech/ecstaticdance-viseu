<?php
/**
 * Campos de apresentação do evento activo (/links, meta público).
 */
declare(strict_types=1);

/** @var list<array{name:string,type:string}> */
const EDV_EVENT_DISPLAY_COLUMNS = [
    ['name' => 'doors_close', 'type' => 'TEXT'],
    ['name' => 'dance_start', 'type' => 'TEXT'],
    ['name' => 'dance_end', 'type' => 'TEXT'],
    ['name' => 'integration_time', 'type' => 'TEXT'],
    ['name' => 'dj_name', 'type' => 'TEXT'],
    ['name' => 'dj_instagram', 'type' => 'TEXT'],
    ['name' => 'warmup_name', 'type' => 'TEXT'],
    ['name' => 'warmup_instagram', 'type' => 'TEXT'],
    ['name' => 'integration_name', 'type' => 'TEXT'],
    ['name' => 'integration_instagram', 'type' => 'TEXT'],
    ['name' => 'location_url', 'type' => 'TEXT'],
];

function edv_events_ensure_display_columns(PDO $pdo): void
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'sqlite') {
        $cols = $pdo->query('PRAGMA table_info(events)')->fetchAll(PDO::FETCH_ASSOC);
        $names = array_column($cols, 'name');
        foreach (EDV_EVENT_DISPLAY_COLUMNS as $col) {
            if (!in_array($col['name'], $names, true)) {
                $pdo->exec('ALTER TABLE events ADD COLUMN ' . $col['name'] . ' ' . $col['type']);
            }
        }
        return;
    }

    if ($driver === 'pgsql') {
        foreach (EDV_EVENT_DISPLAY_COLUMNS as $col) {
            $sqlType = $col['name'] === 'location_url' ? 'VARCHAR(512)' : (
                str_contains($col['name'], '_time') || $col['name'] === 'doors_close'
                    || str_starts_with($col['name'], 'dance_')
                    ? 'TIME'
                    : 'VARCHAR(255)'
            );
            $pdo->exec(
                'ALTER TABLE events ADD COLUMN IF NOT EXISTS '
                . $col['name'] . ' ' . $sqlType
            );
        }
        return;
    }

    if ($driver === 'mysql') {
        $mysqlTypes = [
            'doors_close' => 'TIME NULL DEFAULT NULL',
            'dance_start' => 'TIME NULL DEFAULT NULL',
            'dance_end' => 'TIME NULL DEFAULT NULL',
            'integration_time' => 'TIME NULL DEFAULT NULL',
            'dj_name' => 'VARCHAR(255) NULL DEFAULT NULL',
            'dj_instagram' => 'VARCHAR(64) NULL DEFAULT NULL',
            'warmup_name' => 'VARCHAR(255) NULL DEFAULT NULL',
            'warmup_instagram' => 'VARCHAR(64) NULL DEFAULT NULL',
            'integration_name' => 'VARCHAR(255) NULL DEFAULT NULL',
            'integration_instagram' => 'VARCHAR(64) NULL DEFAULT NULL',
            'location_url' => 'VARCHAR(512) NULL DEFAULT NULL',
        ];
        foreach ($mysqlTypes as $col => $ddl) {
            try {
                $chk = $pdo->query(
                    "SELECT 1 FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'events' AND COLUMN_NAME = " . $pdo->quote($col)
                );
                if ($chk && $chk->fetchColumn()) {
                    continue;
                }
                $pdo->exec('ALTER TABLE `events` ADD COLUMN `' . $col . '` ' . $ddl);
            } catch (PDOException) {
                // coluna já existe
            }
        }
    }
}

function edv_event_slug_from_date(string $date): string
{
    return 'edv-' . $date;
}

function edv_event_time_hm(?string $time): ?string
{
    if ($time === null || trim($time) === '') {
        return null;
    }
    return substr(trim($time), 0, 5);
}

function edv_event_add_minutes(?string $time, int $minutes): ?string
{
    $hm = edv_event_time_hm($time);
    if ($hm === null) {
        return null;
    }
    [$h, $m] = array_map('intval', explode(':', $hm));
    $total = $h * 60 + $m + $minutes;
    $total = max(0, min(23 * 60 + 59, $total));

    return sprintf('%02d:%02d', intdiv($total, 60), $total % 60);
}

/**
 * @param array<string,mixed> $event
 * @return array<string,mixed>
 */
function edv_event_public_payload(array $event): array
{
    $date = (string) ($event['date'] ?? '');
    $doorsOpen = edv_event_time_hm(isset($event['doors_open']) ? (string) $event['doors_open'] : null);
    $timeStart = edv_event_time_hm(isset($event['time_start']) ? (string) $event['time_start'] : null);
    $timeEnd = edv_event_time_hm(isset($event['time_end']) ? (string) $event['time_end'] : null);
    $danceStart = edv_event_time_hm(isset($event['dance_start']) ? (string) $event['dance_start'] : null)
        ?? edv_event_add_minutes($timeStart, 30);
    $danceEnd = edv_event_time_hm(isset($event['dance_end']) ? (string) $event['dance_end'] : null)
        ?? edv_event_add_minutes($timeEnd, -30);
    $integrationTime = edv_event_time_hm(isset($event['integration_time']) ? (string) $event['integration_time'] : null)
        ?? $danceEnd;
    $doorsClose = edv_event_time_hm(isset($event['doors_close']) ? (string) $event['doors_close'] : null);

    $event['slug'] = $date !== '' ? edv_event_slug_from_date($date) : '';
    $event['doors_open_hm'] = $doorsOpen;
    $event['time_start_hm'] = $timeStart;
    $event['time_end_hm'] = $timeEnd;
    $event['dance_start_hm'] = $danceStart;
    $event['dance_end_hm'] = $danceEnd;
    $event['integration_time_hm'] = $integrationTime;
    $event['doors_close_hm'] = $doorsClose;

    return $event;
}
