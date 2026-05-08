-- Link booking (links.html) — PostgreSQL/Supabase variant
-- New installs using schema-pgsql.sql already include this table.

CREATE TABLE IF NOT EXISTS link_registrations (
  id                 CHAR(36) PRIMARY KEY,
  payment_ref        VARCHAR(40) NOT NULL UNIQUE,
  event_slug         VARCHAR(64) NOT NULL DEFAULT 'edv-2026-05-23',
  name               VARCHAR(255) NOT NULL,
  email              VARCHAR(255) NOT NULL,
  phone              VARCHAR(40) NOT NULL,
  ticket_euros       NUMERIC(8,2) NOT NULL,
  dinner_note        VARCHAR(64) NOT NULL DEFAULT '',
  total_euros        NUMERIC(8,2) NOT NULL,
  payment_method     VARCHAR(20) NOT NULL CHECK (payment_method IN ('mbway', 'transfer', 'revolut')),
  heard_from         VARCHAR(32) NOT NULL,
  heard_other        VARCHAR(255) DEFAULT NULL,
  step1_at           TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
  step2_type         VARCHAR(20) DEFAULT NULL CHECK (step2_type IS NULL OR step2_type IN ('upload', 'email_later')),
  proof_relpath      VARCHAR(512) DEFAULT NULL,
  proof_mime         VARCHAR(120) DEFAULT NULL,
  step2_at           TIMESTAMP WITHOUT TIME ZONE DEFAULT NULL,
  created_at         TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_link_email ON link_registrations (email);
CREATE INDEX IF NOT EXISTS idx_link_step1 ON link_registrations (step1_at);
