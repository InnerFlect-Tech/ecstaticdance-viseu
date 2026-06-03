-- Importar presenças edição #01 a partir da folha à porta.
-- Preferir Admin → Presenças → «Importar folha #01» (PHP faz matching por nome/email/tel).
-- Este ficheiro só garante que o evento existe.

INSERT INTO `events`
  (`title`, `description`, `date`, `time_start`, `time_end`, `doors_open`, `location`, `type`, `capacity`, `min_price`, `is_active`, `settlement_profile`)
SELECT
  'Ecstatic Dance Viseu #01',
  'Primeira edição. DJ Nu Moksa, warm-up Carolina Gomes. Nua e Crua.',
  '2026-05-23', '16:00:00', '19:00:00', '15:30:00',
  'Nua e Crua, Viseu', 'paid', 60, 25.00, 0, 'venue_first'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `events` WHERE `date` = '2026-05-23' OR `title` LIKE '%#01%');
