<?php
/* Persistência apenas para desenvolvimento: grava registos de links.html num JSON com lock de ficheiro. */

declare(strict_types=1);

function link_json_store_path(): string {
    return defined('LINK_JSON_PATH') && is_string(LINK_JSON_PATH) && LINK_JSON_PATH !== ''
        ? LINK_JSON_PATH
        : (__DIR__ . '/../data/link-registrations-dev.json');
}

/**
 * @template T
 * @param callable(array): T $fn
 * @return T
 */
function link_json_store_mutate(callable $fn): mixed {
    $path = link_json_store_path();
    $dir  = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Não foi possível criar o directório: ' . $dir);
    }
    $fh = fopen($path, 'c+');
    if ($fh === false) {
        throw new RuntimeException('Não foi possível abrir: ' . $path);
    }
    try {
        if (!flock($fh, LOCK_EX)) {
            throw new RuntimeException('Não foi possível obter exclusão sobre o ficheiro JSON.');
        }
        rewind($fh);
        $raw = stream_get_contents($fh);
        $raw = ($raw !== false && $raw !== '') ? $raw : '{}';
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $data = [];
        }
        if (!isset($data['registrations']) || !is_array($data['registrations'])) {
            $data['registrations'] = [];
        }
        $result = $fn($data);
        $out = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($out === false) {
            throw new RuntimeException('Erro ao serializar JSON.');
        }
        rewind($fh);
        ftruncate($fh, 0);
        fwrite($fh, $out);
        fflush($fh);
        return $result;
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/**
 * @param array<string,mixed> $row
 *
 * @throws RuntimeException duplicate id/ref dentro do store
 */
function link_json_insert_registration(array $row): void {
    link_json_store_mutate(static function (array &$data) use ($row): void {
        foreach ($data['registrations'] as $ex) {
            if (!is_array($ex)) {
                continue;
            }
            if (($ex['payment_ref'] ?? '') === ($row['payment_ref'] ?? '') || ($ex['id'] ?? '') === ($row['id'] ?? '')) {
                throw new RuntimeException('duplicate_key');
            }
        }
        $data['registrations'][] = $row;
    });
}

/** @return ?array<string,mixed> */
function link_json_find_registration(string $id): ?array {
    return link_json_store_mutate(static function (array &$data) use ($id): ?array {
        foreach ($data['registrations'] as $r) {
            if (is_array($r) && ($r['id'] ?? '') === $id) {
                return $r;
            }
        }
        return null;
    });
}

/**
 * @param array<string,mixed> $patch
 */
function link_json_patch_registration(string $id, array $patch): bool {
    return link_json_store_mutate(static function (array &$data) use ($id, $patch): bool {
        foreach ($data['registrations'] as $i => $r) {
            if (!is_array($r) || ($r['id'] ?? '') !== $id) {
                continue;
            }
            $data['registrations'][$i] = array_merge($r, $patch);
            return true;
        }
        return false;
    });
}

/**
 * @return ?array<string,mixed>
 */
function link_json_find_open_registration(string $email, string $eventSlug): ?array {
    return link_json_store_mutate(static function (array &$data) use ($email, $eventSlug): ?array {
        $best = null;
        foreach ($data['registrations'] as $r) {
            if (!is_array($r)) {
                continue;
            }
            if ((string)($r['email'] ?? '') !== $email) {
                continue;
            }
            if ((string)($r['event_slug'] ?? '') !== $eventSlug) {
                continue;
            }
            if (!empty($r['step2_at'])) {
                continue;
            }
            if ($best === null || strcmp((string)($r['step1_at'] ?? ''), (string)($best['step1_at'] ?? '')) > 0) {
                $best = $r;
            }
        }
        return $best;
    });
}

/**
 * @return array{deleted:bool, proof_relpath:?string}
 */
function link_json_delete_registration(string $id): array {
    return link_json_store_mutate(static function (array &$data) use ($id): array {
        foreach ($data['registrations'] as $i => $r) {
            if (!is_array($r) || (string)($r['id'] ?? '') !== $id) {
                continue;
            }
            $proof = isset($r['proof_relpath']) ? (string)$r['proof_relpath'] : null;
            array_splice($data['registrations'], $i, 1);
            return ['deleted' => true, 'proof_relpath' => $proof !== '' ? $proof : null];
        }
        return ['deleted' => false, 'proof_relpath' => null];
    });
}

/**
 * Lista todas as reservas (modo JSON), mais recentes primeiro.
 *
 * @return list<array<string,mixed>>
 */
function link_json_all_registrations_ordered(): array {
    $path = link_json_store_path();
    if (!is_readable($path)) {
        return [];
    }
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        return [];
    }
    try {
        if (!flock($fh, LOCK_SH)) {
            return [];
        }
        $raw = stream_get_contents($fh);
        flock($fh, LOCK_UN);
    } finally {
        fclose($fh);
    }
    $raw = ($raw !== false && $raw !== '') ? $raw : '{}';
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['registrations']) || !is_array($data['registrations'])) {
        return [];
    }
    $regs = array_values(array_filter($data['registrations'], 'is_array'));
    usort(
        $regs,
        static function (array $a, array $b): int {
            return strcmp((string)($b['step1_at'] ?? ''), (string)($a['step1_at'] ?? ''));
        }
    );
    return $regs;
}
