-- Campos de apresentação /links (facilitadores, horários extra)
ALTER TABLE `events`
  ADD COLUMN `doors_close` TIME NULL DEFAULT NULL AFTER `doors_open`,
  ADD COLUMN `dance_start` TIME NULL DEFAULT NULL AFTER `time_start`,
  ADD COLUMN `dance_end` TIME NULL DEFAULT NULL AFTER `dance_start`,
  ADD COLUMN `integration_time` TIME NULL DEFAULT NULL AFTER `dance_end`,
  ADD COLUMN `dj_name` VARCHAR(255) NULL DEFAULT NULL,
  ADD COLUMN `dj_instagram` VARCHAR(64) NULL DEFAULT NULL,
  ADD COLUMN `warmup_name` VARCHAR(255) NULL DEFAULT NULL,
  ADD COLUMN `warmup_instagram` VARCHAR(64) NULL DEFAULT NULL,
  ADD COLUMN `integration_name` VARCHAR(255) NULL DEFAULT NULL,
  ADD COLUMN `integration_instagram` VARCHAR(64) NULL DEFAULT NULL,
  ADD COLUMN `location_url` VARCHAR(512) NULL DEFAULT NULL;

-- Dados exemplo edição #02 (ajustar se necessário)
UPDATE `events`
SET
  doors_close = '20:30:00',
  dance_start = '16:30:00',
  dance_end = '18:30:00',
  integration_time = '18:30:00',
  dj_name = 'Bernardo B-file',
  dj_instagram = 'b_filemusic',
  location_url = 'https://www.instagram.com/_nua_e_crua_/'
WHERE `date` = '2026-06-27';
