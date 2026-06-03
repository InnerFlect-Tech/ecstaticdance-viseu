# Database Guide — Ecstatic Dance Viseu

## Coolify (produção actual) — SQLite

No Hetzner, com **Nixpacks**, a app usa **dois ficheiros SQLite** no volume persistente `/var/www/edv-server/data/`:

| Ficheiro | Tabelas principais |
|----------|-------------------|
| `events-tickets.sqlite` | `events`, `tickets`, `event_attendance`, `event_costs`, `event_settlement_shares`, … |
| `link-bookings.sqlite` | `link_registrations` (fluxo `/links`) |

Configuração: `environment.coolify.env` (`EDV_USE_SQLITE_MAIN_DB=true`, paths `/var/www/edv-server/data/...`).

- Schema inicial: criado/actualizado pelo PHP ao usar admin/API; ficheiros `server/setup/schema-main-sqlite.sql` e migrations `migration_*.sql` para referência.
- Backup: Admin → exportar base / cópia do volume `edv-data`.
- **MySQL no Coolify:** possível no futuro (`EDV_USE_SQLITE_MAIN_DB=false`, migrations MySQL) — ver [COOLIFY.md](./COOLIFY.md).

---

## cPanel — MySQL

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
| `returning_min_eur` | DECIMAL(8,2) NULL | Floor price (€) for returning dancers on this event; NULL = 15€ default |
| `is_active` | TINYINT(1) | 1 = shown on booking page |

### `event_attendance` table (who actually came)

One row per person per event, filled when they **check in at the door** (QR scan or manual check-in). This is the source of truth for “returning dancer” pricing — not ticket sales alone.

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT | Primary key |
| `event_id` | INT FK | References `events.id` |
| `ticket_id` | CHAR(36) FK | References `tickets.id` |
| `email` | VARCHAR(255) | Normalised lowercase; **unique per event** |
| `name` | VARCHAR(255) | From ticket at check-in |
| `phone` | VARCHAR(40) | From ticket |
| `amount_paid` | DECIMAL(8,2) | Ticket amount |
| `checked_in_at` | DATETIME | Door entry time |

**Returning dancer rule:** if `email` exists in `event_attendance` (or legacy `tickets.checked_in = 1`) for any event with `date` **before** the event being purchased, checkout uses `events.returning_min_eur` (or 15€ default) as the sliding-scale floor. Stored on the ticket as `price_tier = 'returning'`.

Admin: **Presenças** (`/admin/attendance.php`) lists attendees per event and exports CSV.

**Edição #01:** folha à porta importada via «Importar folha #01» (10 presentes / 12 bilhetes). Sem email na folha → marcador `tel+…@presenca.ecstaticdanceviseu.pt`; desconto de regresso também por telemóvel na reserva.

Migrations: `migration_2026_06_attendance_returning.sql`, `migration_2026_06_event_01_attendance.sql`

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
| `price_tier` | VARCHAR | `standard` \| `early_bird` \| `returning` — audit of pricing tier used |
| `paid_at` | DATETIME | Set by webhook on payment |
| `created_at` | DATETIME | Registration time |

### `event_costs` — custos base e outros

| Column | Notes |
|---|---|
| `cost_bucket` | `base` = custos base (subtraídos da receita antes das %); `expense` = outros/reembolsos (fora da folha) |
| `base_cost_slug` | `transportes`, `flyers`, `comidas`, `promo_online` (linhas da folha) |
| `cost_stage` | `actual` \| `promised` |
| `amount_eur` | Valor em euros |

### `event_settlement_shares` — repartição %

Per-event rows: Espaço (25% pós-custos-base), Facilitadores/DJ (% pós-espaço), Indias/Carolina (50/50 do restante).

| `pool` | Applied to |
|---|---|
| `post_base` | Receita − custos base |
| `post_venue` | Após deduzir espaço |
| `final` | Lucro após equipa |

Admin: **Custos** → folha de cálculo + percentagens. Migration: `migration_2026_06_event_settlement.sql`.

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
