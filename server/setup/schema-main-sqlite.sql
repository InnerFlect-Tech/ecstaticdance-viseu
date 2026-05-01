-- ============================================================
-- Ecstatic Dance Viseu — SQLite schema (eventos + bilhetes)
-- Usado quando USE_SQLITE_MAIN_DB = true (dev sem MySQL / sem pdo_mysql)
-- ============================================================

PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  description TEXT DEFAULT NULL,
  date TEXT NOT NULL,
  time_start TEXT DEFAULT '16:00:00',
  time_end TEXT DEFAULT '19:00:00',
  doors_open TEXT DEFAULT '15:30:00',
  location TEXT DEFAULT 'Viseu',
  type TEXT NOT NULL DEFAULT 'paid' CHECK (type IN ('free','paid')),
  capacity INTEGER NOT NULL DEFAULT 60,
  min_price REAL NOT NULL DEFAULT 25.00,
  is_active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS tickets (
  id TEXT NOT NULL PRIMARY KEY,
  event_id INTEGER NOT NULL,
  name TEXT NOT NULL,
  email TEXT NOT NULL,
  phone TEXT NOT NULL,
  amount_paid REAL NOT NULL DEFAULT 0,
  payment_status TEXT NOT NULL DEFAULT 'pending' CHECK (payment_status IN ('pending','paid','free')),
  stripe_session_id TEXT DEFAULT NULL,
  checked_in INTEGER NOT NULL DEFAULT 0,
  checked_in_at TEXT DEFAULT NULL,
  paid_at TEXT DEFAULT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE RESTRICT ON UPDATE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_event_status ON tickets (event_id, payment_status);
CREATE INDEX IF NOT EXISTS idx_email ON tickets (email);
CREATE INDEX IF NOT EXISTS idx_stripe_session ON tickets (stripe_session_id);
CREATE INDEX IF NOT EXISTS idx_checked_in ON tickets (checked_in);
