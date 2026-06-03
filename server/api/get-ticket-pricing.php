<?php
/**
 * GET /api/get-ticket-pricing.php?email=&event_id=
 * Piso do bilhete e escalão (standard | early_bird | returning).
 */
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/attendance.php';
require_once __DIR__ . '/link-common.php';

cors();
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_err('Method not allowed', 405);
}

$email   = edv_normalize_email((string) ($_GET['email'] ?? ''));
$phone   = trim((string) ($_GET['phone'] ?? ''));
$eventId = (int) ($_GET['event_id'] ?? 0);
$slug    = link_sanitise((string) ($_GET['event_slug'] ?? ''), 64);
if ($eventId <= 0 && $slug !== '') {
    $resolved = link_resolve_event_id_from_slug($slug);
    if ($resolved !== null) {
        $eventId = $resolved;
    }
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_err('Email inválido.', 400);
}

$hasContact = $email !== '' || edv_normalize_phone_digits($phone) !== '';
$tier = $hasContact ? edv_ticket_price_tier($email, $eventId > 0 ? $eventId : null, null, $phone) : 'standard';
$min  = edv_ticket_min_eur($email !== '' ? $email : null, $eventId > 0 ? $eventId : null, null, $phone);

json_ok([
    'min_eur'           => $min,
    'tier'              => $tier,
    'is_returning'      => $hasContact && $tier === 'returning',
    'is_early_bird'     => $tier === 'early_bird',
    'returning_min_eur' => edv_returning_min_for_event($eventId > 0 ? $eventId : null),
    'standard_min_eur'  => edv_is_early_bird_period() ? 20.0 : 30.0,
]);
