-- SQLite — colunas para confirmação e emails (ignora erro se já existir)

ALTER TABLE link_registrations ADD COLUMN ticket_id TEXT;
ALTER TABLE link_registrations ADD COLUMN confirmed_at TEXT;
ALTER TABLE link_registrations ADD COLUMN receipt_email_sent_at TEXT;
