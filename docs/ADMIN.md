# Admin Panel Guide — Ecstatic Dance Viseu

The admin panel is a PHP-based dashboard for managing ticket check-ins and viewing registrations.

**URL:** `https://ecstaticdanceviseu.pt/admin/`

---

## First login

### Setting the admin password

Generate a password hash using PHP:

```bash
php -r "echo password_hash('yourpassword', PASSWORD_DEFAULT);"
```

Copy the output (starts with `$2y$12$...`) into `server/api/config.php`:

```php
define('ADMIN_PASSWORD_HASH', '$2y$12$xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');
```

Then upload the updated `config.php` to cPanel.

### Logging in

1. Go to `https://ecstaticdanceviseu.pt/admin/`
2. You will be redirected to the login page
3. Enter the password you set above
4. Session expires when you close the browser

---

## Dashboard overview

### Sidebar (left)

Lists all events ordered by most recent. Each event shows:
- Title and date
- `X/Y entradas` — check-ins vs total tickets

Click an event to view its tickets.

### Stats row

For the selected event:
| Stat | Description |
|---|---|
| **Bilhetes** | Total confirmed tickets (paid + free) |
| **Pagos** | Paid via Stripe |
| **Gratuitos** | Free registrations |
| **Entradas** | Scanned or manually checked in |

### Ticket table

Shows all confirmed tickets with:
- Name, email, phone
- Payment status badge
- Amount paid
- Registration timestamp
- Check-in button

---

## Check-in at the door

### Method 1: QR code scanner (recommended)

1. Open `https://ecstaticdanceviseu.pt/admin/` on a smartphone
2. Log in if prompted
3. Tap the **Scan QR** button (top right)
4. Allow camera access when prompted
5. Point the camera at the attendee's QR code

**Results:**
- **Green** → Valid entry, attendee's name shown
- **Red** → Invalid ticket or already used

The scanner vibrates on result (on supported devices). Results clear after 5 seconds so you can scan the next ticket.

> Camera access requires HTTPS — this works automatically on the live site.

### Method 2: Manual check-in

In the ticket table, find the attendee (use the search field) and click **Marcar entrada**. The button turns green with the timestamp.

To undo a check-in, click the green button again.

---

## Filtering and searching tickets

Use the toolbar above the table:
- **Search field** — filter by name or email (partial match)
- **Filter dropdown** — All / Pagos / Gratuitos / Com entrada / Sem entrada
- Click **Filtrar** or press Enter

---

## Exporting to CSV

Click **Exportar CSV** in the top bar to download all confirmed tickets for the selected event as a `.csv` file.

The CSV includes: ID, Name, Email, Phone, Status, Amount, Check-in status, Check-in time, Registration time.

The file uses UTF-8 with BOM (compatible with Excel and Google Sheets).

---

## Managing events

Events are created and managed via **cPanel → phpMyAdmin**.

### Create a new event

```sql
INSERT INTO events
  (title, description, date, time_start, time_end, doors_open, location, type, capacity, min_price, is_active)
VALUES
  ('Ecstatic Dance Viseu #03', 'Descrição do evento.', '2025-07-19',
   '19:00:00', '22:30:00', '18:30:00', 'Viseu', 'paid', 60, 25.00, 1);
```

### Hide an event (sold out or past)

```sql
UPDATE events SET is_active = 0 WHERE id = N;
```

Only events with `is_active = 1` and `date >= TODAY` appear on the booking page.

---

## Security notes

- The admin URL is not linked from the public site
- Sessions expire on browser close
- Maximum 5 login attempts per minute before a 1-minute lockout
- All admin PHP files block direct access to `auth.php` via `.htaccess`
- For extra protection, you can restrict access by IP in `server/admin/.htaccess`:
  ```apache
  <RequireAll>
    Require ip YOUR.IP.ADDRESS
  </RequireAll>
  ```

---

## Troubleshooting

| Problem | Solution |
|---|---|
| Scanner won't open camera | Ensure you are on HTTPS and allow camera permission in browser |
| "Não autorizado" on check-in | Session expired — log in again |
| Ticket not found after payment | Wait 30 seconds (webhook delay) or check reconcile log |
| Admin panel slow to load | Large number of tickets — use event filter in sidebar |
| Login loop | Clear browser cookies and try again |
