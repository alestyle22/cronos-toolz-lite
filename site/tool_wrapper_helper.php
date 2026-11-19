<?php
/**
 * g7_wrap_tool($htmlFile, $toolUrl, $toolName)
 * Wraps a standalone HTML tool with auth + topbar.
 * Call this at the top of each tool's PHP wrapper.
 */
function g7_wrap_tool(string $htmlFile, string $toolUrl, string $toolName, bool|string $csp = false): void {
    require_once __DIR__ . '/auth.php';
    // $csp: la herramienta ya no tiene código en línea → CSP estricta (SEC-24). Las que todavía tienen, sin el parámetro.
    // Un texto es un perfil de csp_lib.php que suma algo a script-src (p. ej. 'ml' para modelos en el navegador).
    if ($csp) { require_once __DIR__ . '/csp_lib.php'; g7_csp_enforce(is_string($csp) ? $csp : ''); }

    $user = getCurrentUser();
    if (!$user) { header('Location: /toolz/login.php'); exit; }
    g7_enforce_access($user);

    global $currentUser, $isAdmin, $db;
    $currentUser = $user;
    $isAdmin     = ($user['role'] === 'admin');
    $db          = getDB();

    // Track access
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        try {
            $db->prepare("INSERT INTO g7_accesos_rapidos (user_id, tool, tool_name) VALUES (?,?,?)")
               ->execute([$currentUser['id'], $toolUrl, $toolName]);
        } catch(Exception $e) {}
    }

    // Capture topbar (injects g7-theme.css + user accent/theme vars)
    ob_start();
    include __DIR__ . '/topbar.php';
    $topbarHtml = ob_get_clean();

    // Load HTML tool file
    $html = file_get_contents($htmlFile);
    if ($html === false) { http_response_code(500); echo "Error: no se pudo leer $htmlFile"; exit; }
    if (function_exists('g7_pack_strip_missing')) $html = g7_pack_strip_missing($html);   // datos del paquete de marcas que este sitio no tiene

    // Remove the hardcoded g7-theme.css link — topbar.php already injects it with user accent override
    $html = str_replace('<link rel="stylesheet" href="/toolz/g7-theme.css">', '', $html);

    // Add topbar-aware body padding + topbar sticky positioning
    $extraCss = '<style>body{padding-top:0!important}.g7-topbar{position:sticky;top:0;z-index:200}</style>';
    $html = str_replace('</head>', $extraCss . '</head>', $html);

    // Insert topbar after <body> opening tag
    // Con callback: preg_replace interpreta "\\" y "$" del HTML de la topbar y lo altera
    $html = preg_replace_callback('/<body([^>]*)>/i', fn($m) => '<body' . $m[1] . '>' . $topbarHtml, $html, 1);

    echo $html;
}
