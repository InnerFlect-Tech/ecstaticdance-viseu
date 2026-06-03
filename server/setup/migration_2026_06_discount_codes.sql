-- Códigos de desconto + registo de utilizações
CREATE TABLE IF NOT EXISTS `discount_campaigns` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_id` INT UNSIGNED NOT NULL,
  `label` VARCHAR(255) DEFAULT NULL,
  `min_eur` DECIMAL(8,2) NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'draft',
  `recipient_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `codes_generated` INT UNSIGNED NOT NULL DEFAULT 0,
  `emails_sent` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_campaign_event` (`event_id`),
  CONSTRAINT `fk_campaign_event`
    FOREIGN KEY (`event_id`) REFERENCES `events` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `discount_codes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `campaign_id` BIGINT UNSIGNED DEFAULT NULL,
  `event_id` INT UNSIGNED NOT NULL,
  `code` VARCHAR(32) NOT NULL,
  `min_eur` DECIMAL(8,2) NOT NULL,
  `email` VARCHAR(255) DEFAULT NULL,
  `name` VARCHAR(255) DEFAULT NULL,
  `max_uses` INT UNSIGNED NOT NULL DEFAULT 1,
  `use_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `valid_until` DATE DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `sent_at` DATETIME DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_discount_code` (`code`),
  KEY `idx_discount_event_email` (`event_id`, `email`),
  CONSTRAINT `fk_discount_event`
    FOREIGN KEY (`event_id`) REFERENCES `events` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_discount_campaign`
    FOREIGN KEY (`campaign_id`) REFERENCES `discount_campaigns` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `discount_code_uses` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `discount_code_id` BIGINT UNSIGNED NOT NULL,
  `ticket_id` CHAR(36) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `amount_paid` DECIMAL(8,2) NOT NULL,
  `used_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_code_use_code` (`discount_code_id`),
  KEY `idx_code_use_ticket` (`ticket_id`),
  CONSTRAINT `fk_code_use_code`
    FOREIGN KEY (`discount_code_id`) REFERENCES `discount_codes` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_code_use_ticket`
    FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `tickets`
  ADD COLUMN `promo_code` VARCHAR(32) NULL DEFAULT NULL AFTER `price_tier`;

ALTER TABLE `link_registrations`
  ADD COLUMN `promo_code` VARCHAR(32) NULL DEFAULT NULL AFTER `heard_other`;
