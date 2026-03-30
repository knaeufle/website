<?php
/**
 * freund-hase CMS Backend
 * Auth + Datenspeicherung + Password-Reset
 */

session_start();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ── Brute-Force-Schutz (IP-basiert, nur für Login/Reset) ────
function checkRateLimit(string $ip): void {
    $file  = sys_get_temp_dir() . '/fh_rl_' . md5($ip) . '.json';
    $now   = time();
    $limit = 10;   // max. Versuche
    $window = 300; // innerhalb 5 Minuten
    $block  = 900; // Sperre 15 Minuten

    $data = [];
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true) ?: [];
    }

    // Alte Einträge außerhalb des Fensters entfernen
    $data['attempts'] = array_filter($data['attempts'] ?? [], fn($t) => $t > $now - $window);

    if (!empty($data['blocked_until']) && $now < $data['blocked_until']) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Zu viele Versuche. Bitte warte einige Minuten.']);
        exit;
    }

    $data['attempts'][] = $now;

    if (count($data['attempts']) >= $limit) {
        $data['blocked_until'] = $now + $block;
    }

    file_put_contents($file, json_encode($data), LOCK_EX);
}

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
    $js = "/* freund-hase CMS Cache — auto-generated, do not edit */\n"
        . "(function(){var d=" . $json . ";"
        . "Object.keys(d).forEach(function(k){try{localStorage.setItem(k,JSON.stringify(d[k]));}catch(e){}});"
        . "window.__CMS__=d;"
        . "document.addEventListener('DOMContentLoaded',function(){"
        . "try{var m=d['freundhase_cms_image_meta']||{};"
        . "Object.keys(m).forEach(function(src){var a=(m[src]||{}).alt;if(!a)return;"
        . "document.querySelectorAll('img').forEach(function(img){"
        . "var s=img.getAttribute('src')||'';"
        . "if((s===src||s.indexOf(src)!==-1)&&!img.getAttribute('alt'))img.setAttribute('alt',a);"
        . "});});}"
        . "catch(e){}});"
        . "})();\n";
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
        checkRateLimit($_SERVER['REMOTE_ADDR'] ?? 'unknown');
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
        checkRateLimit($_SERVER['REMOTE_ADDR'] ?? 'unknown');
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
            if (preg_match('/^freundhase_cms(_[a-z0-9_]+)?$/', $k)) {
                $cms[$k] = $v;
            }
        }
        file_put_contents($CMS_FILE, json_encode($cms, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        writeCmsCache($cms);
        ok();

    // Bild hochladen (erfordert Login)
    case 'upload':
        requireAuth();
        $folder  = preg_replace('/[^a-z0-9_\-]/', '', strtolower($_GET['folder'] ?? 'index'));
        $allowed = ['db','tdacademy','uiq','abinsb','lag','gm','synverz','spandau','index'];
        // Dynamische Seiten-Ordner ergänzen
        $_cmsData = file_exists($CMS_FILE) ? (json_decode(file_get_contents($CMS_FILE), true) ?: []) : [];
        foreach ($_cmsData['freundhase_cms_pages_registry'] ?? [] as $_dp) {
            if (!empty($_dp['slug'])) $allowed[] = $_dp['slug'];
        }
        if (!in_array($folder, $allowed)) fail('Ungültiger Ordner: ' . $folder);

        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $c = $_FILES['file']['error'] ?? 99;
            $m = [1=>'Datei zu groß (PHP)',2=>'Zu groß (Form)',3=>'Unvollständig',4=>'Keine Datei'];
            fail('Upload-Fehler: ' . ($m[$c] ?? 'Code '.$c));
        }
        $file  = $_FILES['file'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);
        $allowedMimes = ['image/jpeg','image/jpg','image/png','image/webp','image/gif','image/svg+xml'];
        if (!in_array($mime, $allowedMimes)) fail('Dateityp nicht erlaubt: '.$mime);
        if ($file['size'] > 10 * 1024 * 1024) fail('Datei zu groß (max. 10 MB)');

        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $name = preg_replace('/[^a-z0-9_\-]/', '_', strtolower(pathinfo($file['name'], PATHINFO_FILENAME)));
        $name = substr(trim($name, '_'), 0, 80) ?: 'img_'.time();
        $fn   = $name . '.' . $ext;
        $dir  = __DIR__ . '/images/' . $folder . '/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $path = $dir . $fn;
        $i = 1;
        while (file_exists($path)) { $fn = $name.'_'.$i.'.'.$ext; $path = $dir.$fn; $i++; }
        if (!move_uploaded_file($file['tmp_name'], $path)) fail('Datei konnte nicht gespeichert werden');
        ok(['path' => 'images/' . $folder . '/' . $fn]);

    // Dynamische Seiten: auflisten
    case 'list-pages':
        requireAuth();
        $cms = file_exists($CMS_FILE) ? (json_decode(file_get_contents($CMS_FILE), true) ?: []) : [];
        ok(['pages' => $cms['freundhase_cms_pages_registry'] ?? []]);

    // Dynamische Seiten: anlegen
    case 'create-page':
        requireAuth();
        $slug  = preg_replace('/[^a-z0-9\-]/', '-', strtolower(trim($body['slug'] ?? '')));
        $slug  = preg_replace('/-+/', '-', trim($slug, '-'));
        $title = trim($body['title'] ?? '');
        $cat   = trim($body['cat']   ?? '');
        $desc  = trim($body['desc']  ?? '');
        $tags  = trim($body['tags']  ?? '');

        if (strlen($slug) < 2) fail('Slug ungültig (min. 2 Zeichen, nur a–z 0–9 Bindestrich)');
        if (!$title) fail('Titel erforderlich');

        $reserved = ['index','admin','cms','impressum','datenschutz','data','images','fonts','upload','cookie-consent'];
        if (in_array($slug, $reserved)) fail('Dieser Slug ist reserviert');

        $pageFile = __DIR__ . '/' . $slug . '.html';
        if (file_exists($pageFile)) fail('Seite existiert bereits: ' . $slug . '.html');

        $imgDir = __DIR__ . '/images/' . $slug . '/';
        if (!is_dir($imgDir)) mkdir($imgDir, 0755, true);

        $heroTagsHtml = '';
        foreach (array_filter(array_map('trim', explode(',', $tags))) as $t) {
            $heroTagsHtml .= '<span class="proj-hero-tag">' . htmlspecialchars($t, ENT_QUOTES, 'UTF-8') . '</span>';
        }

        $cmsKey = 'freundhase_cms_page_' . preg_replace('/[^a-z0-9_]/', '_', $slug);
        $titleH = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $catH   = htmlspecialchars($cat,   ENT_QUOTES, 'UTF-8');
        $descH  = htmlspecialchars($desc ?: $title, ENT_QUOTES, 'UTF-8');

        $tpl = <<<'TPLEND'
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="images/favicon-magenta.svg">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{TITLE}} — Daniel Wieczorek · freund-hase.com</title>
    <meta name="description" content="{{DESC}}">
    <link rel="stylesheet" href="fonts/fonts.css">
    <link rel="stylesheet" href="styles.css">
    <style>
        .proj-hero{position:relative;height:90vh;min-height:520px;display:flex;align-items:flex-end;overflow:hidden;padding-top:var(--nav-h)}
        .proj-hero-bg{position:absolute;inset:0;background-size:cover;background-position:center;transform:scale(1.04);transition:transform 8s ease-out}
        .proj-hero-bg.loaded{transform:scale(1)}
        .proj-hero-overlay{position:absolute;inset:0;background:linear-gradient(to top,rgba(10,10,10,.92) 0%,rgba(10,10,10,.45) 50%,rgba(10,10,10,.15) 100%)}
        .proj-hero-content{position:relative;z-index:2;width:100%;max-width:var(--wrap);margin:0 auto;padding:0 clamp(20px,5vw,60px) clamp(48px,8vw,80px)}
        .proj-breadcrumb{display:flex;align-items:center;gap:10px;font-family:var(--font-ui);font-size:.72rem;font-weight:600;letter-spacing:.15em;text-transform:uppercase;color:var(--text-muted);margin-bottom:20px}
        .proj-breadcrumb a{color:var(--text-muted);transition:color .2s}.proj-breadcrumb a:hover{color:var(--accent)}
        .proj-breadcrumb svg{width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:1.5;flex-shrink:0}
        .proj-hero-cat{display:inline-block;font-family:var(--font-ui);font-size:.7rem;font-weight:600;letter-spacing:.2em;text-transform:uppercase;color:var(--accent);margin-bottom:16px}
        .proj-hero-title{font-family:var(--font-display);font-size:clamp(3rem,8vw,7rem);font-weight:900;line-height:.92;color:var(--text-light);margin-bottom:24px}
        .proj-hero-tags{display:flex;flex-wrap:wrap;gap:10px}
        .proj-hero-tag{font-family:var(--font-ui);font-size:.7rem;font-weight:500;letter-spacing:.12em;text-transform:uppercase;color:var(--text-muted);border:1px solid rgba(255,255,255,.12);padding:6px 14px;border-radius:2px}
        .intro-section{background:var(--bg);padding:clamp(72px,10vw,120px) clamp(20px,5vw,60px)}
        .intro-grid{display:grid;grid-template-columns:1fr 1fr;gap:clamp(40px,8vw,120px);max-width:var(--wrap);margin:0 auto;align-items:start}
        @media(max-width:768px){.intro-grid{grid-template-columns:1fr}}
        .intro-label{font-family:var(--font-ui);font-size:.7rem;font-weight:600;letter-spacing:.2em;text-transform:uppercase;color:var(--accent);margin-bottom:20px;display:block}
        .intro-headline{font-family:var(--font-display);font-size:clamp(1.9rem,3.5vw,3rem);font-weight:700;line-height:1.15;color:var(--text-light);margin-bottom:28px}
        .intro-headline em{font-style:italic;color:var(--accent)}
        .intro-text{font-size:clamp(1rem,1.2vw,1.1rem);line-height:1.75;color:rgba(250,250,248,.75);margin-bottom:20px}
        .services-box{background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.07);padding:36px;border-radius:2px}
        .services-title{font-family:var(--font-ui);font-size:.68rem;font-weight:700;letter-spacing:.2em;text-transform:uppercase;color:var(--text-muted);margin-bottom:24px}
        .services-list{display:flex;flex-direction:column;gap:14px}
        .services-item{display:flex;align-items:center;gap:14px;font-family:var(--font-ui);font-size:.95rem;font-weight:500;color:var(--text-light)}
        .services-item::before{content:'';display:block;width:20px;height:1px;background:var(--accent);flex-shrink:0}
        .deliverables-title{font-family:var(--font-ui);font-size:.68rem;font-weight:700;letter-spacing:.2em;text-transform:uppercase;color:var(--text-muted);margin-top:32px;margin-bottom:16px}
        .deliverables-tags{display:flex;flex-wrap:wrap;gap:8px}
        .deliverable-tag{font-family:var(--font-ui);font-size:.7rem;font-weight:500;letter-spacing:.08em;text-transform:uppercase;color:var(--text-muted);border:1px solid rgba(255,255,255,.1);padding:5px 12px;border-radius:1px;transition:border-color .2s,color .2s}
        .deliverable-tag:hover{border-color:var(--accent);color:var(--accent)}
        .gallery-section{background:var(--bg-alt);padding:clamp(48px,8vw,100px) 0}
        .gallery-wrap{max-width:var(--wrap);margin:0 auto;padding:0 clamp(20px,5vw,60px)}
        .gallery-grid{display:grid;grid-template-columns:1fr 1fr;gap:4px}
        @media(max-width:640px){.gallery-grid{grid-template-columns:1fr}}
        .gallery-item{position:relative;overflow:hidden}.gallery-item.full{grid-column:1/-1}
        .gallery-item img{width:100%;height:100%;object-fit:cover;display:block;transition:transform 3s ease-in-out}
        .gallery-item:hover img{transform:scale(1.04)}
        .gallery-item.full img{aspect-ratio:16/7}.gallery-item:not(.full) img{aspect-ratio:4/3}
        .challenge-section{background:var(--bg);padding:clamp(72px,10vw,120px) clamp(20px,5vw,60px)}
        .challenge-grid{display:grid;grid-template-columns:1fr 1fr;gap:clamp(32px,6vw,80px);max-width:var(--wrap);margin:0 auto}
        @media(max-width:768px){.challenge-grid{grid-template-columns:1fr}}
        .challenge-block{padding:40px;border-top:1px solid rgba(255,255,255,.08)}
        .challenge-num{font-family:var(--font-display);font-size:clamp(3rem,5vw,5rem);font-weight:900;color:var(--accent-dim);line-height:1;margin-bottom:20px}
        .challenge-label{font-family:var(--font-ui);font-size:.7rem;font-weight:700;letter-spacing:.2em;text-transform:uppercase;color:var(--accent);margin-bottom:16px;display:block}
        .challenge-title{font-family:var(--font-display);font-size:clamp(1.4rem,2.5vw,2rem);font-weight:700;color:var(--text-light);margin-bottom:16px;line-height:1.2}
        .challenge-text{font-size:.98rem;line-height:1.75;color:rgba(250,250,248,.65)}
        .stats-section{background:var(--surface);padding:clamp(64px,9vw,110px) clamp(20px,5vw,60px)}
        .stats-wrap{max-width:var(--wrap);margin:0 auto}
        .stats-eyebrow{font-family:var(--font-ui);font-size:.7rem;font-weight:700;letter-spacing:.2em;text-transform:uppercase;color:var(--accent);margin-bottom:56px;display:block}
        .stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:clamp(24px,4vw,48px);border-top:1px solid rgba(26,25,24,.1);padding-top:48px}
        @media(max-width:768px){.stats-grid{grid-template-columns:repeat(2,1fr)}}
        .stat-item{padding-right:24px}
        .stat-num{font-family:var(--font-display);font-size:clamp(2.8rem,5vw,4.5rem);font-weight:900;color:var(--text-dark);line-height:1;margin-bottom:10px}
        .stat-num em{font-style:normal;color:var(--accent)}
        .stat-label{font-family:var(--font-ui);font-size:.8rem;font-weight:500;color:var(--text-dark-mid);line-height:1.4}
        .fullimg-section{position:relative;overflow:hidden;height:clamp(300px,50vw,640px)}
        .fullimg-section img{width:100%;height:100%;object-fit:cover;transition:transform 3s ease-in-out}
        .fullimg-section:hover img{transform:scale(1.04)}
        .next-section{background:var(--bg);padding:clamp(72px,10vw,120px) clamp(20px,5vw,60px)}
        .next-wrap{max-width:var(--wrap);margin:0 auto}
        .next-label{font-family:var(--font-ui);font-size:.7rem;font-weight:700;letter-spacing:.2em;text-transform:uppercase;color:var(--text-muted);margin-bottom:40px;display:block}
        .next-link{display:flex;align-items:center;justify-content:space-between;padding:32px 0;border-top:1px solid rgba(255,255,255,.07);transition:border-color .3s}
        .next-link:last-child{border-bottom:1px solid rgba(255,255,255,.07)}.next-link:hover{border-color:var(--accent)}
        .next-link-inner{display:flex;flex-direction:column;gap:6px}
        .next-link-cat{font-family:var(--font-ui);font-size:.68rem;font-weight:600;letter-spacing:.18em;text-transform:uppercase;color:var(--accent)}
        .next-link-name{font-family:var(--font-display);font-size:clamp(1.4rem,2.5vw,2.2rem);font-weight:700;color:var(--text-light);transition:color .2s}
        .next-link:hover .next-link-name{color:var(--accent)}
        .next-arrow{width:48px;height:48px;border-radius:50%;border:1px solid rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;transition:background .3s,border-color .3s,transform .3s var(--ease-out);flex-shrink:0}
        .next-link:hover .next-arrow{background:var(--accent);border-color:var(--accent);transform:translate(4px,-4px)}
        .next-arrow svg{width:18px;height:18px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
    </style>
    <script src="cms-cache.js" onerror="void 0"></script>
</head>
<body>
<div class="cursor" id="cursor"></div>
<div class="cursor-ring" id="cursorRing"></div>

<nav class="nav solid" role="navigation" aria-label="Hauptnavigation">
    <div class="nav-inner">
        <a href="index.html" class="nav-logo" aria-label="Zurück zur Startseite">freund<em>·</em>hase</a>
        <ul class="nav-links" role="list">
            <li><a href="index.html#projekte">Projekte</a></li>
            <li><a href="index.html#ueber-mich">Über mich</a></li>
            <li><a href="index.html#kunden">Kunden</a></li>
            <li><a href="index.html#kontakt">Kontakt</a></li>
        </ul>
        <button class="nav-ham" id="navHam" aria-label="Menü öffnen" aria-expanded="false">
            <span></span><span></span><span></span>
        </button>
    </div>
</nav>

<nav class="mob-nav" id="mobNav" aria-label="Mobile Navigation">
    <a href="index.html#projekte" class="mob-nav-link">Projekte</a>
    <a href="index.html#ueber-mich" class="mob-nav-link">Über mich</a>
    <a href="index.html#kunden" class="mob-nav-link">Kunden</a>
    <a href="index.html#kontakt" class="mob-nav-link">Kontakt</a>
</nav>

<section class="proj-hero" aria-label="{{TITLE}}">
    <div class="proj-hero-bg" id="projHeroBg"></div>
    <div class="proj-hero-overlay"></div>
    <div class="proj-hero-content">
        <div class="proj-breadcrumb rv">
            <a href="index.html">Startseite</a>
            <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
            <a href="index.html#projekte">Projekte</a>
            <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
            <span>{{TITLE}}</span>
        </div>
        <span class="proj-hero-cat rv d1">{{CAT}}</span>
        <h1 class="proj-hero-title rv d2">{{TITLE}}</h1>
        <div class="proj-hero-tags rv d3">{{HERO_TAGS}}</div>
    </div>
</section>

<section class="intro-section" aria-label="Projektbeschreibung">
    <div class="intro-grid">
        <div>
            <span class="intro-label rv">Das Projekt</span>
            <h2 class="intro-headline rv d1">{{TITLE}}</h2>
            <p class="intro-text rv d2">{{DESC}}</p>
            <p class="intro-text rv d3"></p>
        </div>
        <div class="rv d2">
            <div class="services-box">
                <p class="services-title">Leistungsumfang</p>
                <ul class="services-list"></ul>
                <p class="deliverables-title">Deliverables</p>
                <div class="deliverables-tags"></div>
                <p class="deliverables-title">Tools</p>
                <div class="deliverables-tags"></div>
            </div>
        </div>
    </div>
</section>

<section class="gallery-section" aria-label="Projektbilder">
    <div class="gallery-wrap">
        <div class="gallery-grid">
            <div class="gallery-item full rv">
                <img src="" alt="{{TITLE}}" loading="lazy">
            </div>
            <div class="gallery-item rv d1">
                <img src="" alt="{{TITLE}}" loading="lazy">
            </div>
            <div class="gallery-item rv d2">
                <img src="" alt="{{TITLE}}" loading="lazy">
            </div>
        </div>
    </div>
</section>

<section class="challenge-section" aria-label="Herausforderung und Vorgehen">
    <div class="challenge-grid">
        <div class="challenge-block rv">
            <div class="challenge-num" aria-hidden="true">01</div>
            <span class="challenge-label">Herausforderung</span>
            <h3 class="challenge-title">Titel</h3>
            <p class="challenge-text">Text</p>
        </div>
        <div class="challenge-block rv d2">
            <div class="challenge-num" aria-hidden="true">02</div>
            <span class="challenge-label">Ansatz</span>
            <h3 class="challenge-title">Titel</h3>
            <p class="challenge-text">Text</p>
        </div>
        <div class="challenge-block rv d1">
            <div class="challenge-num" aria-hidden="true">03</div>
            <span class="challenge-label">Ergebnis</span>
            <h3 class="challenge-title">Titel</h3>
            <p class="challenge-text">Text</p>
        </div>
        <div class="challenge-block rv d3">
            <div class="challenge-num" aria-hidden="true">04</div>
            <span class="challenge-label">Fazit</span>
            <h3 class="challenge-title">Titel</h3>
            <p class="challenge-text">Text</p>
        </div>
    </div>
</section>

<section class="stats-section" aria-label="Projektergebnisse">
    <div class="stats-wrap">
        <span class="stats-eyebrow rv">Projektumfang</span>
        <div class="stats-grid">
            <div class="stat-item rv"><div class="stat-num"><em>—</em></div><div class="stat-label">Stat 1</div></div>
            <div class="stat-item rv d1"><div class="stat-num"><em>—</em></div><div class="stat-label">Stat 2</div></div>
            <div class="stat-item rv d2"><div class="stat-num"><em>—</em></div><div class="stat-label">Stat 3</div></div>
            <div class="stat-item rv d3"><div class="stat-num"><em>—</em></div><div class="stat-label">Stat 4</div></div>
        </div>
    </div>
</section>

<div class="fullimg-section" role="img" aria-label="{{TITLE}}">
    <img src="" alt="{{TITLE}}" loading="lazy" onerror="this.closest('.fullimg-section').style.display='none'">
</div>

<section class="next-section" aria-label="Weitere Projekte">
    <div class="next-wrap">
        <span class="next-label rv">Weitere Arbeiten</span>
        <a href="index.html#projekte" class="next-link rv d1">
            <div class="next-link-inner">
                <span class="next-link-cat">Zurück zur Übersicht</span>
                <span class="next-link-name">Alle Projekte</span>
            </div>
            <div class="next-arrow"><svg viewBox="0 0 24 24"><path d="M7 17L17 7M17 7H7M17 7v10"/></svg></div>
        </a>
    </div>
</section>

<section class="contact-section" id="kontakt" aria-label="Kontakt">
    <span class="ct-label rv">Kontakt</span>
    <h2 class="ct-headline rv d1">Nächstes Projekt<br>gemeinsam <em>gestalten?</em></h2>
    <a href="mailto:hallo@freund-hase.com" class="ct-email rv d2" aria-label="E-Mail senden">
        hallo@freund-hase.com
        <svg viewBox="0 0 24 24"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
    </a>
    <nav class="ct-social rv d3" aria-label="Social Media">
        <a href="https://www.instagram.com/freund.hase/" target="_blank" rel="noopener noreferrer">Instagram</a>
        <a href="https://www.linkedin.com/in/daniel-wieczorek-14030173" target="_blank" rel="noopener noreferrer">LinkedIn</a>
        <a href="https://www.xing.com/profile/Daniel_Wieczorek2" target="_blank" rel="noopener noreferrer">XING</a>
    </nav>
</section>

<footer class="footer">
    <div class="footer-inner">
        <span class="footer-copy">© 2025 Daniel Wieczorek · freund-hase.com</span>
        <div class="footer-right">
            <a href="impressum.html" class="footer-link">Impressum</a>
            <a href="datenschutz.html" class="footer-link">Datenschutz</a>
            <a href="#" class="footer-top" aria-label="Zurück nach oben">
                <svg viewBox="0 0 24 24"><path d="M12 19V5M5 12l7-7 7 7"/></svg>
                Nach oben
            </a>
        </div>
    </div>
</footer>

<script>
    const revEls = document.querySelectorAll('.rv');
    if ('IntersectionObserver' in window) {
        const io = new IntersectionObserver((entries) => {
            entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('visible'); io.unobserve(e.target); } });
        }, { threshold: 0.12 });
        revEls.forEach(el => io.observe(el));
    } else { revEls.forEach(el => el.classList.add('visible')); }

    const cursor = document.getElementById('cursor');
    const ring   = document.getElementById('cursorRing');
    if (cursor && ring) {
        document.addEventListener('mousemove', e => {
            cursor.style.left = e.clientX + 'px'; cursor.style.top = e.clientY + 'px';
            ring.style.left   = e.clientX + 'px'; ring.style.top   = e.clientY + 'px';
        });
        document.querySelectorAll('a, button').forEach(el => {
            el.addEventListener('mouseenter', () => { cursor.classList.add('hover'); ring.classList.add('hover'); });
            el.addEventListener('mouseleave', () => { cursor.classList.remove('hover'); ring.classList.remove('hover'); });
        });
    }

    const ham = document.getElementById('navHam');
    const mobNav = document.getElementById('mobNav');
    ham.addEventListener('click', () => {
        const open = mobNav.classList.toggle('open');
        ham.classList.toggle('open', open);
        ham.setAttribute('aria-expanded', open);
    });
    mobNav.querySelectorAll('a').forEach(a => a.addEventListener('click', () => {
        mobNav.classList.remove('open'); ham.classList.remove('open'); ham.setAttribute('aria-expanded','false');
    }));

    window.addEventListener('load', () => {
        setTimeout(() => document.getElementById('projHeroBg').classList.add('loaded'), 100);
    });

    /* CMS Content Loader */
    (function () {
        let cms;
        try { cms = JSON.parse(localStorage.getItem('{{CMS_KEY}}')); } catch(e) {}
        if (!cms) return;
        const h = cms.hero;
        if (h) {
            const bg = document.getElementById('projHeroBg');
            if (bg && h.image) bg.style.backgroundImage = 'url(' + h.image + ')';
            const cat = document.querySelector('.proj-hero-cat');
            if (cat && h.cat) cat.textContent = h.cat;
            const title = document.querySelector('.proj-hero-title');
            if (title && h.title) title.innerHTML = h.title.replace(/\n/g, '<br>');
        }
        const intro = cms.intro;
        if (intro) {
            const headline = document.querySelector('.intro-headline');
            if (headline && intro.headline) headline.innerHTML = intro.headline;
            const texts = document.querySelectorAll('.intro-text');
            if (texts[0] && intro.text1) texts[0].textContent = intro.text1;
            if (texts[1] && intro.text2) texts[1].textContent = intro.text2;
            if (intro.services) {
                const list = document.querySelector('.services-list');
                if (list) list.innerHTML = intro.services.split('\n').filter(s => s.trim()).map(s => '<li class="services-item">' + s.trim() + '</li>').join('');
            }
        }
        const g = cms.gallery;
        if (g) {
            const imgs = document.querySelectorAll('.gallery-item img');
            if (imgs[0] && g.img1) imgs[0].src = g.img1;
            if (imgs[1] && g.img2) imgs[1].src = g.img2;
            if (imgs[2] && g.img3) imgs[2].src = g.img3;
            const fullImg = document.querySelector('.fullimg-section img');
            if (fullImg && g.imgFull) fullImg.src = g.imgFull;
            else if (g.hasOwnProperty('imgFull') && !g.imgFull) { const el = document.querySelector('.fullimg-section'); if (el) el.style.display = 'none'; }
        }
        if (Array.isArray(cms.challenges)) {
            const blocks = document.querySelectorAll('.challenge-block');
            cms.challenges.forEach((c, i) => {
                if (!blocks[i]) return;
                const t = blocks[i].querySelector('.challenge-title');
                const p = blocks[i].querySelector('.challenge-text');
                if (t && c.title) t.textContent = c.title;
                if (p && c.text)  p.textContent = c.text;
            });
        }
        if (Array.isArray(cms.stats)) {
            const items = document.querySelectorAll('.stat-item');
            cms.stats.forEach((s, i) => {
                if (!items[i]) return;
                const numEl = items[i].querySelector('.stat-num');
                const lblEl = items[i].querySelector('.stat-label');
                if (numEl && s.num)   numEl.innerHTML = '<em>' + s.num + '</em>';
                if (lblEl && s.label) lblEl.innerHTML = s.label.replace(/\n/g, '<br>');
            });
        }
        function setChips(el, csv, cls) { if (!el || !csv) return; const t = csv.split(',').map(s=>s.trim()).filter(Boolean); if (t.length) el.innerHTML = t.map(s=>'<span class="' + cls + '">' + s + '</span>').join(''); }
        if (cms.heroTags) setChips(document.querySelector('.proj-hero-tags'), cms.heroTags, 'proj-hero-tag');
        const tagGroups = document.querySelectorAll('.deliverables-tags');
        if (cms.deliverables) setChips(tagGroups[0], cms.deliverables, 'deliverable-tag');
        if (cms.tools) setChips(tagGroups[1], cms.tools, 'deliverable-tag');
    })();
