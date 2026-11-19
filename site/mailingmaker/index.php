<?php
// === DEBUG: mostrar errores en pantalla ===
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

try {
    require_once __DIR__ . '/../auth.php';

    $user = getCurrentUser();
    if (!$user) {
        header('Location: /toolz/login.php');
        exit;
    }

    $currentUser = $user;
    require_once __DIR__ . '/../csp_lib.php'; g7_csp_enforce();   // CSP estricta (SEC-24 fase 2): generador_mailings.html y mailings.js no tienen código en línea
    require_once __DIR__ . '/brands_inc.php';
    $isAdmin     = ($user['role'] === 'admin');
    $db          = getDB();   // topbar.php necesita la conexión a DB
    // Tracking
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    try {
        $db->prepare("INSERT INTO g7_accesos_rapidos (user_id, tool, tool_name) VALUES (?,?,?)")
           ->execute([$currentUser['id'], '/toolz/mailingmaker/', 'Mailing Maker']);
    } catch(Exception $e) {}
}

    $htmlPath = __DIR__ . '/generador_mailings.html';
    if (!file_exists($htmlPath)) {
        die('Error: no existe ' . $htmlPath);
    }
    $html = file_get_contents($htmlPath);
    if ($html === false) {
        die('Error: no se pudo leer ' . $htmlPath);
    }

    // Título de la pestaña con el nombre del sitio (o el del paquete de marcas)
    $mmTitle = (string)(g7_pack('mailingmaker', [])['titulo'] ?? g7_site('short'));
    $html = str_replace('<title>Generador de Mailings</title>', '<title>Generador de Mailings — ' . htmlspecialchars($mmTitle, ENT_QUOTES, 'UTF-8') . '</title>', $html);

    // Inyectar Font Awesome
    $html = str_replace(
        '<meta name="viewport"',
        '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" integrity="sha384-/o6I2CkkWC//PSjvWC/eYN7l3xM3tJm8ZzVkCOfp//W05QcE3mlGskpoHB6XqI+B" crossorigin="anonymous">' . "\n" . '<meta name="viewport"',
        $html
    );

    // Capturar topbar global
    $topbarPath = __DIR__ . '/../topbar.php';
    if (!file_exists($topbarPath)) {
        die('Error: no existe topbar.php en ' . $topbarPath);
    }
    ob_start();
    include $topbarPath;
    $topbarHtml = ob_get_clean();

    // Insertar links en el topbar
    $topbarHtml = str_replace(
        '<div class="g7-spacer"></div>',
        '<a href="comms.php" class="g7-link" style="border-color:rgba(201,168,76,0.3);color:rgba(201,168,76,0.7);"><i class="fa-solid fa-paper-plane" style="font-size:11px"></i> Comunicación</a><a href="#" data-on-click="mmOpenTplModal" class="g7-link" style="border-color:rgba(201,168,76,0.3);color:rgba(201,168,76,0.7);"><i class="fa-solid fa-envelope-open-text" style="font-size:11px"></i> Plantillas</a><span style="font-size:10px;font-weight:700;letter-spacing:2px;color:rgba(201,168,76,0.45);padding:0 4px;white-space:nowrap;">MAILING MAKER</span><div class="g7-spacer"></div>',
        $topbarHtml
    );

    // Reemplazar el header del generador por el topbar global (con strpos, sin preg_replace)
    $startTag = '<header class="topbar">';
    $endTag   = '</header>';
    $startPos = strpos($html, $startTag);
    if ($startPos !== false) {
        $endPos = strpos($html, $endTag, $startPos);
        if ($endPos !== false) {
            $html = substr($html, 0, $startPos) . $topbarHtml . substr($html, $endPos + strlen($endTag));
        } else {
            $html = str_replace('<body>', '<body>' . $topbarHtml, $html);
        }
    } else {
        $html = str_replace('<body>', '<body>' . $topbarHtml, $html);
    }

    // Ocultar plataformas de marcas que el usuario no ve (Admin › Marcas)
    $bjs = mm_brand_tags((int)$currentUser['id']);   // antes g7_brand_js(), que imprime un script en línea
    if ($bjs !== '' && ($bpos = strrpos($html, '</body>')) !== false) $html = substr($html, 0, $bpos) . $bjs . substr($html, $bpos);
    // Piel estudio (assets/tool-studio.css/js), 2026-11-14
    $tsV = fn($f) => (int)@filemtime(__DIR__ . '/../assets/' . $f);
    $html = str_replace('</head>', '<link rel="stylesheet" href="/toolz/assets/tool-studio.css?v=' . $tsV('tool-studio.css') . '"></head>', $html);
    $tsJs = '<script src="/toolz/assets/tool-studio.js?v=' . $tsV('tool-studio.js') . '" data-ts="mailing" data-ts-name="Mailing Maker" data-ts-desc="Campañas de mail listas para enviar" data-ts-icon="fa-envelope-open-text" data-ts-grad="#f472b6,#a855f7" data-ts-head=".sb-head" data-ts-icons=".sec-lbl > i" data-ts-stage=".main"></script>';
    if (($tp = strrpos($html, '</body>')) !== false) $html = substr($html, 0, $tp) . $tsJs . substr($html, $tp);
    echo $html;

} catch (Throwable $e) {
    echo '<pre style="background:#fee;color:#900;padding:20px;font-family:monospace;font-size:13px;">';
    echo 'ERROR EN INDEX.PHP:' . "\n\n";
    echo 'Tipo: ' . get_class($e) . "\n";
    echo 'Mensaje: ' . $e->getMessage() . "\n";
    echo 'Archivo: ' . $e->getFile() . "\n";
    echo 'Línea: ' . $e->getLine() . "\n\n";
    echo 'Stack trace:' . "\n";
    echo $e->getTraceAsString();
    echo '</pre>';
}