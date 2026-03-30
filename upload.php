<?php
/**
 * Image Upload & List Handler — freund-hase.com CMS
 *
 * SETUP: Setze $UPLOAD_TOKEN auf einen sicheren Wert.
 *        Denselben Token im Admin-Panel unter "Bilder verwalten" eintragen.
 *
 * POST  upload.php              → Bild hochladen (fields: file, folder, token)
 * GET   upload.php?list=1&...   → Bildliste eines Ordners (params: folder, token)
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

/* ════════════════════════════════════
   KONFIGURATION — hier anpassen
   ════════════════════════════════════ */
$UPLOAD_TOKEN = 'DEIN_GEHEIMES_TOKEN_HIER_AENDERN';  // <-- Hier sicheres Token setzen
$MAX_SIZE     = 8 * 1024 * 1024;  // 8 MB
/* ════════════════════════════════════ */

$ALLOWED_FOLDERS = ['db','tdacademy','uiq','abinsb','lag','gm','synverz','spandau','index'];
$ALLOWED_EXTS    = ['jpg','jpeg','png','webp','gif','svg'];
$ALLOWED_TYPES   = ['image/jpeg','image/jpg','image/png','image/webp','image/gif','image/svg+xml'];

/* ── Auth helper ── */
function checkToken($token, $expected) {
    if (empty($expected) || $expected === 'DEIN_GEHEIMES_TOKEN_HIER_AENDERN') {
        http_response_code(503);
        echo json_encode(['success'=>false,'error'=>'Upload-Token nicht konfiguriert. Bitte upload.php bearbeiten.']);
        exit;
    }
    if (!hash_equals($expected, $token)) {
        http_response_code(403);
        echo json_encode(['success'=>false,'error'=>'Ungültiger Token']);
        exit;
    }
}

/* ── Route: GET list ── */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['list'])) {
    $token  = trim($_GET['token'] ?? '');
    checkToken($token, $UPLOAD_TOKEN);

    $folder = preg_replace('/[^a-z0-9_\-]/', '', strtolower($_GET['folder'] ?? ''));
    if (!in_array($folder, $ALLOWED_FOLDERS)) {
        echo json_encode(['success'=>false,'error'=>'Ungültiger Ordner']);
        exit;
    }

    $dir = __DIR__ . '/images/' . $folder . '/';
    $files = [];
    if (is_dir($dir)) {
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') continue;
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            if (in_array($ext, $ALLOWED_EXTS)) $files[] = $f;
        }
        sort($files);
    }
    echo json_encode(['success'=>true,'files'=>$files,'folder'=>$folder]);
    exit;
}

/* ── Route: POST upload ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = trim($_POST['token'] ?? '');
    checkToken($token, $UPLOAD_TOKEN);

    $folder = preg_replace('/[^a-z0-9_\-]/', '', strtolower($_POST['folder'] ?? ''));
    if (!in_array($folder, $ALLOWED_FOLDERS)) {
        echo json_encode(['success'=>false,'error'=>'Ungültiger Ordner: ' . $folder]);
        exit;
    }

    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $code = $_FILES['file']['error'] ?? 99;
        $msgs = [1=>'Datei zu groß (PHP)',2=>'Datei zu groß (Form)',3=>'Unvollständiger Upload',4=>'Keine Datei'];
        echo json_encode(['success'=>false,'error'=>'Upload-Fehler: '.($msgs[$code]??'Code '.$code)]);
        exit;
    }

    $file     = $_FILES['file'];
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);

    if (!in_array($mimeType, $ALLOWED_TYPES)) {
        echo json_encode(['success'=>false,'error'=>'Dateityp nicht erlaubt: ' . $mimeType]);
        exit;
    }

    if ($file['size'] > $MAX_SIZE) {
        echo json_encode(['success'=>false,'error'=>'Datei zu groß (max. 8 MB)']);
        exit;
    }

    $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $safeName = preg_replace('/[^a-z0-9_\-]/', '_', strtolower(pathinfo($file['name'], PATHINFO_FILENAME)));
    $safeName = substr(trim($safeName, '_'), 0, 80);
    if (empty($safeName)) $safeName = 'image_' . time();
    $filename = $safeName . '.' . $ext;

    $targetDir = __DIR__ . '/images/' . $folder . '/';
    if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);

    // Avoid overwriting
    $finalPath = $targetDir . $filename;
    if (file_exists($finalPath)) {
        $counter = 1;
        do {
            $filename  = $safeName . '_' . $counter . '.' . $ext;
            $finalPath = $targetDir . $filename;
            $counter++;
        } while (file_exists($finalPath));
    }

    if (!move_uploaded_file($file['tmp_name'], $finalPath)) {
        echo json_encode(['success'=>false,'error'=>'Datei konnte nicht gespeichert werden (Schreibrechte prüfen)']);
        exit;
    }

    echo json_encode([
        'success'  => true,
        'path'     => 'images/' . $folder . '/' . $filename,
        'filename' => $filename,
        'folder'   => $folder,
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['success'=>false,'error'=>'Methode nicht erlaubt']);
