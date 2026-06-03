-- Repartição financeira (custos base + percentagens) — alinhado com folha de cálculo EDV
-- MySQL / MariaDB

ALTER TABLE `event_costs`
  ADD COLUMN IF NOT EXISTS `cost_bucket` VARCHAR(16) NOT NULL DEFAULT 'base' AFTER `category`,
  ADD COLUMN IF NOT EXISTS `base_cost_slug` VARCHAR(40) NULL DEFAULT NULL AFTER `cost_bucket`,
  ADD COLUMN IF NOT EXISTS `cost_stage` VARCHAR(16) NOT NULL DEFAULT 'actual' AFTER `reimbursed`;

-- MariaDB < 10.5: run columns individually if IF NOT EXISTS fails
-- ALTER TABLE `event_costs` ADD COLUMN `cost_bucket` ...
-- UPDATE legacy rows: treat uncategorised as base when category suggests it
UPDATE `event_costs` SET `cost_bucket` = 'base' WHERE `cost_bucket` = '' OR `cost_bucket` IS NULL;

CREATE TABLE IF NOT EXISTS `event_settlement_shares` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_id`   INT UNSIGNED    NOT NULL,
  `role_key`   VARCHAR(40)     NOT NULL,
  `label`      VARCHAR(120)    NOT NULL,
  `percent`    DECIMAL(6,2)    NOT NULL DEFAULT 0.00,
  `pool`       VARCHAR(20)     NOT NULL DEFAULT 'post_venue',
  `sort_order` SMALLINT        NOT NULL DEFAULT 0,
  `is_active`  TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_settlement_event_role` (`event_id`, `role_key`),
  KEY `idx_settlement_event` (`event_id`),
  CONSTRAINT `fk_settlement_event`
    FOREIGN KEY (`event_id`) REFERENCES `events` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
