<?php
/* ============================================================
   config.php — copy to config.php and fill in (not committed)

   ── Coolify (Nixpacks) / Docker / local ──
   Produção Coolify: copiar `environment.coolify.env` (paths `/var/www/edv-server/data/...` nos volumes).
   Com `EDV_REPLACE_CONFIG_FROM_EXAMPLE=1`, cada arranque gera `config.php` daqui.
   Omitir path env = defaults relativos a `server/data/` (dev e Nixpacks).
   Variáveis suportadas (todas opcionais):

   Base de dados (multi-driver via PDO):
     EDV_DB_DRIVER   (sqlite | mysql | pgsql)
     EDV_DB_HOST, EDV_DB_PORT, EDV_DB_NAME, EDV_DB_USER, EDV_DB_PASS
     EDV_DB_SSLMODE  (sobretudo para pgsql/supabase; ex.: require)

   Booleans (`true` / `false` / `1` / `0`):
     EDV_USE_SQLITE_MAIN_DB  — principal eventos+tickets SQLite vs MySQL
     EDV_LINK_USE_SQLITE     — fila /links em SQLite vs MySQL
     EDV_LINK_USE_JSON       — só dev local; deve ser false em produção

   Caminhos (opcional):
     EDV_MAIN_DB_SQLITE_PATH
     EDV_LINK_SQLITE_PATH
     EDV_LINK_JSON_PATH

   Stripe / app / correio / cron / admin:
     EDV_STRIPE_PUBLIC_KEY, EDV_STRIPE_SECRET_KEY, EDV_STRIPE_WEBHOOK_SECRET
     EDV_APP_URL, EDV_FROM_EMAIL, EDV_FROM_NAME
     EDV_RECONCILE_TOKEN, EDV_INSTALL_TOKEN
     EDV_ADMIN_PASSWORD_HASH   ( bcrypt; ou omite e mantém hash de exemplo / admin123 )
     EDV_ORG_NOTIFY_EMAIL, EDV_ORG_INFO_EMAIL

  Produção SQL (MySQL/MariaDB/PostgreSQL/Supabase) → define pelo menos:
    EDV_DB_DRIVER=mysql|pgsql
    EDV_LINK_USE_SQLITE=false
    EDV_LINK_USE_JSON=false
    EDV_DB_* = credenciais da base activa.

   ⚠️ O MySQL na hospedagem tem de aceitar conexões a partir do servidor Coolify,
   caso contrário mantém só o admin em cPanel ou usa rede/tunnel própria.
   ============================================================ */

$edv_bool = static function (string $name, bool $default): bool {
    $raw = getenv($name);
    if ($raw === false || trim((string) $raw) === '') {
        return $default;
    }

    $parsed = filter_var(
        trim((string) $raw),
        FILTER_VALIDATE_BOOLEAN,
        FILTER_NULL_ON_FAILURE
    );
    if ($parsed === null) {
        return $default;
    }

    return $parsed;
};

$edv_str = static function (string $name, string $default): string {
    $raw = getenv($name);
    if ($raw === false || trim((string) $raw) === '') {
        return $default;
    }

    return trim((string) $raw);
};

/* ── Reservas links.html (save/complete link-booking) ──
 * Escolhe **um** modo de armazenamento:
 * • **Local com SQLite:** `LINK_USE_SQLITE = true`, `LINK_USE_JSON = false` — requer pacote php-sqlite3 (PDO sqlite).
 * • **Local só com JSON:** `LINK_USE_SQLITE = false`, `LINK_USE_JSON = true` — dados em LINK_JSON_PATH (sem MySQL nem sqlite); útil quando o PDO sqlite não está instalado.
 * • **Produção (cPanel / MySQL):** `LINK_USE_SQLITE = false`, `LINK_USE_JSON = false` — usa DB_HOST… abaixo; necessária a tabela `link_registrations` (ver docs/DEPLOYMENT.md ou migration SQL).
 */

