<?php
/**
 * Piso do bilhete alinhado com js/pricing.js — early bird até ao fim do dia 9 de maio de 2026 (Europe/Lisbon).
 */
declare(strict_types=1);

function edv_ticket_min_eur(): float {
    $tz = new DateTimeZone('Europe/Lisbon');
    $now = new DateTime('now', $tz);
    $earlyBirdEnds = new DateTime('2026-05-10 00:00:00', $tz);

    return $now < $earlyBirdEnds ? 20.0 : 30.0;
}
