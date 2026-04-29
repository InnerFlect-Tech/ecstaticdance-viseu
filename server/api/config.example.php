<?php
/* ============================================================
   config.php — copy to config.php and fill in (not committed)
   ============================================================ */

define('DB_HOST', 'localhost');
define('DB_NAME', 'your_db');
define('DB_USER', 'your_user');
define('DB_PASS', 'your_pass');

/* ── Reservas links.html: save/complete link-booking ──
 * Local: `true` + SQLite (sem MySQL). Produção: `false` e credenciais MySQL de cima. */
define('LINK_USE_SQLITE', true);
/* Caminho do ficheiro .sqlite (o directório server/data/ deve ser gravável pelo PHP) */
define('LINK_SQLITE_PATH', __DIR__ . '/../data/link-bookings.sqlite');

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
