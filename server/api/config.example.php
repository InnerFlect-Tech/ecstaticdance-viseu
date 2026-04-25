<?php
/* ============================================================
   config.example.php — Template for server/api/config.php
   Copy this file to config.php and fill in real values.
   NEVER commit config.php to git.
   ============================================================ */

// ── Database (cPanel MySQL) ──
define('DB_HOST', 'localhost');
define('DB_NAME', 'cpanelusername_edviseu');   // cPanel format: user_dbname
define('DB_USER', 'cpanelusername_eduser');    // cPanel format: user_dbuser
define('DB_PASS', 'your_database_password');

// ── Stripe keys ──
// Find these in: Stripe Dashboard → Developers → API Keys
// Use test keys during development, switch to live keys for production
define('STRIPE_PUBLIC_KEY',    'YOUR_STRIPE_PUBLISHABLE_KEY_HERE');
define('STRIPE_SECRET_KEY',    'YOUR_STRIPE_SECRET_KEY_HERE');
define('STRIPE_WEBHOOK_SECRET', 'YOUR_STRIPE_WEBHOOK_SIGNING_SECRET_HERE');

// ── Application ──
define('APP_URL',    'https://ecstaticdanceviseu.pt');   // no trailing slash
define('FROM_EMAIL', 'bilhetes@ecstaticdanceviseu.pt');
define('FROM_NAME',  'Ecstatic Dance Viseu');

// ── Security tokens (generate with: openssl rand -hex 32) ──
define('RECONCILE_TOKEN', 'change_me_to_random_hex_string');
define('INSTALL_TOKEN',   'change_me_to_another_random_hex_string');

// ── Admin password ──
// Generate with: php -r "echo password_hash('yourpassword', PASSWORD_DEFAULT);"
define('ADMIN_PASSWORD_HASH', '$2y$12$xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');
