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


-- ── First event — uncomment to seed after install ──
-- INSERT INTO `events`
--   (title, description, date, time_start, time_end, doors_open, location, type, capacity, min_price, is_active)
-- VALUES
--   ('Ecstatic Dance Viseu #01',
--    'A primeira edição em Viseu. DJ convidado com jornada musical de 3h. Dança livre, sem passos, sem performance.',
--    '2026-05-23', '16:00:00', '19:00:00', '15:30:00',
--    'Nua e Crua, Viseu', 'paid', 60, 25.00, 1);