define('DB_HOST', $edv_str('EDV_DB_HOST', 'localhost'));
define('DB_PORT', $edv_str('EDV_DB_PORT', ''));
define('DB_NAME', $edv_str('EDV_DB_NAME', 'your_db'));
define('DB_USER', $edv_str('EDV_DB_USER', 'your_user'));
define('DB_PASS', $edv_str('EDV_DB_PASS', 'your_pass'));
define('DB_SSLMODE', $edv_str('EDV_DB_SSLMODE', 'require'));
define('DB_DRIVER', strtolower($edv_str('EDV_DB_DRIVER', '')));

/** Compatibilidade antiga: se DB_DRIVER vazio, `true` força SQLite para eventos/bilhetes. */
define('USE_SQLITE_MAIN_DB', $edv_bool('EDV_USE_SQLITE_MAIN_DB', true));
define(
    'MAIN_DB_SQLITE_PATH',
    $edv_str('EDV_MAIN_DB_SQLITE_PATH', __DIR__ . '/../data/events-tickets.sqlite')
);

define('LINK_USE_SQLITE', $edv_bool('EDV_LINK_USE_SQLITE', true));
define('LINK_USE_JSON', $edv_bool('EDV_LINK_USE_JSON', false));
define(
    'LINK_SQLITE_PATH',
    $edv_str('EDV_LINK_SQLITE_PATH', __DIR__ . '/../data/link-bookings.sqlite')
);
/** Ficheiro de desenvolvimento (ignorado no .gitignore). Nunca activar JSON em produção. */
define(
    'LINK_JSON_PATH',
    $edv_str('EDV_LINK_JSON_PATH', __DIR__ . '/../data/link-registrations-dev.json')
);

define(
    'STRIPE_PUBLIC_KEY',
    $edv_str('EDV_STRIPE_PUBLIC_KEY', 'YOUR_STRIPE_PUBLISHABLE_KEY_HERE')
);
define(
    'STRIPE_SECRET_KEY',
    $edv_str('EDV_STRIPE_SECRET_KEY', 'YOUR_STRIPE_SECRET_KEY_HERE')
);
define(
    'STRIPE_WEBHOOK_SECRET',
    $edv_str('EDV_STRIPE_WEBHOOK_SECRET', 'YOUR_STRIPE_WEBHOOK_SIGNING_SECRET_HERE')
);

define('APP_URL', $edv_str('EDV_APP_URL', 'https://ecstaticdanceviseu.pt'));
define('APP_TIMEZONE', $edv_str('EDV_APP_TIMEZONE', 'UTC'));
define('FROM_EMAIL', $edv_str('EDV_FROM_EMAIL', 'bilhetes@ecstaticdanceviseu.pt'));
define('FROM_NAME', $edv_str('EDV_FROM_NAME', 'Ecstatic Dance Viseu'));

define('RECONCILE_TOKEN', $edv_str('EDV_RECONCILE_TOKEN', 'change_me'));
define('INSTALL_TOKEN', $edv_str('EDV_INSTALL_TOKEN', 'change_me'));
/** Admin login password: omite EDV_ADMIN_PASSWORD_HASH e usa `admin123` com o hash de exemplo até substituires. */
define(
    'ADMIN_PASSWORD_HASH',
    $edv_str(
        'EDV_ADMIN_PASSWORD_HASH',
        '$2y$12$ep6Uq2RlUgQsVZy88gdICu96nDUokHKo9FilFZDEIPiO7M9jA6OpG'
    )
);

define(
    'ORG_NOTIFY_EMAIL',
    $edv_str('EDV_ORG_NOTIFY_EMAIL', 'hello@ecstaticdanceviseu.pt')
);
define(
    'ORG_INFO_EMAIL',
    $edv_str('EDV_ORG_INFO_EMAIL', 'info@ecstaticdanceviseu.pt')
);
