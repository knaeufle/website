<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$to   = isset($input['to'])   ? trim($input['to'])   : '';
$code = isset($input['code']) ? trim($input['code'])  : '';

if (!$to || !$code) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Fehlende Parameter']);
    exit;
}

if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Ungültige E-Mail-Adresse']);
    exit;
}

// Sicherheit: Code darf nur alphanumerisch sein
if (!preg_match('/^[A-Z0-9]{4,10}$/', $code)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Ungültiger Code']);
    exit;
}

$from    = 'noreply@freund-hase.com';
$subject = 'CMS Passwort zurücksetzen — freund-hase.com';
$body    = "Hallo,\r\n\r\n"
         . "du hast einen Passwort-Reset für das CMS auf freund-hase.com angefordert.\r\n\r\n"
         . "Dein Reset-Code:\r\n\r\n"
         . "    " . $code . "\r\n\r\n"
         . "Dieser Code ist 15 Minuten gültig.\r\n\r\n"
         . "Gib den Code auf der Login-Seite unter \"Passwort vergessen\" ein.\r\n\r\n"
         . "Falls du keinen Reset angefordert hast, ignoriere diese E-Mail.\r\n\r\n"
         . "— freund-hase.com";

$headers  = "From: " . $from . "\r\n";
$headers .= "Reply-To: " . $from . "\r\n";
$headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

$sent = mail($to, $subject, $body, $headers);

if ($sent) {
    echo json_encode(['ok' => true]);
} else {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'E-Mail konnte nicht gesendet werden']);
}
