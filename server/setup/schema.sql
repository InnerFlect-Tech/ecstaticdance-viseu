-- ============================================================
-- Ecstatic Dance Viseu — MySQL Schema
-- Run via install.php or import in cPanel phpMyAdmin
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ── Events ──
CREATE TABLE IF NOT EXISTS `events` (
  `id`          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `title`       VARCHAR(255)     NOT NULL,
  `description` TEXT             DEFAULT NULL,
  `date`        DATE             NOT NULL,
  `time_start`  TIME             DEFAULT '16:00:00',
  `time_end`    TIME             DEFAULT '19:00:00',
  `doors_open`  TIME             DEFAULT '15:30:00',
  `location`    VARCHAR(255)     DEFAULT 'Viseu',
  `type`        ENUM('free','paid') NOT NULL DEFAULT 'paid',
  `capacity`    SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  `min_price`   DECIMAL(8,2)     NOT NULL DEFAULT 25.00,
  `returning_min_eur` DECIMAL(8,2) DEFAULT NULL,
  `is_active`   TINYINT(1)       NOT NULL DEFAULT 1,
  `created_at`  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_date_active` (`date`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── Tickets ──
CREATE TABLE IF NOT EXISTS `tickets` (
  `id`                CHAR(36)         NOT NULL,
  `event_id`          INT UNSIGNED     NOT NULL,
  `name`              VARCHAR(255)     NOT NULL,
  `email`             VARCHAR(255)     NOT NULL,
  `phone`             VARCHAR(30)      NOT NULL,
  `amount_paid`       DECIMAL(8,2)     NOT NULL DEFAULT 0.00,
  `price_tier`        VARCHAR(32)      NOT NULL DEFAULT 'standard',
  `payment_status`    ENUM('pending','paid','free') NOT NULL DEFAULT 'pending',
  `stripe_session_id` VARCHAR(255)     DEFAULT NULL,
  `checked_in`        TINYINT(1)       NOT NULL DEFAULT 0,
  `checked_in_at`     DATETIME         DEFAULT NULL,
  `paid_at`           DATETIME         DEFAULT NULL,
  `created_at`        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_event_status`   (`event_id`, `payment_status`),
  KEY `idx_email`          (`email`),
  KEY `idx_stripe_session` (`stripe_session_id`),
  KEY `idx_checked_in`     (`checked_in`),
  CONSTRAINT `fk_ticket_event`
    FOREIGN KEY (`event_id`) REFERENCES `events` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── Reservas via links.html (pedido + comprovativo) ──
CREATE TABLE IF NOT EXISTS `link_registrations` (
  `id`                 CHAR(36)     NOT NULL,
  `payment_ref`        VARCHAR(40)  NOT NULL,
  `event_slug`         VARCHAR(64)  NOT NULL DEFAULT 'edv-2026-06-27',
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
  `ticket_id`          CHAR(36)     DEFAULT NULL,
  `confirmed_at`       DATETIME     DEFAULT NULL,
  `receipt_email_sent_at` DATETIME  DEFAULT NULL,
  `created_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payment_ref` (`payment_ref`),
  KEY `idx_email` (`email`),
  KEY `idx_step1` (`step1_at`),
  KEY `idx_link_ticket_id` (`ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── Presenças (quem entrou na porta — lista por evento + desconto de regresso) ──
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

-- ── Event costs / reimbursements (admin/costs.php) ──
CREATE TABLE IF NOT EXISTS `event_costs` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_id`        INT UNSIGNED    NOT NULL,
  `label`           VARCHAR(255)    NOT NULL,
  `category`        VARCHAR(80)     NOT NULL DEFAULT '',
  `cost_bucket`     VARCHAR(16)     NOT NULL DEFAULT 'base',
  `base_cost_slug`  VARCHAR(40)     DEFAULT NULL,
  `amount_eur`      DECIMAL(10,2)   NOT NULL,
  `paid_by`         VARCHAR(120)    DEFAULT NULL,
  `notes`           TEXT            DEFAULT NULL,
  `incurred_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reimbursed`      TINYINT(1)      NOT NULL DEFAULT 0,
  `cost_stage`      VARCHAR(16)     NOT NULL DEFAULT 'actual',
  `reimbursed_at`   DATETIME        DEFAULT NULL,
  `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_event` (`event_id`),
  KEY `idx_incurred_at` (`incurred_at`),
  CONSTRAINT `fk_event_costs_event`
    FOREIGN KEY (`event_id`) REFERENCES `events` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  CONSTRAINT `fk_settlement_event`
    FOREIGN KEY (`event_id`) REFERENCES `events` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── Event #02 — uncomment to seed after install (desactiva #01 se existir) ──
-- UPDATE `events` SET `is_active` = 0 WHERE `date` = '2026-05-23';
-- INSERT INTO `events`
--   (title, description, date, time_start, time_end, doors_open, location, type, capacity, min_price, is_active)
-- VALUES
--   ('Ecstatic Dance Viseu #02',
--    'DJ Bernardo B-file — jornada musical de 3h. Warm-up e integração a anunciar.',
--    '2026-06-27', '16:00:00', '19:00:00', '15:30:00',
--    'Nua e Crua, Viseu', 'paid', 60, 25.00, 1);
