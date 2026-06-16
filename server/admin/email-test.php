<?php
declare(strict_types=1);

/**
 * Teste de envio de email — manda o template real do bilhete (com QR) para
 * um endereço à escolha, sem criar bilhete na BD. O QR usa uma referência
 * "TESTE-…" que nunca valida no scanner.
 *
 * Serve para verificar entregabilidade (inbox vs spam) do PHP mail().
 */
require_once __DIR__ . '/auth.php';
require_admin_session();

$pdo = db();
$flash = '';
$flashError = false;

$events = $pdo->query('SELECT id, title, date FROM events ORDER BY date DESC LIMIT 20')
    ->fetchAll(PDO::FETCH_ASSOC);

$defaultTo = 'daniel@innerflect.tech';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'send_test') {
    $to = strtolower(trim((string) ($_POST['to'] ?? '')));
    $eventId = (int) ($_POST['event_id'] ?? 0);

    $event = null;
    foreach ($events as $ev) {
        if ((int) $ev['id'] === $eventId) {
            $event = $ev;
            break;
        }
    }

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $flash = 'Indica um email válido.';
        $flashError = true;
    } elseif ($event === null) {
        $flash = 'Escolhe um evento.';
        $flashError = true;
    } else {
        $testRef = 'TESTE-' . strtoupper(substr(generate_uuid(), 0, 8));
        $ok = send_ticket_email(
            $to,
            'Teste de Entrega',
            $testRef,
            '[TESTE] ' . (string) $event['title'],
            (string) $event['date'],
            25.0
        );
        if ($ok) {
            $flash = "Email de teste aceite pelo servidor para {$to} (ref {$testRef}). "
                . 'Confirma na caixa de entrada E na pasta de spam — mail() aceitar não garante entrega.';
        } else {
            $flash = 'mail() devolveu falha — o servidor não aceitou o envio. Ver logs do contentor.';
            $flashError = true;
        }
    }
}

function et_h(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="robots" content="noindex,nofollow" />
  <title>Teste de email — Admin</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root { --dark:#0E0B09; --dark-m:#1A1210; --bone:#F5EFE6; --gold:#D4A85A; --gold-l:#B8924A; }
    body { background: var(--dark); color: var(--bone); font-family: Arial, sans-serif; font-size: 14px; }
    <?php require __DIR__ . '/_topbar-styles.php'; ?>
    .main { max-width: 720px; margin: 0 auto; padding: 1.2rem 1rem 2.5rem; }
    .head h1 { font-weight: 300; font-size: 1.5rem; }
    .head p { color: rgba(245,239,230,.5); font-size: .82rem; margin-top: .35rem; line-height: 1.55; }
    .flash { background: rgba(45,106,79,.18); border: 1px solid rgba(45,106,79,.36); padding: .65rem .85rem; margin: 1rem 0; border-radius: 8px; font-size: .82rem; line-height: 1.5; }
    .flash-error { background: rgba(139,48,48,.2); border-color: rgba(180,70,70,.45); }
    form { display: flex; flex-direction: column; gap: .7rem; margin-top: 1.2rem; max-width: 26rem; }
    input, select {
      background: rgba(245,239,230,.06); border: 1px solid rgba(245,239,230,.16); color: var(--bone);
      padding: .5rem .6rem; border-radius: 8px; font-size: .85rem; font-family: inherit;
    }
    label { font-size: .68rem; letter-spacing: .08em; text-transform: uppercase; color: rgba(245,239,230,.45); }
    .btn { border: 1px solid rgba(184,146,74,.5); background: rgba(184,146,74,.14); color: var(--gold); cursor: pointer;
      padding: .55rem .9rem; border-radius: 8px; font-size: .74rem; text-transform: uppercase; letter-spacing: .08em; align-self: flex-start; }
    .hint-box { background: rgba(212,168,90,.08); border: 1px solid rgba(212,168,90,.22);
      padding: .85rem 1rem; border-radius: 8px; font-size: .78rem; line-height: 1.6;
      color: rgba(245,239,230,.65); margin-top: 1.6rem; }
    code { color: var(--gold); }
  </style>
</head>
<body class="has-bottom-tabs">
<?php
$__adminNav = 'tickets';
require __DIR__ . '/_topbar.php';
?>

<main class="main">
  <div class="head">
    <h1>Teste de envio de email</h1>
    <p>
      Envia o template real do bilhete (QR incluído) sem criar nada na base de dados.
      A referência <code>TESTE-…</code> nunca valida no scanner.
    </p>
  </div>

  <?php if ($flash !== ''): ?>
    <div class="flash<?= $flashError ? ' flash-error' : '' ?>"><?= et_h($flash) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="send_test" />
    <label for="to">Enviar para</label>
    <input type="email" id="to" name="to" value="<?= et_h((string) ($_POST['to'] ?? $defaultTo)) ?>" required />
    <label for="event_id">Evento (título e data usados no email)</label>
    <select id="event_id" name="event_id">
      <?php foreach ($events as $ev): ?>
        <option value="<?= (int) $ev['id'] ?>" <?= (int) $ev['id'] === (int) ($_POST['event_id'] ?? 0) ? 'selected' : '' ?>>
          <?= et_h((string) $ev['title']) ?> — <?= date('d/m/Y', strtotime((string) $ev['date'])) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn">Enviar email de teste</button>
  </form>

  <div class="hint-box">
    <strong>Como avaliar o resultado:</strong><br>
    1. «Aceite pelo servidor» só significa que o <code>mail()</code> entregou ao MTA local — não que chegou.<br>
    2. Verifica a caixa de entrada <em>e o spam</em> do destinatário.<br>
    3. Se cair em spam ou não chegar: o domínio precisa de SPF/DKIM alinhados com o IP do servidor,
       ou de migrar o envio para SMTP autenticado (ex.: conta <code>bilhetes@ecstaticdanceviseu.pt</code>).
  </div>
</main>
</body>
</html>