</script>
</body>
</html>
TPLEND;

        $html = str_replace(
            ['{{TITLE}}', '{{CAT}}', '{{DESC}}', '{{HERO_TAGS}}', '{{CMS_KEY}}'],
            [$titleH, $catH, $descH, $heroTagsHtml, $cmsKey],
            $tpl
        );

        file_put_contents($pageFile, $html);

        // Registry + CMS-Daten aktualisieren
        $cms = file_exists($CMS_FILE) ? (json_decode(file_get_contents($CMS_FILE), true) ?: []) : [];
        $registry = $cms['freundhase_cms_pages_registry'] ?? [];
        $nextNum  = count($registry) + 9; // start after static pages (8)
        $registry[] = [
            'slug'    => $slug,
            'title'   => $title,
            'cat'     => $cat,
            'desc'    => $desc,
            'num'     => str_pad($nextNum, 2, '0', STR_PAD_LEFT),
            'image'   => '',
            'created' => date('Y-m-d')
        ];
        $cms['freundhase_cms_pages_registry'] = $registry;

        file_put_contents($CMS_FILE, json_encode($cms, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        writeCmsCache($cms);
        ok(['slug' => $slug, 'file' => $slug . '.html', 'cmsKey' => $cmsKey]);

    // Dynamische Seiten: löschen
    case 'delete-page':
        requireAuth();
        $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($body['slug'] ?? '')));
        if (!$slug) fail('Slug fehlt');

        $reserved = ['index','admin','cms','impressum','datenschutz'];
        if (in_array($slug, $reserved)) fail('Reservierter Slug darf nicht gelöscht werden');

        $pageFile = __DIR__ . '/' . $slug . '.html';
        if (file_exists($pageFile)) unlink($pageFile);

        $cms = file_exists($CMS_FILE) ? (json_decode(file_get_contents($CMS_FILE), true) ?: []) : [];
        $cms['freundhase_cms_pages_registry'] = array_values(array_filter(
            $cms['freundhase_cms_pages_registry'] ?? [],
            fn($p) => ($p['slug'] ?? '') !== $slug
        ));
        // CMS-Daten der Seite löschen
        $pageKey = 'freundhase_cms_page_' . preg_replace('/[^a-z0-9_]/', '_', $slug);
        unset($cms[$pageKey]);

        file_put_contents($CMS_FILE, json_encode($cms, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        writeCmsCache($cms);
        ok(['deleted' => $slug]);

    // Dynamische Seiten: Registry-Eintrag aktualisieren (Bild, Num)
    case 'update-page-meta':
        requireAuth();
        $slug  = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($body['slug'] ?? '')));
        $image = trim($body['image'] ?? '');
        $num   = trim($body['num']   ?? '');
        if (!$slug) fail('Slug fehlt');

        $cms = file_exists($CMS_FILE) ? (json_decode(file_get_contents($CMS_FILE), true) ?: []) : [];
        $found = false;
        foreach ($cms['freundhase_cms_pages_registry'] ?? [] as &$p) {
            if (($p['slug'] ?? '') === $slug) {
                if ($image !== '') $p['image'] = $image;
                if ($num   !== '') $p['num']   = $num;
                $found = true;
                break;
            }
        }
        unset($p);
        if (!$found) fail('Seite nicht gefunden');

        file_put_contents($CMS_FILE, json_encode($cms, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        writeCmsCache($cms);
        ok();

    // Alle Bilder auflisten (mit Verwendungsnachweis)
    case 'list-images':
        requireAuth();
        $imgDir = __DIR__ . '/images/';
        $images = [];
        if (is_dir($imgDir)) {
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($imgDir, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iter as $file) {
                if (!$file->isFile()) continue;
                $ext = strtolower($file->getExtension());
                if (!in_array($ext, ['jpg','jpeg','png','webp','gif','svg'])) continue;
                $rel = str_replace('\\', '/', substr($file->getPathname(), strlen(__DIR__) + 1));
                $images[] = [
                    'path'   => $rel,
                    'folder' => basename(dirname($rel)),
                    'name'   => $file->getFilename(),
                    'size'   => $file->getSize(),
                    'usedIn' => []
                ];
            }
        }
        usort($images, fn($a, $b) => $a['folder'] <=> $b['folder'] ?: $a['name'] <=> $b['name']);

        // Verwendungsnachweis: welche CMS-Keys referenzieren dieses Bild?
        $cmsForUsage = file_exists($CMS_FILE) ? (json_decode(file_get_contents($CMS_FILE), true) ?: []) : [];
        $keyNames = [
            'freundhase_cms'         => 'Startseite',
            'freundhase_cms_tda'     => 'TD Academy',
            'freundhase_cms_uiq'     => 'Umwelt im Quartier',
            'freundhase_cms_abinsb'  => 'Ab ins B!',
            'freundhase_cms_lag'     => 'LAG Märkische Seen',
            'freundhase_cms_gm'      => 'Green Mierendorff',
            'freundhase_cms_synverz' => 'SynVer*Z',
            'freundhase_cms_spandau' => 'Visit Spandau',
            'freundhase_cms_db'      => 'DoctorBox',
            'freundhase_cms_clients' => 'Referenzen',
        ];
        foreach ($cmsForUsage['freundhase_cms_pages_registry'] ?? [] as $dp) {
            if (!empty($dp['slug'])) {
                $k = 'freundhase_cms_page_' . preg_replace('/[^a-z0-9_]/', '_', $dp['slug']);
                $keyNames[$k] = $dp['title'] ?? $dp['slug'];
            }
        }
        $skip = ['freundhase_cms_image_meta','freundhase_cms_pages_registry'];
        foreach ($images as &$imgData) {
            $usedIn = [];
            foreach ($cmsForUsage as $key => $value) {
                if (in_array($key, $skip) || !isset($keyNames[$key])) continue;
                if (strpos(json_encode($value), $imgData['path']) !== false) {
                    $usedIn[] = $keyNames[$key];
                }
            }
            $imgData['usedIn'] = array_values(array_unique($usedIn));
        }
        unset($imgData);

        // Alle vorhandenen Ordner zurückgeben
        $folders = [];
        if (is_dir($imgDir)) {
            foreach (new DirectoryIterator($imgDir) as $d) {
                if ($d->isDir() && !$d->isDot()) $folders[] = $d->getFilename();
            }
        }
        sort($folders);
        ok(['images' => $images, 'folders' => $folders]);

    // Neuen Bildordner anlegen
    case 'create-folder':
        requireAuth();
        $name = preg_replace('/[^a-z0-9\-]/', '-', strtolower(trim($body['name'] ?? '')));
        $name = trim(preg_replace('/-+/', '-', $name), '-');
        if (strlen($name) < 2) fail('Ordnername ungültig (min. 2 Zeichen, nur a–z 0–9 Bindestrich)');
        $reservedFolders = ['data','fonts','css','js','php'];
        if (in_array($name, $reservedFolders)) fail('Reservierter Ordnername');
        $dir = __DIR__ . '/images/' . $name . '/';
        if (is_dir($dir)) fail('Ordner existiert bereits: ' . $name);
        mkdir($dir, 0755, true);
        ok(['folder' => $name]);

    default:
        fail('Unbekannte Aktion');
}
