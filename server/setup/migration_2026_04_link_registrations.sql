-- Link booking (links.html) — run once on existing DBs
-- New installs: table is already in schema.sql

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `link_registrations` (
  `id`                 CHAR(36)     NOT NULL,
  `payment_ref`        VARCHAR(40)  NOT NULL,
  `event_slug`         VARCHAR(64)  NOT NULL DEFAULT 'edv-2026-05-23',
  `name`               VARCHAR(255) NOT NULL,
  `email`              VARCHAR(255) NOT NULL,
  `phone`              VARCHAR(40)  NOT NULL,
  `ticket_euros`       DECIMAL(8,2) NOT NULL,
  `dinner_note`        VARCHAR(64)  NOT NULL DEFAULT '',
  `total_euros`        DECIMAL(8,2) NOT NULL,
  `payment_method`     ENUM('mbway','transfer','revolut') NOT NULL,
  `heard_from`         VARCHAR(32)  NOT NULL,
  `heard_other`        VARCHAR(255) DEFAULT NULL,
  `step1_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `step2_type`         ENUM('upload','email_later') DEFAULT NULL,
  `proof_relpath`      VARCHAR(512) DEFAULT NULL,
  `proof_mime`         VARCHAR(120) DEFAULT NULL,
  `step2_at`           DATETIME     DEFAULT NULL,
  `created_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payment_ref` (`payment_ref`),
  KEY `idx_email` (`email`),
  KEY `idx_step1` (`step1_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- If you created an older version of the table without `revolut`, run:
-- ALTER TABLE `link_registrations` MODIFY `payment_method` ENUM('mbway','transfer','revolut') NOT NULL;
