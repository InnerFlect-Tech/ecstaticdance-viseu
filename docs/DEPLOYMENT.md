# Deployment Guide — Ecstatic Dance Viseu

Deploy the Vite static site and PHP backend to cPanel shared hosting at **ecstaticdanceviseu.pt**.

---

## Prerequisites

- cPanel hosting with PHP 8.1+ and MySQL 5.7+
- SSH access (recommended) or FTP client
- Node.js 18+ on your local machine
- A Stripe account (see [STRIPE.md](./STRIPE.md))

---

## First-time setup

### 1. Create the MySQL database

1. Log in to cPanel → **MySQL Databases**
2. Create a database, e.g. `cpanelusername_edviseu`
3. Create a user, e.g. `cpanelusername_eduser`, with a strong password
4. Add the user to the database with **All Privileges**

### 2. Configure the PHP backend

```bash
# On your local machine, copy the example config
cp server/api/config.example.php server/api/config.php
```

Edit `server/api/config.php` and fill in:
- `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` (from step 1)
- `STRIPE_PUBLIC_KEY`, `STRIPE_SECRET_KEY` (from Stripe Dashboard)
- `STRIPE_WEBHOOK_SECRET` (from step 4 below)
- `RECONCILE_TOKEN` — run `openssl rand -hex 32` to generate
- `INSTALL_TOKEN` — run `openssl rand -hex 32` to generate
- `ADMIN_PASSWORD_HASH` — run:
  ```bash
  php -r "echo password_hash('yourpassword', PASSWORD_DEFAULT);"
  ```

### 3. Build the Vite site locally

```bash
npm install
npm run build
# Output: dist/
```

**Always build locally — never on the cPanel server.**

### 4. Upload files

Using FTP (FileZilla, Cyberduck) or cPanel File Manager:

| Local path | Upload to (cPanel) |
|---|---|
| `dist/*` | `public_html/` |
| `server/api/` | `public_html/api/` |
| `server/admin/` | `public_html/admin/` |
| `server/setup/` | `public_html/setup/` |

> The `server/api/config.php` file is **gitignored** and must be uploaded manually.

### 5. Run the installer

Open in your browser:
```
https://ecstaticdanceviseu.pt/setup/install.php?token=YOUR_INSTALL_TOKEN
```

You should see all green checkmarks. After success:

```bash
# Via SSH — delete the setup folder
rm -rf ~/public_html/setup/
```

Or delete via cPanel File Manager.

### 6. Configure the Stripe webhook

See [STRIPE.md](./STRIPE.md) — set the webhook URL to:
```
https://ecstaticdanceviseu.pt/api/webhook.php
```

### 7. Set up the reconciliation cron job

In cPanel → **Cron Jobs**, add:

```
*/15 * * * * curl -s "https://ecstaticdanceviseu.pt/api/reconcile.php?token=YOUR_RECONCILE_TOKEN" > /dev/null 2>&1
```

This reconciles any Stripe payments that were missed by the webhook.

### 8. Create the first event

In cPanel → **phpMyAdmin**, run:

```sql
INSERT INTO events
  (title, description, date, time_start, time_end, doors_open, location, type, capacity, min_price, is_active)
VALUES
  ('Ecstatic Dance Viseu #01',
   'A primeira edição em Viseu. DJ convidado com jornada musical de 2h30.',
   '2025-05-17', '19:00:00', '22:30:00', '18:30:00',
   'Viseu (local a confirmar)', 'paid', 60, 25.00, 1);
```

### 9. Test end-to-end

1. Visit `https://ecstaticdanceviseu.pt/bilhetes.html` — event should load
2. Make a test booking with Stripe test card `4242 4242 4242 4242`
3. Verify confirmation email arrives
4. Log in to admin: `https://ecstaticdanceviseu.pt/admin/`
5. Confirm ticket appears and check-in works

---

## Subsequent deployments

After making changes to the Vite site:

```bash
npm run build
# Upload dist/* to public_html/ (overwrite)
```

If you changed PHP files:
```bash
# Upload only the changed files via FTP
# e.g. server/api/create-checkout.php → public_html/api/create-checkout.php
```

---

## File structure on cPanel

```
public_html/
├── index.html          ← Vite output
├── sobre.html
├── eventos.html
├── galeria.html
├── faq.html
├── contacto.html
├── bilhetes.html
├── confirmacao.html
├── cancelamento.html
├── assets/             ← Vite JS/CSS chunks
├── css/
├── public/
├── api/
│   ├── config.php      ← credentials (NOT in git)
│   ├── helpers.php
│   ├── get-events.php
│   ├── create-checkout.php
│   ├── register-free.php
│   ├── webhook.php
│   ├── verify-ticket.php
│   ├── reconcile.php
│   └── .htaccess
└── admin/
    ├── index.php
    ├── login.php
    ├── logout.php
    ├── checkin.php
    ├── export.php
    ├── auth.php
    └── .htaccess
```

---

## Troubleshooting

| Problem | Solution |
|---|---|
| API returns 500 | Check PHP error log in cPanel → Error Log |
| Emails not sending | Verify `FROM_EMAIL` in config.php matches a cPanel email account |
| Stripe webhook fails | Check Stripe Dashboard → Webhooks → Recent events for error details |
| Admin redirect loop | Clear cookies; check session cookie settings in auth.php |
| QR scanner won't start | HTTPS is required for camera access; confirm SSL certificate is active |
