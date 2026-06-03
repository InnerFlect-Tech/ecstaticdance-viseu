-- Edição #01 (23 maio 2026) — contas históricas alinhadas com fecho manual
-- 300€ receita (6×20€ + 6×30€) · 75€ Nua e Crua · custos 47€ · 20€/facilitador · 69€ cada

-- Perfil e tabelas (se ainda não existirem — ver também migration_2026_06_event_settlement.sql)
ALTER TABLE `events`
  ADD COLUMN `settlement_profile` VARCHAR(20) NOT NULL DEFAULT 'standard';

ALTER TABLE `event_settlement_shares`
  ADD COLUMN `amount_fixed_eur` DECIMAL(10,2) NULL DEFAULT NULL;

CREATE TABLE IF NOT EXISTS `event_revenue_tiers_manual` (
  `event_id`   INT UNSIGNED NOT NULL,
  `price_eur`  DECIMAL(8,2) NOT NULL,
  `quantity`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `notes`      VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`event_id`, `price_eur`),
  CONSTRAINT `fk_manual_tiers_event`
    FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @eid := (SELECT `id` FROM `events` WHERE `date` = '2026-05-23' OR `title` LIKE '%#01%' ORDER BY `date` ASC LIMIT 1);

UPDATE `events` SET `settlement_profile` = 'venue_first' WHERE `id` = @eid;

INSERT INTO `event_revenue_tiers_manual` (`event_id`, `price_eur`, `quantity`, `notes`)
VALUES
  (@eid, 20.00, 6, 'Edição #01'),
  (@eid, 30.00, 6, 'Edição #01')
ON DUPLICATE KEY UPDATE `quantity` = VALUES(`quantity`), `notes` = VALUES(`notes`);

INSERT INTO `event_costs`
  (`event_id`, `label`, `category`, `base_cost_slug`, `amount_eur`, `incurred_at`, `reimbursed`, `cost_stage`, `cost_bucket`, `created_at`)
SELECT @eid, 'Flyers', 'custos_base', 'flyers', 20.00, NOW(), 0, 'actual', 'base', NOW()
FROM DUAL
WHERE @eid IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `event_costs` WHERE `event_id` = @eid AND `base_cost_slug` = 'flyers');

INSERT INTO `event_costs`
  (`event_id`, `label`, `category`, `base_cost_slug`, `amount_eur`, `incurred_at`, `reimbursed`, `cost_stage`, `cost_bucket`, `created_at`)
SELECT @eid, 'Comidas', 'custos_base', 'comidas', 27.00, NOW(), 0, 'actual', 'base', NOW()
FROM DUAL
WHERE @eid IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `event_costs` WHERE `event_id` = @eid AND `base_cost_slug` = 'comidas');

UPDATE `event_costs` SET `amount_eur` = 20.00 WHERE `event_id` = @eid AND `base_cost_slug` = 'flyers';
UPDATE `event_costs` SET `amount_eur` = 27.00 WHERE `event_id` = @eid AND `base_cost_slug` = 'comidas';

DELETE FROM `event_settlement_shares` WHERE `event_id` = @eid;

INSERT INTO `event_settlement_shares` (`event_id`, `role_key`, `label`, `percent`, `amount_fixed_eur`, `pool`, `sort_order`, `is_active`)
VALUES
  (@eid, 'espaco', 'Nua e Crua (espaço)', 25.00, NULL, 'gross', 10, 1),
  (@eid, 'facilitador_1', 'Facilitador 1', 0.00, 20.00, 'post_gross_base', 20, 1),
  (@eid, 'facilitador_2', 'Facilitador 2', 0.00, 20.00, 'post_gross_base', 30, 1),
  (@eid, 'indias', 'Indias', 50.00, NULL, 'final', 50, 1),
  (@eid, 'carolina', 'Carolina', 50.00, NULL, 'final', 60, 1);
