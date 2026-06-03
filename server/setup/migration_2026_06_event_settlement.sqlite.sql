-- SQLite: settlement + cost_bucket (run once)

ALTER TABLE event_costs ADD COLUMN cost_bucket TEXT NOT NULL DEFAULT 'base';
ALTER TABLE event_costs ADD COLUMN base_cost_slug TEXT;
ALTER TABLE event_costs ADD COLUMN cost_stage TEXT NOT NULL DEFAULT 'actual';

CREATE TABLE IF NOT EXISTS event_settlement_shares (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  event_id INTEGER NOT NULL,
  role_key TEXT NOT NULL,
  label TEXT NOT NULL,
  percent REAL NOT NULL DEFAULT 0,
  pool TEXT NOT NULL DEFAULT 'post_venue',
  sort_order INTEGER NOT NULL DEFAULT 0,
  is_active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (event_id, role_key),
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE ON UPDATE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_settlement_shares_event ON event_settlement_shares (event_id);
