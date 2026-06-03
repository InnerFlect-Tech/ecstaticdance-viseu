-- Early bird configurável por evento (admin → Eventos)
ALTER TABLE `events`
  ADD COLUMN `early_bird_min_eur` DECIMAL(8,2) NULL DEFAULT NULL AFTER `returning_min_eur`,
  ADD COLUMN `early_bird_until` DATE NULL DEFAULT NULL AFTER `early_bird_min_eur`;
