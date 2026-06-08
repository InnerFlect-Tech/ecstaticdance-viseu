-- Ecstatic Dance Viseu — activar edição #02 (27 junho 2026)
-- Executar uma vez em produção (phpMyAdmin / cliente SQL) após deploy do site.
-- Preço: standard 30€ · early bird 25€ (até 13 jun, inclusive) · regresso 20€.
-- Em alternativa, fazer tudo pelo /admin → Eventos (recomendado, funciona em SQLite e MySQL).

-- Desactivar edições anteriores (inclui #01 de 23 maio)
UPDATE `events` SET `is_active` = 0 WHERE `date` < '2026-06-27';

-- Criar #02 se ainda não existir
INSERT INTO `events`
  (`title`, `description`, `date`, `time_start`, `time_end`, `doors_open`,
   `location`, `type`, `capacity`,
   `min_price`, `early_bird_min_eur`, `early_bird_until`, `returning_min_eur`,
   `is_active`)
SELECT
  'Ecstatic Dance Viseu #02',
  'DJ Bernardo B-file (B-File) — jornada musical de 3h. Warm-up e integração a anunciar.',
  '2026-06-27', '16:00:00', '19:00:00', '15:30:00',
  'Nua e Crua, Viseu', 'paid', 60,
  30.00, 25.00, '2026-06-13', 20.00,
  1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `events` WHERE `date` = '2026-06-27');

-- Se #02 já existia mas estava inactivo/desalinhado, reactivar e fixar preços
UPDATE `events`
SET `is_active` = 1,
    `title` = 'Ecstatic Dance Viseu #02',
    `description` = 'DJ Bernardo B-file (B-File) — jornada musical de 3h. Warm-up e integração a anunciar.',
    `location` = 'Nua e Crua, Viseu',
    `doors_open` = '15:30:00',
    `time_start` = '16:00:00',
    `time_end` = '19:00:00',
    `min_price` = 30.00,
    `early_bird_min_eur` = 25.00,
    `early_bird_until` = '2026-06-13',
    `returning_min_eur` = 20.00
WHERE `date` = '2026-06-27';
