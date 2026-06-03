-- Reservas /links: bilhete confirmado + emails (recepção + confirmação)
-- Executar uma vez em produção.

ALTER TABLE `link_registrations`
  ADD COLUMN `ticket_id` CHAR(36) NULL DEFAULT NULL AFTER `step2_at`,
  ADD COLUMN `confirmed_at` DATETIME NULL DEFAULT NULL AFTER `ticket_id`,
  ADD COLUMN `receipt_email_sent_at` DATETIME NULL DEFAULT NULL AFTER `confirmed_at`;

ALTER TABLE `link_registrations`
  ADD KEY `idx_link_ticket_id` (`ticket_id`);
