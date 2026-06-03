# Deployment Guide — Ecstatic Dance Viseu

Deploy the Vite static site and PHP backend at **ecstaticdanceviseu.pt**.

> **Produção Hetzner (Coolify):** o site público está em **Nixpacks + SQLite em volumes**, não neste fluxo cPanel. Usa **[COOLIFY.md](./COOLIFY.md)** e **`environment.coolify.env`**. Este guia aplica-se a **cPanel / MySQL** ou migração futura para SQL gerido.

---

## Prerequisites

- PHP 8.1+ with required PDO driver(s):
  - `pdo_sqlite` for SQLite
  - `pdo_mysql` for MySQL / MariaDB
  - `pdo_pgsql` for PostgreSQL / Supabase
- SSH access (recommended) or FTP client
- Node.js 18+ on your local machine
- A Stripe account (see [STRIPE.md](./STRIPE.md))

---

## First-time setup

### 1. Prepare the database backend

Choose one:

- **SQLite** (local/dev): no server needed; use file path in `EDV_MAIN_DB_SQLITE_PATH`.
- **MySQL/MariaDB** (common cPanel path): create database + user + privileges.
- **PostgreSQL/Supabase**: collect host, port, db name, user, password, SSL mode.

#### MySQL/MariaDB quick setup

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
- `DB_DRIVER` (`sqlite`, `mysql`, or `pgsql`)
- `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`
- `DB_SSLMODE` (recommended `require` for Supabase/PostgreSQL over network)
- `APP_TIMEZONE` (recommended `UTC`)
- `STRIPE_PUBLIC_KEY`, `STRIPE_SECRET_KEY` (from Stripe Dashboard)
- `STRIPE_WEBHOOK_SECRET` (from step 4 below)
- `RECONCILE_TOKEN` — run `openssl rand -hex 32` to generate
- `INSTALL_TOKEN` — run `openssl rand -hex 32` to generate
- `ADMIN_PASSWORD_HASH` — `config.example.php` is pre-filled for password **`admin123`**. To use a different password, run `php -r "echo password_hash('yourpassword', PASSWORD_DEFAULT);"` and replace the hash in `config.php`.

#### Supabase/PostgreSQL notes

- Use `DB_DRIVER=pgsql`.
- For Supabase, use either:
  - Session pooler connection host/port from the dashboard, or
  - Direct DB host if your environment/network supports it.
- Keep `DB_SSLMODE=require` unless your provider explicitly instructs otherwise.

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

The installer now picks schema by active PDO driver:
- `mysql` -> `server/setup/schema.sql`
- `sqlite` -> `server/setup/schema-main-sqlite.sql`
- `pgsql` -> `server/setup/schema-pgsql.sql`

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

### Link hub (`links.html`) — MB Way, transferência, Revolut

O fluxo público usa:

- **`/api/save-link-booking.php`** — passo 1  
- **`/api/complete-link-booking.php`** — passo 2 (multipart ou JSON)

Os dados vão para o backend SQL activo (`mysql`/`pgsql`) ou SQLite quando configurado.

1. **Criar a tabela na base já existente** (executar **uma vez** no teu cliente SQL):

   - MySQL/MariaDB: `server/setup/migration_2026_04_link_registrations.sql`
   - PostgreSQL/Supabase: `server/setup/migration_2026_04_link_registrations.pgsql.sql`

   *(Instalações novas criadas apenas com `server/setup/schema.sql` já trazem a tabela.)*

2. **Produção: `public_html/api/config.php`**

   - `DB_DRIVER` conforme o backend escolhido (`mysql` ou `pgsql`)
   - `LINK_USE_SQLITE` → **`false`** para armazenamento SQL centralizado
   - `LINK_USE_JSON` → **`false`** (JSON é só desenvolvimento local; **nunca** em cPanel.)

3. **Upload** destes ficheiros para `public_html/api/` ao actualizares código:

   - `save-link-booking.php`, `complete-link-booking.php`
   - `link-common.php`, `link-json-store.php`

4. **Pasta gravável para comprovativos** (upload PDF/imagem até 5 MB):

   `public_html/uploads/link-proofs/`

   Cria a pasta pelo File Manager/FTP se não existir; confirma que o utilizador PHP consegue escrever (permissões habituais 0755; se falhar, o painel da hospedagem indica valores correctos).

### 9. Test end-to-end

1. Visit `https://ecstaticdanceviseu.pt/bilhetes.html` — event should load
2. Make a test booking with Stripe test card `4242 4242 4242 4242`
3. Verify confirmation email arrives
4. Log in to admin: `https://ecstaticdanceviseu.pt/admin/`
5. Confirm ticket appears and check-in works

---

## Post-deploy SQL (junho 2026 — executar uma vez em produção)

No phpMyAdmin ou cliente MySQL, **por ordem**:

1. `server/setup/migration_2026_06_event_02.sql` — evento #02 activo  
2. `server/setup/migration_2026_06_link_booking_emails.sql` — emails reserva manual  
3. `server/setup/migration_2026_06_attendance_returning.sql` — presenças + preço regresso  
4. `server/setup/migration_2026_06_event_settlement.sql` — custos base + repartição %  
5. `server/setup/migration_2026_06_event_01_accounts.sql` — contas fechadas #01  
6. `server/setup/migration_2026_06_event_01_attendance.sql` — garante evento #01  

Depois no admin:

- **Presenças** → evento #01 → «Importar folha #01» (10 presentes)  
- **Custos** → evento #01 → «Repor dados #01» se a folha de contas não aparecer  

Em SQLite local, as colunas/tabelas são criadas ao abrir admin; corre os ficheiros `*.sqlite.sql` equivalentes se necessário.

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
│   ├── save-link-booking.php
│   ├── complete-link-booking.php
│   ├── link-common.php
│   ├── link-json-store.php
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
| `Driver não suportado` / `PDO ... não está disponível` | Confirm `DB_DRIVER` and install matching PDO extension (`pdo_sqlite`, `pdo_mysql`, or `pdo_pgsql`). |
| Links page: erro ao gravar / comprovativo | Confirma tabela `link_registrations`, `LINK_USE_SQLITE`/`LINK_USE_JSON` falsos em produção, pasta `uploads/link-proofs` gravável (`docs/DEPLOYMENT.md` → Link hub). |
| Emails not sending | Verify `FROM_EMAIL` in config.php matches a cPanel email account |
| Stripe webhook fails | Check Stripe Dashboard → Webhooks → Recent events for error details |
| Admin redirect loop | Clear cookies; check session cookie settings in auth.php |
| QR scanner won't start | HTTPS is required for camera access; confirm SSL certificate is active |
