<?php
/**
 * Emails ao participante — reservas manuais (/links).
 */
declare(strict_types=1);

require_once __DIR__ . '/link-common.php';

function link_send_customer_html(string $to_email, string $subject, string $html): bool
{
    if ($to_email === '' || !filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    if (!function_exists('mail')) {
        return false;
    }
    $fromName  = defined('FROM_NAME') && is_string(FROM_NAME) ? FROM_NAME : 'Ecstatic Dance Viseu';
    $fromEmail = defined('FROM_EMAIL') && is_string(FROM_EMAIL) ? FROM_EMAIL : 'bilhetes@ecstaticdanceviseu.pt';
    $replyTo   = link_org_info();

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= 'From: ' . $fromName . ' <' . $fromEmail . ">\r\n";
    $headers .= 'Reply-To: ' . $replyTo . "\r\n";
    $headers .= "X-Mailer: PHP/" . PHP_VERSION . "\r\n";

    $enc = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    return @mail($to_email, $enc, $html, $headers);
}

/**
 * @return array{title:string, date:string, date_fmt:string}
 */
function link_event_meta_from_slug(string $slug): array
{
    $title = 'Ecstatic Dance Viseu';
    $date  = '';
    if (preg_match('/edv-(\d{4}-\d{2}-\d{2})$/', $slug, $m)) {
        $date = $m[1];
    }
    $date_fmt = '';
    if ($date !== '') {
        try {
            $dt = new DateTime($date);
            $months = [
                1 => 'janeiro', 2 => 'fevereiro', 3 => 'março', 4 => 'abril',
                5 => 'maio', 6 => 'junho', 7 => 'julho', 8 => 'agosto',
                9 => 'setembro', 10 => 'outubro', 11 => 'novembro', 12 => 'dezembro',
            ];
            $m = (int) $dt->format('n');
            $date_fmt = $dt->format('j') . ' de ' . ($months[$m] ?? $dt->format('F')) . ' de ' . $dt->format('Y');
        } catch (Throwable) {
            $date_fmt = $date;
        }
    }

    return ['title' => $title, 'date' => $date, 'date_fmt' => $date_fmt];
}

/**
 * Email 1 — pedido recebido, pagamento em verificação.
 *
 * @param array<string,mixed> $row link_registrations row
 */
function link_send_booking_received_email(array $row): bool
{
    $email = trim((string) ($row['email'] ?? ''));
    $name  = trim((string) ($row['name'] ?? ''));
    $ref   = trim((string) ($row['payment_ref'] ?? ''));
    $slug  = trim((string) ($row['event_slug'] ?? ''));
    $total = (float) ($row['total_euros'] ?? 0);
    if ($email === '' || $ref === '') {
        return false;
    }

    $meta     = link_event_meta_from_slug($slug);
    $app_url  = defined('APP_URL') && is_string(APP_URL) ? APP_URL : 'https://ecstaticdanceviseu.pt';
    $info     = link_org_info();
    $total_fmt = '€' . number_format($total, 2, ',', ' ');
    $first     = $name !== '' ? explode(' ', $name, 2)[0] : 'Olá';
    $when      = $meta['date_fmt'] !== '' ? $meta['date_fmt'] : 'a próxima edição';

    $subject = 'Recebemos o teu pedido — ' . $meta['title'];

    ob_start(); ?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<style>
  body{font-family:Arial,sans-serif;background:#F5EFE6;margin:0;padding:20px}
  .wrap{max-width:560px;margin:0 auto;background:#1A1210;padding:40px;color:#F5EFE6}
  .eyebrow{font-size:11px;letter-spacing:3px;text-transform:uppercase;color:#B8924A;margin-bottom:20px}
  h1{font-size:26px;font-weight:300;margin:0 0 12px;line-height:1.25}
  .lead{font-size:15px;color:rgba(245,239,230,.72);line-height:1.65;margin:0 0 28px}
  .box{background:rgba(245,239,230,.06);border:1px solid rgba(245,239,230,.12);border-radius:8px;padding:20px;margin-bottom:24px}
  .row{display:flex;justify-content:space-between;gap:12px;padding:8px 0;border-bottom:1px solid rgba(245,239,230,.08);font-size:13px}
  .row:last-child{border-bottom:0}
  .lbl{color:rgba(245,239,230,.45)}
  .val{color:#F5EFE6;text-align:right}
  .ref{font-family:monospace;letter-spacing:.08em;color:#D4A85A}
  .steps{margin:0;padding:0;list-style:none}
  .steps li{font-size:13px;color:rgba(245,239,230,.55);line-height:1.7;padding:6px 0 6px 1.2rem;position:relative}
  .steps li::before{content:'';position:absolute;left:0;top:.85rem;width:6px;height:6px;border-radius:50%;background:#B8924A}
  .note{margin-top:24px;font-size:12px;color:rgba(245,239,230,.38);line-height:1.7}
  .link{color:#B8924A;text-decoration:none}
</style>
</head>
<body>
<div class="wrap">
  <p class="eyebrow">Ecstatic Dance Viseu</p>
  <h1>Obrigado, <?= htmlspecialchars($first) ?> —<br>recebemos o teu pedido.</h1>
  <p class="lead">
    O teu pedido de bilhete para <strong><?= htmlspecialchars($when) ?></strong> está registado.
    Estamos a verificar o pagamento; assim que estiver confirmado, enviamos um segundo email com o bilhete e código QR para a entrada.
  </p>
  <div class="box">
    <div class="row"><span class="lbl">Referência</span><span class="val ref"><?= htmlspecialchars($ref) ?></span></div>
    <div class="row"><span class="lbl">Montante indicado</span><span class="val"><?= htmlspecialchars($total_fmt) ?></span></div>
    <div class="row"><span class="lbl">Nome</span><span class="val"><?= htmlspecialchars($name) ?></span></div>
  </div>
  <p style="font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:rgba(245,239,230,.4);margin:0 0 10px">O que acontece a seguir</p>
  <ul class="steps">
    <li>Verificamos o comprovativo (ou o pagamento associado à referência).</li>
    <li>Confirmamos por email — em regra até 48 horas úteis.</li>
    <li>No email de confirmação recebes o bilhete com QR code para apresentar na porta.</li>
  </ul>
  <p class="note">
    Guarda a referência <span class="ref"><?= htmlspecialchars($ref) ?></span> para qualquer contacto connosco.<br><br>
    Dúvidas? <a class="link" href="mailto:<?= htmlspecialchars($info) ?>"><?= htmlspecialchars($info) ?></a><br>
    <a class="link" href="<?= htmlspecialchars($app_url) ?>/links"><?= htmlspecialchars($app_url) ?></a>
  </p>
</div>
</body>
</html>
<?php
    $html = ob_get_clean();

    return link_send_customer_html($email, $subject, $html);
}

function link_should_send_receipt_email(array $row): bool
{
    if (!empty($row['receipt_email_sent_at'])) {
        return false;
    }

    return !empty($row['step2_at']);
}

/**
 * Marca email de recepção enviado (evita duplicados).
 */
function link_mark_receipt_email_sent(string $registrationId): void
{
    $now     = link_sql_now();
    $backend = link_registration_backend();
    if ($backend === 'json') {
        require_once __DIR__ . '/link-json-store.php';
        link_json_patch_registration($registrationId, [
            'receipt_email_sent_at' => $now,
            'updated_at'            => $now,
        ]);

        return;
    }
    $pdo = link_api_db();
    link_registrations_ensure_columns($pdo);
    $pdo->prepare(
        'UPDATE link_registrations SET receipt_email_sent_at = ?, updated_at = ? WHERE id = ?'
    )->execute([$now, $now, $registrationId]);
}
