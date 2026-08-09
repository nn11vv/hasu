<?php
// Dirección propia del dominio desde la que se envía el mail.
define('FROM_EMAIL', 'reservas@hasumasajes.com');
// Copia oculta para Cecilia — cambiar si su casilla de contacto es otra.
define('ADMIN_EMAIL', 'reservas@hasumasajes.com');

$allowed_origins = ['https://hasumasajes.com', 'http://localhost:8000'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed_origins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
}
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'error' => 'Method not allowed']));
}

$input     = json_decode(file_get_contents('php://input'), true) ?? [];
$email     = trim($input['email'] ?? '');
$code      = trim(strip_tags($input['code'] ?? ''));
$recipient = trim(strip_tags($input['recipient'] ?? ''));
$service   = trim(strip_tags($input['service'] ?? ''));
$sender    = trim(strip_tags($input['sender'] ?? ''));
$message   = trim(strip_tags($input['message'] ?? ''));
$expiry    = trim(strip_tags($input['expiry'] ?? ''));

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !$code || !$recipient || !$service) {
    http_response_code(400);
    exit(json_encode(['success' => false, 'error' => 'Datos inválidos']));
}

$e = fn($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$senderLine = $sender
    ? '<p style="margin:0 0 4px;font-size:15px;color:#5C3D5C;">De parte de: <strong>' . $e($sender) . '</strong></p>'
    : '';

$messageBlock = $message
    ? '<div style="margin:20px 0;padding:16px 20px;background:#F5E8EF;border-radius:10px;">
         <p style="margin:0;font-size:15px;color:#2E1A2E;line-height:1.6;font-style:italic;">“' . nl2br($e($message)) . '”</p>
       </div>'
    : '';

$html = '
<div style="font-family:Georgia,\'Times New Roman\',serif;background:#FDF6F9;padding:32px 16px;">
  <div style="max-width:520px;margin:0 auto;background:#FFFAFD;border-radius:16px;overflow:hidden;border:1px solid #D9AECB;">
    <div style="background:linear-gradient(135deg,#9B6B9B,#C97A9B);padding:28px 24px;text-align:center;">
      <p style="margin:0;font-size:22px;letter-spacing:0.08em;color:#FFFAFD;">蓮 HASU MASAJES</p>
      <p style="margin:6px 0 0;font-size:13px;letter-spacing:0.12em;color:#F5E8EF;text-transform:uppercase;">Gift Card</p>
    </div>
    <div style="padding:28px 24px;">
      <p style="margin:0 0 4px;font-size:15px;color:#5C3D5C;">Para: <strong style="color:#2E1A2E;">' . $e($recipient) . '</strong></p>
      <p style="margin:0 0 4px;font-size:15px;color:#5C3D5C;">Tratamiento regalado: <strong style="color:#2E1A2E;">' . $e($service) . '</strong></p>
      ' . $senderLine . '
      ' . $messageBlock . '
      <div style="margin:24px 0;padding:18px;text-align:center;background:#2E1A2E;border-radius:10px;">
        <p style="margin:0 0 6px;font-size:12px;letter-spacing:0.1em;color:#D9AECB;text-transform:uppercase;">Código de la Gift Card</p>
        <p style="margin:0;font-size:24px;letter-spacing:0.06em;color:#FFFAFD;font-weight:bold;">' . $e($code) . '</p>
      </div>
      <p style="margin:0;font-size:13px;color:#5C3D5C;">Válida hasta: <strong>' . $e($expiry) . '</strong></p>
      <p style="margin:20px 0 0;font-size:12px;color:#9B6B9B;line-height:1.6;">Este email es un respaldo de tu tarjeta descargada en el sitio. Presentá el código al coordinar tu turno por WhatsApp con Cecilia.</p>
    </div>
  </div>
</div>';

$subject = "=?UTF-8?B?" . base64_encode('🎁 Tu Gift Card de Hasu Masajes') . "?=";

$headers  = "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/html; charset=UTF-8\r\n";
$headers .= "From: Hasu Masajes <" . FROM_EMAIL . ">\r\n";
$headers .= "Bcc: " . ADMIN_EMAIL . "\r\n";

$sent = mail($email, $subject, $html, $headers);

if ($sent) {
    echo json_encode(['success' => true]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'No se pudo enviar el email']);
}
