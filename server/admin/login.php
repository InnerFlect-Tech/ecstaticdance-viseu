<?php
/* ============================================================
   login.php — Admin login page
   ============================================================ */

require_once __DIR__ . '/auth.php';

start_admin_session();

// Already logged in
if (is_admin_logged_in()) {
    header('Location: /admin/');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';

    // Rate-limit: max 5 attempts per minute (stored in session)
    $now    = time();
    $window = 60;
    if (!isset($_SESSION['login_attempts'])) {
        $_SESSION['login_attempts'] = [];
    }
    // Remove attempts older than the window
    $_SESSION['login_attempts'] = array_filter(
        $_SESSION['login_attempts'],
        fn($t) => ($now - $t) < $window
    );

    if (count($_SESSION['login_attempts']) >= 5) {
        $error = 'Demasiadas tentativas. Aguarda um minuto.';
    } elseif (password_verify($password, ADMIN_PASSWORD_HASH)) {
        session_regenerate_id(true);
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['login_attempts']  = [];
        header('Location: /admin/');
        exit;
    } else {
        $_SESSION['login_attempts'][] = $now;
        $error = 'Palavra-passe incorrecta.';
    }
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="robots" content="noindex,nofollow" />
<title>Admin — Ecstatic Dance Viseu</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --dark: #0E0B09; --dark-m: #1A1210; --bone: #F5EFE6;
    --terra: #8B3A2A; --terra-l: #C4593F; --gold: #B8924A;
  }
  body { background: var(--dark); color: var(--bone); font-family: 'DM Sans', Arial, sans-serif;
         min-height: 100vh; display: flex; align-items: center; justify-content: center; font-weight: 300; }
  @import url('https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,300;9..40,400&family=Cormorant+Garamond:wght@300;400&display=swap');
  .card { width: 100%; max-width: 400px; padding: 3rem; border: 1px solid rgba(245,239,230,.1); }
  .eyebrow { font-size: .65rem; letter-spacing: .22em; text-transform: uppercase; color: var(--gold);
             font-weight: 400; margin-bottom: 2rem; display: flex; align-items: center; gap: 1rem; }
  .eyebrow::before { content: ''; width: 2rem; height: 1px; background: var(--gold); opacity: .5; }
  h1 { font-family: 'Cormorant Garamond', serif; font-size: 2rem; font-weight: 300; margin-bottom: 2.5rem; color: var(--bone); }
  label { display: block; font-size: .65rem; letter-spacing: .18em; text-transform: uppercase;
          color: rgba(245,239,230,.4); font-weight: 400; margin-bottom: .6rem; }
  input[type="password"] { width: 100%; background: rgba(245,239,230,.05); border: 1px solid rgba(245,239,230,.12);
                           color: var(--bone); padding: .9rem 1.1rem; font-size: .93rem; outline: none;
                           font-family: inherit; font-weight: 300; border-radius: 0; -webkit-appearance: none; }
  input[type="password"]:focus { border-color: rgba(245,239,230,.3); }
  .error { background: rgba(196,89,63,.15); border: 1px solid rgba(196,89,63,.3); padding: .8rem 1rem;
           font-size: .84rem; color: var(--bone); margin-bottom: 1.5rem; line-height: 1.5; }
  button { width: 100%; margin-top: 1.5rem; background: var(--terra); color: var(--bone); border: none;
           padding: 1rem; font-size: .75rem; letter-spacing: .14em; text-transform: uppercase;
           cursor: pointer; font-family: inherit; font-weight: 400; transition: background .2s; }
  button:hover { background: var(--terra-l); }
  .back { display: block; margin-top: 1.5rem; font-size: .72rem; color: rgba(245,239,230,.3);
          text-align: center; text-decoration: none; letter-spacing: .1em; }
  .back:hover { color: rgba(245,239,230,.6); }
</style>
</head>
<body>
<div class="card">
  <p class="eyebrow">Ecstatic Dance Viseu</p>
  <h1>Acesso<br>admin</h1>

  <?php if ($error): ?>
    <div class="error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="POST" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(bin2hex(random_bytes(16))) ?>">
    <label for="password">Palavra-passe</label>
    <input type="password" id="password" name="password" required autofocus />
    <button type="submit">Entrar</button>
  </form>

  <a href="/" class="back">← Voltar ao site</a>
</div>
</body>
</html>
