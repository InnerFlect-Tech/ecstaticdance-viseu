# Database Guide — Ecstatic Dance Viseu

MySQL database structure and management on cPanel.

---

## Schema overview

### `events` table

| Column | Type | Notes |
|---|---|---|
| `id` | INT UNSIGNED AUTO_INCREMENT | Primary key |
| `title` | VARCHAR(255) | Event name |
| `description` | TEXT | Short description shown on booking page |
| `date` | DATE | Event date |
| `time_start` | TIME | Dance start time (default 19:00) |
| `time_end` | TIME | Dance end time (default 22:30) |
| `doors_open` | TIME | Doors open (default 18:30) |
| `location` | VARCHAR(255) | Venue name/address |
| `type` | ENUM('free','paid') | Determines booking flow |
| `capacity` | SMALLINT | Total places (0 = unlimited) |
| `min_price` | DECIMAL(8,2) | Minimum amount for paid events (€25) |
| `is_active` | TINYINT(1) | 1 = shown on booking page |

### `tickets` table

| Column | Type | Notes |
|---|---|---|
| `id` | CHAR(36) | UUID v4 — encoded in QR code |
| `event_id` | INT UNSIGNED FK | References `events.id` |
| `name` | VARCHAR(255) | Attendee name |
| `email` | VARCHAR(255) | For confirmation email |
| `phone` | VARCHAR(30) | Required for MB Way |
| `amount_paid` | DECIMAL(8,2) | 0.00 for free tickets |
| `payment_status` | ENUM | `pending` / `paid` / `free` |
| `stripe_session_id` | VARCHAR(255) | Stripe Checkout session ID |
| `checked_in` | TINYINT(1) | 1 = scanned at door |
| `checked_in_at` | DATETIME | Timestamp of check-in |
| `paid_at` | DATETIME | Set by webhook on payment |
| `created_at` | DATETIME | Registration time |

---

## Creating and managing events

### Create a paid event (via phpMyAdmin)

```sql
INSERT INTO events
  (title, description, date, time_start, time_end, doors_open, location, type, capacity, min_price, is_active)
VALUES
  ('Ecstatic Dance Viseu #02',
   'Segunda edição. DJ convidado, jornada musical de 2h30.',
   '2025-06-21', '19:00:00', '22:30:00', '18:30:00',
   'Viseu', 'paid', 60, 25.00, 1);
```

### Create a free event

```sql
INSERT INTO events
  (title, description, date, time_start, time_end, doors_open, location, type, capacity, min_price, is_active)
VALUES
  ('Ecstatic Dance Viseu — Open Day',
   'Sessão experimental gratuita. Reserva obrigatória.',
   '2025-04-12', '18:00:00', '20:00:00', '17:30:00',
   'Viseu', 'free', 30, 0.00, 1);
```

### Deactivate an event (hide from booking page)

```sql
UPDATE events SET is_active = 0 WHERE id = 1;
```

### Check sold tickets for an event

```sql
SELECT
    COUNT(*) AS total,
    SUM(payment_status = 'paid') AS paid,
    SUM(payment_status = 'free') AS free,
    SUM(checked_in = 1) AS checked_in
FROM tickets
WHERE event_id = 1
  AND payment_status IN ('paid', 'free');
```

### Find a ticket by email

```sql
SELECT t.*, e.title AS event_title
FROM tickets t
JOIN events e ON e.id = t.event_id
WHERE t.email = 'pessoa@email.com'
ORDER BY t.created_at DESC;
```

### Manual refund / ticket deletion

Stripe refunds must be initiated in the Stripe Dashboard. After issuing the refund:

```sql
-- Optionally mark ticket as refunded (or delete it)
DELETE FROM tickets WHERE id = 'uuid-here';
```

---

## Useful queries

### Revenue by event

```sql
SELECT
    e.title,
    e.date,
    COUNT(t.id) AS tickets,
    SUM(t.amount_paid) AS revenue
FROM events e
LEFT JOIN tickets t ON t.event_id = e.id AND t.payment_status = 'paid'
GROUP BY e.id
ORDER BY e.date DESC;
```

### Pending payments older than 1 hour (possibly stuck)

```sql
SELECT * FROM tickets
WHERE payment_status = 'pending'
  AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR);
```

### Clean up stale pending tickets (run after confirming via Stripe Dashboard)

```sql
DELETE FROM tickets
WHERE payment_status = 'pending'
  AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
  AND stripe_session_id IS NOT NULL;
```

---

## Backups

In cPanel → **Backup Wizard**, schedule weekly backups of:
- The `ecstaticdanceviseu` database
- The `public_html/api/config.php` file (contains credentials)

Store backups off-server (local machine or cloud storage).
