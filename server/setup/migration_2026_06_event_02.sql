-- Ecstatic Dance Viseu — activar edição #02 (27 junho 2026)
-- Executar uma vez em produção (phpMyAdmin / cliente SQL) após deploy do site.

-- Desactivar edições anteriores (inclui #01 de 23 maio)
UPDATE `events` SET `is_active` = 0 WHERE `date` < '2026-06-27';

-- Criar #02 se ainda não existir
INSERT INTO `events`
  (`title`, `description`, `date`, `time_start`, `time_end`, `doors_open`, `location`, `type`, `capacity`, `min_price`, `is_active`)
SELECT
  'Ecstatic Dance Viseu #02',
  'DJ Bernardo B-file (B-File) — jornada musical de 3h. Warm-up e integração a anunciar.',
  '2026-06-27', '16:00:00', '19:00:00', '15:30:00',
  'Nua e Crua, Viseu', 'paid', 60, 25.00, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `events` WHERE `date` = '2026-06-27');

-- Se #02 já existia mas estava inactivo, reactivar
UPDATE `events`
SET `is_active` = 1,
    `title` = 'Ecstatic Dance Viseu #02',
    `description` = 'DJ Bernardo B-file (B-File) — jornada musical de 3h. Warm-up e integração a anunciar.',
    `location` = 'Nua e Crua, Viseu',
    `doors_open` = '15:30:00',
    `time_start` = '16:00:00',
    `time_end` = '19:00:00'
WHERE `date` = '2026-06-27';
