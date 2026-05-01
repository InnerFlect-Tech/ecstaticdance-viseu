<?php
/* ============================================================
   config.php — copy to config.php and fill in (not committed)
   ============================================================ */

/* ── Reservas links.html (save/complete link-booking) ──
 * Escolhe **um** modo de armazenamento:
 * • **Local com SQLite:** `LINK_USE_SQLITE = true`, `LINK_USE_JSON = false` — requer pacote php-sqlite3 (PDO sqlite).
 * • **Local só com JSON:** `LINK_USE_SQLITE = false`, `LINK_USE_JSON = true` — dados em LINK_JSON_PATH (sem MySQL nem sqlite); útil quando o PDO sqlite não está instalado.
 * • **Produção (cPanel / MySQL):** `LINK_USE_SQLITE = false`, `LINK_USE_JSON = false` — usa DB_HOST… abaixo; necessária a tabela `link_registrations` (ver docs/DEPLOYMENT.md ou migration SQL).
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'your_db');
define('DB_USER', 'your_user');
define('DB_PASS', 'your_pass');

/** Local sem extensão MySQL: `true` + ficheiro SQLite (ver schema-main-sqlite.sql). Produção: `false`. */
define('USE_SQLITE_MAIN_DB', false);
define('MAIN_DB_SQLITE_PATH', __DIR__ . '/../data/events-tickets.sqlite');

define('LINK_USE_SQLITE', true);
define('LINK_USE_JSON', false);
define('LINK_SQLITE_PATH', __DIR__ . '/../data/link-bookings.sqlite');
/** Ficheiro de desenvolvimento (ignorado no .gitignore). Nunca activar JSON em produção. */
define('LINK_JSON_PATH', __DIR__ . '/../data/link-registrations-dev.json');

define('STRIPE_PUBLIC_KEY',    'YOUR_STRIPE_PUBLISHABLE_KEY_HERE');
define('STRIPE_SECRET_KEY',    'YOUR_STRIPE_SECRET_KEY_HERE');
define('STRIPE_WEBHOOK_SECRET', 'YOUR_STRIPE_WEBHOOK_SIGNING_SECRET_HERE');

define('APP_URL',     'https://ecstaticdanceviseu.pt');
define('FROM_EMAIL',  'bilhetes@ecstaticdanceviseu.pt');
define('FROM_NAME',   'Ecstatic Dance Viseu');

define('RECONCILE_TOKEN', 'change_me');
define('INSTALL_TOKEN',   'change_me');
define('ADMIN_PASSWORD_HASH', '$2y$12$...');

define('ORG_NOTIFY_EMAIL', 'hello@ecstaticdanceviseu.pt');
define('ORG_INFO_EMAIL',   'info@ecstaticdanceviseu.pt');
