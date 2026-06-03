<?php
/**
 * GET /api/get-ticket-pricing.php?email=&event_id=&code=
 * Piso do bilhete e escalão (standard | early_bird | returning | discount_code).
 */
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/attendance.php';
require_once __DIR__ . '/link-common.php';
require_once __DIR__ . '/discount-codes.php';

cors();
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_err('Method not allowed', 405);
}

$email   = edv_normalize_email((string) ($_GET['email'] ?? ''));
$phone   = trim((string) ($_GET['phone'] ?? ''));
$eventId = (int) ($_GET['event_id'] ?? 0);
$slug    = link_sanitise((string) ($_GET['event_slug'] ?? ''), 64);
$promo   = edv_normalize_promo_code((string) ($_GET['code'] ?? ''));

if ($eventId <= 0 && $slug !== '') {
    $resolved = link_resolve_event_id_from_slug($slug);
    if ($resolved !== null) {
        $eventId = $resolved;
    }
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_err('Email inválido.', 400);
}

$resolvedEventId = $eventId > 0 ? $eventId : null;
$hasContact = $email !== '' || edv_normalize_phone_digits($phone) !== '';
$promoArg = $promo !== '' ? $promo : null;

if ($hasContact) {
    $tier = edv_ticket_price_tier($email, $resolvedEventId, null, $phone, $promoArg);
} else {
    $tier = edv_public_price_tier($resolvedEventId, null, $promoArg);
}

$min = edv_ticket_min_eur(
    $email !== '' ? $email : null,
    $resolvedEventId,
    null,
    $phone,
    $promoArg
);
$earlyBirdActive = edv_is_early_bird_period(null, $resolvedEventId);
$codeValid = $promoArg !== null && edv_lookup_discount_code($promoArg, $resolvedEventId, $email !== '' ? $email : null) !== null;

json_ok([
    'min_eur'              => $min,
    'tier'                 => $tier,
    'is_returning'         => $hasContact && $tier === 'returning',
    'is_early_bird'        => $tier === 'early_bird',
    'is_discount_code'     => $tier === 'discount_code',
    'is_early_bird_active' => $earlyBirdActive,
    'code_valid'           => $codeValid,
    'promo_code'           => $codeValid ? $promo : null,
    'returning_min_eur'    => edv_returning_min_for_event($resolvedEventId),
    'early_bird_min_eur'   => edv_early_bird_min_for_event($resolvedEventId),
    'early_bird_until'     => edv_early_bird_until_for_event($resolvedEventId),
    'standard_min_eur'     => edv_standard_min_for_event($resolvedEventId),
]);
