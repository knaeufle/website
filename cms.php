<?php
/**
 * freund-hase CMS Backend
 * Auth + Datenspeicherung + Password-Reset
 */

session_start();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$DATA_DIR   = __DIR__ . '/data/';
$AUTH_FILE  = $DATA_DIR . 'auth.json';
$CMS_FILE   = $DATA_DIR . 'cms-data.json';
$CACHE_FILE = __DIR__ . '/cms-cache.js';
$RESET_EMAIL = 'info@freund-hase.com';

// Datenordner anlegen falls nicht vorhanden
if (!is_dir($DATA_DIR)) {
    mkdir($DATA_DIR, 0755, true);
    file_put_contents($DATA_DIR . '.htaccess', "Require all denied\n");
}

// Auth-Datei mit Standard-Passwort anlegen falls nicht vorhanden
if (!file_exists($AUTH_FILE)) {
    file_put_contents($AUTH_FILE, json_encode([
        'hash'          => password_hash('laminal1!', PASSWORD_DEFAULT),
        'reset_token'   => null,
        'reset_expires' => null
    ]));
}

function getAuth() {
    global $AUTH_FILE;
    $d = json_decode(file_get_contents($AUTH_FILE), true);
    return $d ?: [];
}

function saveAuth(array $d) {
    global $AUTH_FILE;
    file_put_contents($AUTH_FILE, json_encode($d, JSON_PRETTY_PRINT));
}

function isLoggedIn(): bool {
    return !empty($_SESSION['cms_auth']);
}

function ok(array $data = []) {
    echo json_encode(array_merge(['ok' => true], $data));
    exit;
}

function fail(string $msg, int $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

function requireAuth() {
    if (!isLoggedIn()) fail('Nicht eingeloggt', 401);
}

function writeCmsCache(array $cms) {
    global $CACHE_FILE;
    $json = json_encode($cms, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $js   = "/* freund-hase CMS Cache — auto-generated */\nwindow.__CMS__=" . $json . ";\n";
    file_put_contents($CACHE_FILE, $js);
}

// Input lesen
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// POST-Body als JSON falls kein Form-Submit
$body = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    if ($raw) $body = json_decode($raw, true) ?: [];
    else       $body = $_POST;
}

// ── ROUTING ──────────────────────────────────────────────

switch ($action) {

    // Sitzungsstatus prüfen
    case 'check':
        ok(['loggedIn' => isLoggedIn()]);

    // Login
    case 'login':
        $pw   = $body['password'] ?? $_POST['password'] ?? '';
        $auth = getAuth();
        if (!$pw || !password_verify($pw, $auth['hash'] ?? ''))
            fail('Falsches Passwort', 401);
        $_SESSION['cms_auth'] = true;
        ok();

    // Logout
    case 'logout':
        session_destroy();
        ok();

    // Passwort ändern (erfordert Login)
    case 'change-pw':
        requireAuth();
        $current = $body['current'] ?? '';
        $newpw   = $body['newpw']   ?? '';
        $auth    = getAuth();
        if (!password_verify($current, $auth['hash'] ?? ''))
            fail('Aktuelles Passwort falsch', 401);
        if (strlen($newpw) < 6)
            fail('Neues Passwort: mindestens 6 Zeichen');
        $auth['hash'] = password_hash($newpw, PASSWORD_DEFAULT);
        saveAuth($auth);
        ok();

    // Reset-Mail anfordern
    case 'reset-req':
        $token   = bin2hex(random_bytes(16));
        $expires = time() + 900; // 15 Minuten
        $auth    = getAuth();
        $auth['reset_token']   = $token;
        $auth['reset_expires'] = $expires;
        saveAuth($auth);

        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host  = $_SERVER['HTTP_HOST'] ?? 'freund-hase.com';
        $link  = $proto . '://' . $host . '/admin.html?reset=' . $token;

        $subject = 'CMS Passwort zurücksetzen — freund-hase.com';
        $body_txt = "Hallo,\r\n\r\n"
                  . "du hast einen Passwort-Reset angefordert.\r\n\r\n"
                  . "Klicke auf den Link um dein Passwort zurückzusetzen:\r\n"
                  . $link . "\r\n\r\n"
                  . "Der Link ist 15 Minuten gültig.\r\n\r\n"
                  . "Falls du keinen Reset angefordert hast, ignoriere diese E-Mail.\r\n\r\n"
                  . "— freund-hase.com CMS";
        $headers  = "From: noreply@freund-hase.com\r\n"
                  . "Content-Type: text/plain; charset=UTF-8\r\n";

        @mail($RESET_EMAIL, $subject, $body_txt, $headers);
        ok();

    // Reset durchführen
    case 'reset-do':
        $token = $body['token'] ?? '';
        $newpw = $body['newpw'] ?? '';
        $auth  = getAuth();
        if (!$token || $token !== ($auth['reset_token'] ?? '') || time() > ($auth['reset_expires'] ?? 0))
            fail('Link ungültig oder abgelaufen');
        if (strlen($newpw) < 6)
            fail('Mindestens 6 Zeichen');
        $auth['hash']          = password_hash($newpw, PASSWORD_DEFAULT);
        $auth['reset_token']   = null;
        $auth['reset_expires'] = null;
        saveAuth($auth);
        $_SESSION['cms_auth'] = true;
        ok();

    // CMS-Daten laden (öffentlich)
    case 'load':
        $cms = file_exists($CMS_FILE) ? (json_decode(file_get_contents($CMS_FILE), true) ?: []) : [];
        ok(['data' => $cms]);

    // CMS-Daten speichern (erfordert Login)
    case 'save':
        requireAuth();
        $incoming = $body['data'] ?? null;
        if (!is_array($incoming)) fail('Ungültige Daten');

        // Bestehende Daten laden und mergen
        $cms = file_exists($CMS_FILE) ? (json_decode(file_get_contents($CMS_FILE), true) ?: []) : [];
        foreach ($incoming as $k => $v) {
            // Nur erlaubte Keys
            if (preg_match('/^freundhase_cms_[a-z0-9_]+$/', $k)) {
                $cms[$k] = $v;
            }
        }
        file_put_contents($CMS_FILE, json_encode($cms, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        writeCmsCache($cms);
        ok();

    default:
        fail('Unbekannte Aktion');
}
