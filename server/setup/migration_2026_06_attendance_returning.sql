-- Presenças por evento + preço de regresso
-- Executar uma vez em produção (MySQL/MariaDB).

CREATE TABLE IF NOT EXISTS `event_attendance` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_id`      INT UNSIGNED    NOT NULL,
  `ticket_id`     CHAR(36)        NOT NULL,
  `email`         VARCHAR(255)    NOT NULL,
  `name`          VARCHAR(255)    NOT NULL,
  `phone`         VARCHAR(40)     NOT NULL,
  `amount_paid`   DECIMAL(8,2)    NOT NULL DEFAULT 0.00,
  `checked_in_at` DATETIME        NOT NULL,
  `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_attendance_event_email` (`event_id`, `email`),
  UNIQUE KEY `uq_attendance_ticket` (`ticket_id`),
  KEY `idx_attendance_email` (`email`),
  CONSTRAINT `fk_attendance_event`
    FOREIGN KEY (`event_id`) REFERENCES `events` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_attendance_ticket`
    FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `events`
  ADD COLUMN `returning_min_eur` DECIMAL(8,2) NULL DEFAULT NULL AFTER `min_price`;

ALTER TABLE `tickets`
  ADD COLUMN `price_tier` VARCHAR(32) NOT NULL DEFAULT 'standard' AFTER `amount_paid`;

-- Importar check-ins já feitos (edição #01, etc.)
INSERT INTO `event_attendance`
  (`event_id`, `ticket_id`, `email`, `name`, `phone`, `amount_paid`, `checked_in_at`, `created_at`)
SELECT
  t.event_id,
  t.id,
  LOWER(TRIM(t.email)),
  t.name,
  t.phone,
  t.amount_paid,
  COALESCE(t.checked_in_at, t.created_at),
  COALESCE(t.checked_in_at, t.created_at)
FROM `tickets` t
WHERE t.checked_in = 1
  AND t.payment_status IN ('paid', 'free')
ON DUPLICATE KEY UPDATE
  `ticket_id` = VALUES(`ticket_id`),
  `name` = VALUES(`name`),
  `checked_in_at` = VALUES(`checked_in_at`);
