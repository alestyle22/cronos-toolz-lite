<?php
error_reporting(0); ini_set('display_errors', 0);
require_once '../auth.php';
require_once __DIR__ . '/bundle_lib.php';
require_once __DIR__ . '/templates_lib.php';
$currentUser = requireLogin();
require_once __DIR__ . '/../csp_lib.php'; g7_csp_enforce();   // CSP estricta (SEC-24 fase 2): sin código en línea (las vistas previas van aisladas en frame.php)
$isAdmin     = ($currentUser['role'] === 'admin');
$db          = getDB();

// Tabla
try {
    $db->exec("CREATE TABLE IF NOT EXISTS g7_landings (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        platform     VARCHAR(100) NOT NULL,
        name         VARCHAR(255) NOT NULL,
        content_raw  TEXT,
        html_content LONGTEXT,
        created_by   VARCHAR(100),
        created_at   DATETIME DEFAULT NOW(),
        updated_at   DATETIME DEFAULT NOW() ON UPDATE NOW(),
        INDEX idx_platform(platform)
    )");
} catch(Exception $e) {}
try { $db->exec("ALTER TABLE g7_landings ADD COLUMN public_url VARCHAR(500) NULL"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE g7_landings ADD COLUMN published_at DATETIME NULL"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE g7_landings ADD COLUMN editing_by VARCHAR(100) DEFAULT NULL"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE g7_landings ADD COLUMN editing_since DATETIME DEFAULT NULL"); } catch(Exception $e) {}

// GEMINI_API_KEY viene de _private/secrets.php (lo carga auth.php)

$LANDING_TEMPLATE = <<<'TMPL'
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>[TITULO_PROMO] — Terminos y Condiciones</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,100..900;1,14..32,100..900&display=swap" rel="stylesheet">
<style>
  :root{
    --bg: #010101;
    --card: #1A1A1A;
    --border: #262729;
    --text: #e9e9ea;
    --text-dim: #a3a4a7;
    --green: #06FC69;
  }
  * { box-sizing: border-box; }
  body{
    margin:0;
    padding: 32px 16px;
    background: var(--bg);
    color: var(--text);
    font-family: "Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    line-height: 1.5;
  }
  .wrap{ max-width: 1200px; margin: 0 auto; }
  .hero{
    background: var(--green);
    border-radius: 24px;
    min-height: 300px;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    margin-bottom: 24px;
    position: relative;
  }
  .hero-placeholder{
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 8px;
    color: rgba(0,0,0,0.45);
    font-weight: 700;
    font-size: 14px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
  }
  .hero-placeholder svg{ width: 48px; height: 48px; color: rgba(0,0,0,0.35); }
  .card{ width: 100%; background: var(--card); border-radius: 16px; padding: 3em 6em; }
  section{ padding: 20px 0; }
  section + section{ border-top: 1px solid var(--border); }
  .section-title{
    display:flex; align-items:center; gap: 8px;
    font-size: 17px; font-weight: 700; margin: 0 0 12px 0;
    color: #ffffff; font-family: "Inter", sans-serif;
  }
  .section-title::before{
    content: ""; width: 0; height: 0;
    border-top: 5px solid transparent;
    border-bottom: 5px solid transparent;
    border-left: 7px solid var(--green);
    flex-shrink: 0;
  }
  .subsection-title{ font-size: 14.5px; font-weight: 700; color: #ffffff; margin: 16px 0 8px 0; }
  p{ margin: 0 0 10px 0; font-size: 14.5px; color: var(--text); }
  p.sub{ color: #ffffff; }
  p.example{ color: var(--text-dim); font-size: 14px; margin: 4px 0 0 0; }
  ul{ list-style: none; margin: 8px 0; padding: 0; }
  li{ display:flex; gap: 8px; align-items: flex-start; font-size: 14.5px; margin-bottom: 6px; }
  li::before{ content: "\2713"; color: var(--green); font-weight: 700; flex-shrink: 0; }
  a{ color: #06FC69; text-decoration: none!important; }
  a:hover{ text-decoration: underline; }
  strong{ color: #ffffff; }
  .example-box{
    background: rgba(255,255,255,0.03);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 12px 14px;
    margin: 8px 0 4px 0;
  }
  .table-wrap{ overflow-x: auto; margin-top: 8px; }
  table{ width: 100%; border-collapse: collapse; font-size: 14px; min-width: 520px; }
  th, td{ text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--border); white-space: nowrap; }
  th{ color: var(--green); font-weight: 700; font-size: 13px; text-transform: uppercase; letter-spacing: 0.03em; }
  td{ color: var(--text); }
  tbody tr:last-child td{ border-bottom: none; }
  .summary-box{
    background: rgba(6,252,105,0.06);
    border: 1px solid rgba(6,252,105,0.35);
    border-radius: 10px;
    padding: 0.9em 1.25em;
    margin-bottom: 2em;
    display: flex; align-items: baseline; gap: 10px; flex-wrap: wrap;
    width: 100%; justify-content: center;
  }
  .summary-title{ font-size: 12px; font-weight: 700; color: var(--green); text-transform: uppercase; letter-spacing: 0.04em; margin: 0; white-space: nowrap; }
  .summary-date-value{ font-size: 14px; color: #ffffff; font-weight: 600; margin: 0; line-height: 1.3; }
  .promo-narrative{ font-size: 15.5px; color: var(--text); line-height: 1.7; margin: 0 0 2em 0; }
  .promo-narrative strong{ color: #FFF; }
  .benefits-row{ display: flex; flex-wrap: wrap; gap: 16px; margin: 0 0 1.25em 0; }
  .benefit-badge{
    display: flex; align-items: center; gap: 8px;
    border-radius: 999px; padding: 0; margin-right: 1em;
    font-size: 13.5px; font-weight: 700; color: #ffffff;
  }
  .benefit-badge svg{ width: 18px; height: 18px; color: var(--green); flex-shrink: 0; }
  .benefit-badge .benefit-value{ color: var(--green); font-size: 16px; font-weight: 800; }
  .tyc-accordion{
    border: 1px solid var(--border);
    border-radius: 12px; overflow: hidden;
    transition: border-color 0.3s ease, box-shadow 0.3s ease;
  }
  .tyc-accordion[open]{ border-color: var(--green); animation: tyc-glow 1.6s ease-in-out infinite alternate; }
  @keyframes tyc-glow{
    from{ box-shadow: 0 0 0px rgba(6,252,105,0.15); }
    to{ box-shadow: 0 0 18px rgba(6,252,105,0.45); }
  }
  .tyc-accordion summary{
    list-style: none; cursor: pointer;
    display: flex; align-items: center; justify-content: space-between;
    padding: 1.1em 1.5em; font-size: 16px; font-weight: 700;
    color: #ffffff; background: rgba(255,255,255,0.03);
  }
  .tyc-accordion summary::-webkit-details-marker{ display: none; }
  .tyc-accordion summary::after{ content: "+"; font-size: 22px; color: var(--green); line-height: 1; flex-shrink: 0; margin-left: 12px; }
  .tyc-accordion[open] summary::after{ content: "\2013"; }
  .tyc-content{ padding: 0 1.5em; }
  .tyc-content section:first-of-type{ border-top: none; }
  .title-cta{ display: flex; justify-content: space-between; align-items: center; margin-bottom: 2em; }
  .cta{
    background: #0A0A0A; color: #ffffff;
    border: .25em solid #06FC69; padding: 1em 1.5em; margin: 0;
    border-radius: 1.25em; height: 72px;
    justify-content: center; align-content: center;
    text-decoration: none; display: inline-flex; align-items: center;
  }
  h1{ font-size: 3em; color: #06FC69; font-family: "Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; font-weight: 700; padding: 0; margin: 0; }
  @media (max-width: 600px){
    body{ padding: 16px 8px; }
    .hero{ min-height: 200px; border-radius: 16px; }
    .card{ padding: 24px 20px; border-radius: 12px; }
    h1{ font-size: 2em; text-align: center; padding-bottom: 1em; }
    .title-cta{ flex-direction: column; }
    .cta{ text-align:center; width:100%; padding: 1em; }
    .benefits-row{ flex-direction: column; }
  }
</style>
<script src="https://unpkg.com/lucide@0.460.0/dist/umd/lucide.min.js" integrity="sha384-ieG+IKD0d/ZPXyCBTMVAbqsQdns8QGJR/e26WMw7M4fkaI/rHcS/YIoi+ah9WGge" crossorigin="anonymous"></script>
</head>
<body>

<div class="wrap">

  <div class="hero">
    <div class="hero-placeholder">
      <i data-lucide="image"></i>
      <span>Hero Image</span>
    </div>
  </div>

  <div class="card">

    <div class="title-cta">
      <h1>[TITULO_PROMO]</h1>
      <a class="cta" href="[URL_CTA]">[TEXTO_CTA]</a>
    </div>

    <div class="benefits-row">
      <!-- UNA .benefit-badge por beneficio principal con <i data-lucide="flame|gift|zap|star|trophy">, .benefit-value y texto descriptivo -->
    </div>

    <p class="promo-narrative">[NARRATIVA_PROMO_CON_STRONG_EN_VALORES_CLAVE]</p>

    <div class="summary-box">
      <p class="summary-title">Vigencia de la Promocion</p>
      <p class="summary-date-value">[FECHAS_VIGENCIA]</p>
    </div>

    <details class="tyc-accordion">
      <summary>Terminos y Condiciones</summary>
      <div class="tyc-content">
        <!-- SECCIONES: una <section> por cada clausula con <h2 class="section-title">, <p>, <ul><li> y .example-box donde aplique -->
        <!-- AL FINAL: seccion Resumen con .table-wrap > table mostrando los parametros principales -->
      </div>
    </details>

  </div>
</div>

<script>lucide.createIcons();</script>
</body>
</html>
TMPL;

// AJAX handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $data   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $data['action'] ?? $_POST['action'] ?? '';

    // Marca: la plataforma pedida, y la de la landing si viene un id, tienen que ser visibles para el usuario
    $bUid = (int)$currentUser['id'];
    if (!empty($data['platform']) && !g7_brand_in_scope($bUid, (string)$data['platform'])) { echo json_encode(['error' => 'No tienes acceso a esa marca']); exit; }
    if (!empty($data['id'])) {
        $bq = $db->prepare("SELECT platform FROM g7_landings WHERE id=?"); $bq->execute([(int)$data['id']]);
        $bp = $bq->fetchColumn();
        if ($bp !== false && !g7_brand_allows_text($bUid, (string)$bp)) { echo json_encode(['error' => 'Esa landing es de una marca a la que no tienes acceso']); exit; }
    }

    // Landings subidas (carpeta o ZIP): bundle_limits / bundle_put / bundle_commit / bundle_abort
    if (lg_bundle_handle($db, $currentUser, $isAdmin, $action, $data)) exit;

    if ($action === 'list') {
        $platform = $data['platform'] ?? '';
        // Carpetas subidas por FTP con su .landing.json pero sin fila: se registran
        if ($platform !== '') { try { lg_bundle_adopt($db, $platform, $currentUser['username']); } catch (Throwable $e) {} }
        try {
            $stmt = $db->prepare("SELECT l.id,l.name,l.platform,l.created_by,l.created_at,l.updated_at,l.public_url,l.editing_by,l.editing_since,l.published_at,
                COALESCE((SELECT COUNT(*) FROM g7_landing_views WHERE landing_id=l.id),0) as view_count,
                JSON_UNQUOTE(JSON_EXTRACT(l.content_raw,'$.heroImg')) as hero_img,
                JSON_UNQUOTE(JSON_EXTRACT(l.content_raw,'$.type')) as kind,
                JSON_UNQUOTE(JSON_EXTRACT(l.content_raw,'$.files')) as bundle_files
                FROM g7_landings l WHERE l.platform=? ORDER BY l.created_at DESC");
        } catch(Exception $e) {
            $stmt = $db->prepare("SELECT id,name,platform,created_by,created_at,updated_at,public_url,editing_by,editing_since,published_at, 0 as view_count, NULL as hero_img, NULL as kind, NULL as bundle_files FROM g7_landings WHERE platform=? ORDER BY created_at DESC");
        }
        $stmt->execute([$platform]);
        echo json_encode(['ok' => true, 'landings' => $stmt->fetchAll()]);
        exit;
    }

    if ($action === 'get') {
        $id   = (int)($data['id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM g7_landings WHERE id=?");
        $stmt->execute([$id]);
        echo json_encode(['ok' => true, 'landing' => $stmt->fetch()]);
        exit;
    }

    if ($action === 'save') {
        $platform = $data['platform'] ?? '';
        $name     = trim($data['name'] ?? '');
        $raw      = $data['content_raw'] ?? '';
        $html     = $data['html_content'] ?? '';
        $id       = (int)($data['id'] ?? 0);
        if (!$name || !$html) { echo json_encode(['error' => 'Faltan datos']); exit; }
        if ($id) {
            $db->prepare("UPDATE g7_landings SET name=?,content_raw=?,html_content=?,updated_at=NOW() WHERE id=?")
               ->execute([$name, $raw, $html, $id]);
        } else {
            $db->prepare("INSERT INTO g7_landings (platform,name,content_raw,html_content,created_by) VALUES(?,?,?,?,?)")
               ->execute([$platform, $name, $raw, $html, $currentUser['username']]);
            $id = (int)$db->lastInsertId();
        }
        echo json_encode(['ok' => true, 'id' => $id]);
        exit;
    }

    if ($action === 'delete') {
        if (!$isAdmin) { echo json_encode(['error' => 'Sin permiso']); exit; }
        // Una landing subida se lleva su carpeta publicada (si no, se volvería a registrar sola)
        $q = $db->prepare("SELECT * FROM g7_landings WHERE id=?"); $q->execute([(int)($data['id'] ?? 0)]);
        $info = lg_bundle_info($q->fetch(PDO::FETCH_ASSOC) ?: null);
        if ($info && preg_match('/^[a-z0-9][a-z0-9-]*$/', (string)($info['slug'] ?? ''))) lg_rrmdir(lg_pub_dir() . '/' . $info['slug']);
        $db->prepare("DELETE FROM g7_landings WHERE id=?")->execute([(int)($data['id'] ?? 0)]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'generate_ai') {
        $raw = trim($data['content'] ?? '');
        if (!$raw) { echo json_encode(['error' => 'Sin contenido']); exit; }

        // Pedido largo (una landing entera puede tardar minutos):
        // - se suelta la sesión, para que el resto de Cronos (otras pestañas, el chat, recargar) no quede esperando;
        // - las cabeceras salen enseguida: si en este pedido se renovó el id de sesión, la cookie nueva llega aunque
        //   la persona cierre o recargue antes de que termine (si no, a los 2 minutos la sesión vieja muere y la saca);
        // - mientras Claude escribe se manda un espacio cada 10 s, para que el hosting no corte la conexión por inactividad.
        //   El JSON final admite espacios adelante (landings.js lo lee igual).
        @set_time_limit(600);
        session_write_close();
        while (ob_get_level() > 0) @ob_end_flush();
        header('Cache-Control: no-store'); header('X-Accel-Buffering: no');
        echo ' '; @flush();
        $beat = time();
        $tick = function () use (&$beat) { if (time() - $beat >= 10) { $beat = time(); echo ' '; @flush(); } };

        // Plataforma con plantilla de textos (paquete de marcas): la IA solo redacta los textos
        $genPlat = (string)($data['platform'] ?? '');
        if (lg_template_kind($genPlat) === 'textos') {
            try { echo json_encode(['ok' => true, 'html' => lg_tx_generate($raw, $genPlat)]); }
            catch (Throwable $e) { echo json_encode(['error' => $e->getMessage()]); }
            exit;
        }

        $key = GEMINI_API_KEY;
        if (!$key && !g7_claude_ready()) { echo json_encode(['error' => 'No hay IA configurada: carga la clave de Claude en Admin › Inteligencia artificial']); exit; }

        // El prompt puede venir del paquete de marcas (rubro y reglas propias); el de acá es neutro
        $systemPrompt = (string)lg_cfg('prompt_generica',
            "Sos un experto desarrollador HTML especializado en landings de promociones.\n\n" .
            "Tu tarea: tomar el texto de una promocion y generar un archivo HTML completo y standalone listo para usar, siguiendo EXACTAMENTE la estructura del template.\n\n" .
            "REGLAS ESTRICTAS:\n" .
            "1. Devuelve UNICAMENTE el HTML completo, desde <!DOCTYPE html> hasta </html>. Sin nada antes ni despues.\n" .
            "2. NUNCA uses markdown, backticks, triple backticks ni code fences de ningun tipo.\n" .
            "3. Copia el CSS del template VERBATIM — no agregues, quites ni modifiques ninguna regla CSS.\n" .
            "4. Remplaza TODOS los placeholders entre [CORCHETES] con los datos reales del contenido.\n" .
            "5. Para .benefits-row: crea una .benefit-badge por cada beneficio principal.\n" .
            "   Usa <i data-lucide='flame'> para descuentos u ofertas, <i data-lucide='gift'> para regalo,\n" .
            "   <i data-lucide='zap'> para beneficios inmediatos, <i data-lucide='star'> para especiales,\n" .
            "   <i data-lucide='trophy'> para concursos y sorteos. Formato: <i data-lucide='...'>valor clave</i> descripcion.\n" .
            "   Usa la clase .benefit-value en el valor numerico (ej: 20%, \$5.000).\n" .
            "6. La .promo-narrative debe ser persuasiva (150-200 palabras), en espanol argentino,\n" .
            "   con <strong> alrededor de los valores y fechas clave.\n" .
            "7. Dentro de .tyc-content: crea una <section> por cada clausula de T&C.\n" .
            "   Cada seccion: <h2 class='section-title'>Titulo</h2> seguido de <p>, <ul><li> o .example-box segun corresponda.\n" .
            "8. Para ejemplos numericos (compra minima, descuento, tope): usa <div class='example-box'>.\n" .
            "9. Al final del .tyc-content agrega la seccion Resumen con <div class='table-wrap'><table>...\n" .
            "   con los parametros principales de la promo (por ejemplo: Beneficio, Condicion, Minimo, Tope, Vigencia).\n" .
            "10. Si falta la URL del CTA, usa # como href.\n" .
            "11. Mantene la seccion .hero tal cual esta en el template (no la modifiques).\n\n") .
            "TEMPLATE BASE — segui esta estructura exacta:\n\n" . $LANDING_TEMPLATE;

        // Con Claude configurado, la landing la escribe Claude (respuesta larga: va por streaming)
        if (g7_claude_ready()) {
            try {
                $html = g7_claude_text($systemPrompt, "Converti este contenido al HTML del template:\n\n" . $raw, ['maxTokens' => 32000, 'effort' => 'medium', 'tick' => $tick]);
                $html = preg_replace('/^```[a-z]*\n?/i', '', trim($html));
                $html = preg_replace('/\n?```$/', '', $html);
                echo json_encode(['ok' => true, 'html' => trim($html), 'ai' => 'Claude']);
            } catch (Throwable $e) { echo json_encode(['error' => $e->getMessage()]); }
            exit;
        }

        $payload = [
            'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
            'contents' => [
                ['role'=>'user', 'parts'=>[['text'=>"Converti este contenido al HTML del template:\n\n".$raw]]]
            ],
            'generationConfig' => [
                'maxOutputTokens' => 8192,
                'temperature'     => 0.2,
            ],
        ];

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . $key;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $resp     = curl_exec($ch);
        $curlErr  = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$resp || $curlErr) { echo json_encode(['error'=>'cURL: '.($curlErr?:'sin respuesta')]); exit; }
        $result = json_decode($resp, true);
        if (isset($result['error'])) { echo json_encode(['error'=>'['.$httpCode.'] '.($result['error']['message']??'Error Gemini')]); exit; }
        $html = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $html = preg_replace('/^```[a-z]*\n?/i', '', trim($html));
        $html = preg_replace('/\n?```$/', '', $html);
        echo json_encode(['ok'=>true, 'html'=>trim($html)]);
        exit;
    }

    echo json_encode(['error' => 'Accion desconocida']); exit;
}

// Plataformas: las del paquete de marcas o las genéricas (templates_lib.php). El ejemplo del texto de la promo
// sale de la plataforma, o del ejemplo general del paquete, o del neutro del textarea (landings.js)
$platforms = array_map(function ($p) {
    $p['ejemplo'] = (string)($p['ejemplo'] ?? lg_cfg('ejemplo', ''));
    return $p;
}, lg_platforms());
// Solo las plataformas de las marcas que este usuario ve (Admin › Marcas)
$platforms = array_values(array_filter($platforms, fn($p) => g7_brand_in_scope((int)$currentUser['id'], $p['id'] . ' ' . $p['url'])));
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Landing Gen - <?= htmlspecialchars(g7_site('short')) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" integrity="sha384-/o6I2CkkWC//PSjvWC/eYN7l3xM3tJm8ZzVkCOfp//W05QcE3mlGskpoHB6XqI+B" crossorigin="anonymous">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{
  --brand:var(--g7-accent);--brand-lo:rgba(var(--g7-accent-rgb),.1);--brand-mid:rgba(var(--g7-accent-rgb),.25);
  --bg:var(--g7-bg);--s1:var(--g7-bg-2);--s2:var(--g7-bg-3);
  --brd:var(--g7-border-2);--brd2:var(--g7-border-3);
  --txt:var(--g7-text);--txt2:var(--g7-text-2);--txt3:var(--g7-text-4);
}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--txt);font-family:'Inter',-apple-system,sans-serif;height:100vh;overflow:hidden;display:flex;flex-direction:column;font-size:14px;-webkit-font-smoothing:antialiased}

/* Inner bar */
.lg-bar{height:46px;padding:0 20px;border-bottom:1px solid var(--brd);display:flex;align-items:center;gap:14px;flex-shrink:0;background:var(--s1)}
.lg-bar-brand{font-size:12px;font-weight:800;letter-spacing:-.01em;display:flex;align-items:center;gap:7px}
.lg-bar-brand i{color:var(--brand)}
.lg-bar-breadcrumb{display:flex;align-items:center;gap:6px;font-size:11px;color:var(--txt3);flex:1}
.lg-bar-breadcrumb .sep{color:var(--brd2)}

/* Step bar */
.step-bar{height:42px;padding:0 20px;border-bottom:1px solid var(--brd);display:flex;align-items:center;flex-shrink:0;background:var(--bg)}
.step-item{display:flex;align-items:center;gap:7px;font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--txt3);transition:color .2s;cursor:default;white-space:nowrap;padding:0 4px}
.step-item.done{color:var(--brand);cursor:pointer}
.step-item.active{color:var(--txt2)}
.step-item.done:hover{color:#ff8833}
.step-num{width:20px;height:20px;border-radius:50%;border:1.5px solid currentColor;display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:800;flex-shrink:0;transition:all .2s}
.step-item.active .step-num{border-color:var(--brand);background:var(--brand-lo);color:var(--brand)}
.step-item.done .step-num{background:var(--brand);border-color:var(--brand);color:#000}
.step-sep{height:1px;background:var(--brd2);flex:1;max-width:60px;margin:0 10px}

/* Scroll */
.lg-scroll{flex:1;overflow-y:auto}
.lg-scroll::-webkit-scrollbar{width:4px}
.lg-scroll::-webkit-scrollbar-thumb{background:rgba(var(--g7-ov),.07);border-radius:4px}
.lg-wrap{max-width:1020px;margin:0 auto;padding:28px 24px 48px}

/* Buttons */
.btn-back{background:transparent;border:1px solid var(--brd2);color:var(--txt3);border-radius:8px;padding:7px 12px;font-size:10px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .15s;font-family:inherit;letter-spacing:.04em;text-transform:uppercase}
.btn-back:hover{border-color:rgba(var(--g7-ov),.2);color:var(--txt);background:rgba(var(--g7-ov),.04)}
.btn-primary{background:linear-gradient(135deg,var(--g7-accent-hover),var(--g7-accent-hover));color:#000;border:none;border-radius:9px;padding:10px 20px;font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;cursor:pointer;display:inline-flex;align-items:center;gap:7px;transition:all .18s;box-shadow:0 3px 14px rgba(var(--g7-accent-rgb),.28);font-family:inherit}
.btn-primary:hover{transform:translateY(-1px);box-shadow:0 6px 22px rgba(var(--g7-accent-rgb),.42)}
.btn-primary:active{transform:translateY(0)}

/* === STEP 1 === */
.s1-hero{margin-bottom:30px}
.s1-hero h2{font-size:24px;font-weight:800;letter-spacing:-.03em;line-height:1.1}
.s1-hero h2 em{font-style:normal;color:var(--brand)}
.s1-hero p{font-size:12px;color:var(--txt3);margin-top:6px}

.plat-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px}
.plat-card{
  --pc:var(--g7-accent);
  position:relative;overflow:hidden;
  background:var(--s1);border:1px solid var(--brd);border-radius:14px;
  cursor:pointer;transition:border-color .18s,transform .18s,box-shadow .18s;
}
.plat-card::before{
  content:'';position:absolute;inset:0;
  background:radial-gradient(ellipse at 85% 15%,color-mix(in srgb,var(--pc) 14%,transparent),transparent 65%);
  opacity:0;transition:opacity .22s;pointer-events:none;
}
.plat-card:hover{border-color:color-mix(in srgb,var(--pc) 40%,transparent);transform:translateY(-2px);box-shadow:0 8px 28px rgba(0,0,0,.5)}
.plat-card:hover::before{opacity:1}
.plat-card-inner{padding:18px 16px;display:flex;flex-direction:column;gap:14px}
.plat-card-top{display:flex;align-items:flex-start;justify-content:space-between}
.plat-icon{width:40px;height:40px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:17px;background:color-mix(in srgb,var(--pc) 13%,transparent);color:var(--pc);flex-shrink:0}
.plat-arrow{color:var(--brd2);font-size:11px;margin-top:4px;transition:all .2s}
.plat-card:hover .plat-arrow{color:var(--pc);transform:translateX(4px)}
.plat-name{font-size:13px;font-weight:700;color:var(--txt);line-height:1.2}
.plat-url{font-size:10px;color:var(--txt3);margin-top:3px;display:flex;align-items:center;gap:5px}
.plat-url-dot{width:4px;height:4px;border-radius:50%;background:var(--pc);opacity:.6;flex-shrink:0}

/* El color de cada plataforma va en la tarjeta (--pc, de 'card_color' o 'color') */

/* === STEP 2 === */
.list-hdr{display:flex;align-items:center;gap:14px;margin-bottom:22px;flex-wrap:wrap}
.list-hdr-info{flex:1;min-width:0}
.list-hdr-plat{font-size:18px;font-weight:800;letter-spacing:-.02em}
.list-hdr-meta{font-size:11px;color:var(--txt3);margin-top:3px}

.land-list{display:flex;flex-direction:column;gap:8px}
.land-card{
  --pc:var(--g7-accent);
  display:flex;align-items:stretch;
  background:var(--s1);border:1px solid var(--brd);border-radius:12px;
  overflow:hidden;transition:border-color .14s,background .14s;
}
.land-card:hover{border-color:var(--brd2);background:var(--s2)}
.land-stripe{width:3px;background:var(--pc);flex-shrink:0}
.land-body{flex:1;padding:13px 16px;display:flex;align-items:center;gap:14px;min-width:0;flex-wrap:wrap}
.land-info{flex:1;min-width:0}
.land-nm{font-size:13px;font-weight:700;color:var(--txt);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.land-meta{font-size:10px;color:var(--txt3);margin-top:2px}
.land-actions{display:flex;gap:5px;align-items:center;flex-shrink:0}
.land-btn{background:rgba(var(--g7-ov),.03);border:1px solid var(--brd);color:var(--txt3);border-radius:7px;padding:6px 11px;font-size:10px;font-weight:700;cursor:pointer;transition:all .12s;display:inline-flex;align-items:center;gap:5px;letter-spacing:.04em;text-transform:uppercase;white-space:nowrap;font-family:inherit}
.land-btn:hover{background:rgba(var(--g7-ov),.07);border-color:var(--brd2);color:var(--txt)}
.land-btn.edit{background:rgba(var(--g7-accent-rgb),.08);border-color:rgba(var(--g7-accent-rgb),.2);color:var(--brand)}
.land-btn.edit:hover{background:rgba(var(--g7-accent-rgb),.16)}
.land-btn.del:hover{background:rgba(239,68,68,.09);border-color:rgba(239,68,68,.25);color:#f87171}

.empty-st{text-align:center;padding:72px 20px;display:flex;flex-direction:column;align-items:center;gap:12px}
.empty-st-icon{font-size:36px;opacity:.18;margin-bottom:4px}
.empty-st h3{font-size:14px;font-weight:700;color:var(--txt2)}
.empty-st p{font-size:11px;color:var(--txt3);line-height:1.6;max-width:220px}

/* === STEP 3 === */
.creator-hdr{display:flex;align-items:center;gap:14px;margin-bottom:24px;flex-wrap:wrap}
.creator-hdr-info h2{font-size:17px;font-weight:800;letter-spacing:-.02em}
.creator-hdr-info p{font-size:11px;color:var(--txt3);margin-top:2px}

.creator-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;align-items:start}
@media(max-width:700px){.creator-grid{grid-template-columns:1fr}}

/* Form */
.form-panel{display:flex;flex-direction:column;gap:14px}
.fcard{background:var(--s1);border:1px solid var(--brd);border-radius:12px;overflow:hidden}
.fcard-head{padding:11px 15px;border-bottom:1px solid var(--brd);display:flex;align-items:center;justify-content:space-between}
.fcard-label{font-size:9px;font-weight:700;color:var(--txt3);letter-spacing:.1em;text-transform:uppercase;display:flex;align-items:center;gap:6px}
.fcard-label i{color:var(--brand);font-size:8px}
.fcard-body{padding:14px}

.lg-input{width:100%;background:rgba(var(--g7-ov),.03);border:1px solid var(--brd2);color:var(--txt);border-radius:8px;padding:9px 12px;font-size:13px;outline:none;font-family:inherit;transition:border-color .15s,background .15s,box-shadow .15s}
.lg-input:focus{background:rgba(var(--g7-accent-rgb),.04);border-color:rgba(var(--g7-accent-rgb),.5);box-shadow:0 0 0 3px rgba(var(--g7-accent-rgb),.07)}
.lg-input::placeholder{color:var(--txt3)}
.name-input{font-size:13px;font-weight:600}
.lg-textarea{resize:vertical;min-height:220px;font-size:12px;line-height:1.65;font-family:inherit}
.char-badge{font-size:9px;color:var(--txt3);font-weight:600;letter-spacing:.04em}

/* Tips */
.tips-box{background:rgba(var(--g7-ov),.02);border:1px solid var(--brd);border-radius:10px;overflow:hidden}
.tips-box summary{padding:10px 14px;cursor:pointer;font-size:10px;font-weight:700;color:var(--txt3);display:flex;align-items:center;gap:7px;list-style:none;user-select:none;transition:color .14s;text-transform:uppercase;letter-spacing:.07em}
.tips-box summary:hover{color:var(--txt2)}
.tips-box summary i{color:var(--brand);font-size:8px}
.tips-toggle{margin-left:auto;font-size:13px;color:var(--brd2);transition:transform .2s;display:inline-block}
.tips-box[open] .tips-toggle{transform:rotate(45deg)}
.tips-body{padding:2px 14px 12px;display:flex;flex-direction:column;gap:5px}
.tips-item{display:flex;align-items:flex-start;gap:8px;font-size:11px;color:var(--txt2);line-height:1.45}
.tips-item::before{content:'>';color:var(--brand);flex-shrink:0;font-size:9px;margin-top:2px;font-weight:700}

/* Gen button + loader */
.gen-btn{width:100%;background:linear-gradient(135deg,var(--g7-accent-hover),var(--g7-accent-hover));color:#000;border:none;border-radius:10px;padding:14px;font-size:12px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;cursor:pointer;transition:all .18s;box-shadow:0 4px 18px rgba(var(--g7-accent-rgb),.28);display:flex;align-items:center;justify-content:center;gap:8px;font-family:inherit}
.gen-btn:hover:not(:disabled){transform:translateY(-1px);box-shadow:0 8px 26px rgba(var(--g7-accent-rgb),.44)}
.gen-btn:active:not(:disabled){transform:translateY(0)}
.gen-btn:disabled{opacity:.45;cursor:not-allowed;transform:none;box-shadow:none}

.gen-loading{display:none;align-items:center;gap:12px;padding:13px 15px;background:rgba(var(--g7-accent-rgb),.05);border:1px solid rgba(var(--g7-accent-rgb),.13);border-radius:10px}
.gen-loading.on{display:flex}
.ld-dots{display:flex;gap:4px;flex-shrink:0}
.ld-dots span{width:5px;height:5px;border-radius:50%;background:var(--brand);animation:dotB 1.1s ease-in-out infinite}
.ld-dots span:nth-child(2){animation-delay:.18s}
.ld-dots span:nth-child(3){animation-delay:.36s}
@keyframes dotB{0%,80%,100%{opacity:.2;transform:scale(.75)}40%{opacity:1;transform:scale(1)}}
.ld-msg{font-size:11px;font-weight:600;color:rgba(255,130,40,.85);transition:opacity .18s}
.ld-msg.fade{opacity:0}

/* Preview panel */
.prev-panel{position:sticky;top:20px;background:var(--s1);border:1px solid var(--brd);border-radius:12px;overflow:hidden;display:flex;flex-direction:column}
.prev-topbar{padding:10px 13px;border-bottom:1px solid var(--brd);display:flex;align-items:center;gap:8px;flex-shrink:0;flex-wrap:wrap}
.prev-label{font-size:9px;font-weight:700;color:var(--txt3);letter-spacing:.1em;text-transform:uppercase;display:flex;align-items:center;gap:6px;flex:1}
.prev-dot{font-size:6px}
.prev-dot.live{color:#22c55e}
.prev-dot.idle{color:var(--txt3)}
.device-toggle{display:flex;gap:2px;background:rgba(var(--g7-ov),.04);border:1px solid var(--brd);border-radius:6px;padding:2px}
.dev-btn{padding:4px 8px;border:none;background:transparent;color:var(--txt3);cursor:pointer;border-radius:4px;font-size:10px;transition:all .12s;font-family:inherit}
.dev-btn.on{background:rgba(var(--g7-ov),.09);color:var(--txt)}
.prev-actions{display:none;align-items:center;gap:5px;flex-wrap:wrap}
.pv-btn{background:rgba(var(--g7-ov),.03);border:1px solid var(--brd);color:var(--txt3);border-radius:7px;padding:5px 10px;font-size:10px;font-weight:700;cursor:pointer;transition:all .12s;display:inline-flex;align-items:center;gap:4px;letter-spacing:.04em;text-transform:uppercase;white-space:nowrap;font-family:inherit}
.pv-btn:hover{background:rgba(var(--g7-ov),.07);color:var(--txt)}
.pv-btn.ok{background:rgba(var(--g7-accent-rgb),.09);border-color:rgba(var(--g7-accent-rgb),.22);color:var(--brand)}
.pv-btn.ok:hover{background:rgba(var(--g7-accent-rgb),.17)}
.pv-btn.save{background:rgba(34,197,94,.07);border-color:rgba(34,197,94,.2);color:#4ade80}
.pv-btn.save:hover{background:rgba(34,197,94,.14)}

/* Preview empty */
.prev-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:60px 20px;text-align:center;gap:14px;min-height:380px}
.prev-glow{width:64px;height:64px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:26px;background:radial-gradient(circle,rgba(var(--g7-accent-rgb),.14),transparent 70%);animation:glowPulse 2.6s ease-in-out infinite}
@keyframes glowPulse{0%,100%{box-shadow:0 0 0 0 rgba(var(--g7-accent-rgb),.08)}50%{box-shadow:0 0 28px 8px rgba(var(--g7-accent-rgb),.1)}}
.prev-empty h3{font-size:13px;font-weight:700;color:var(--txt2)}
.prev-empty p{font-size:11px;color:var(--txt3);line-height:1.6;max-width:180px}
#preview-iframe{width:100%;border:none;display:none;background:#fff}

/* Toast */
.lg-toast{position:fixed;bottom:22px;left:50%;transform:translateX(-50%) translateY(80px);background:color-mix(in srgb, var(--g7-bg-2) 97%, transparent);border:1px solid var(--brd2);color:var(--txt);border-radius:10px;padding:9px 18px;font-size:12px;font-weight:600;transition:transform .28s cubic-bezier(.34,1.4,.64,1);z-index:9999;pointer-events:none;white-space:nowrap;backdrop-filter:blur(8px)}
.lg-toast.show{transform:translateX(-50%) translateY(0)}
.lg-toast.ok{border-color:rgba(34,197,94,.3);color:#4ade80}
.lg-toast.err{border-color:rgba(239,68,68,.3);color:#f87171}

/* Preview modal */
.pv-modal{position:fixed;inset:0;z-index:500;background:rgba(0,0,0,.9);backdrop-filter:blur(10px);display:flex;flex-direction:column;padding:14px}
.pv-modal-bar{display:flex;align-items:center;gap:10px;margin-bottom:12px;flex-shrink:0}
.pv-modal-nm{font-size:13px;font-weight:700;flex:1;color:var(--txt)}
.pv-modal-close{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.2);color:#f87171;border-radius:7px;padding:6px 12px;font-size:10px;font-weight:700;cursor:pointer;font-family:inherit;letter-spacing:.04em;text-transform:uppercase}
.pv-modal-iframe{flex:1;border:none;border-radius:12px;background:#010101}

/* Landings subidas (carpeta o ZIP) */
.btn-sec{background:rgba(var(--g7-ov),.04);color:var(--txt2);border:1px solid var(--brd2);border-radius:9px;padding:9px 16px;font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;cursor:pointer;display:inline-flex;align-items:center;gap:7px;transition:all .15s;font-family:inherit}
.btn-sec:hover{border-color:rgba(var(--g7-accent-rgb),.45);color:var(--txt);background:rgba(var(--g7-accent-rgb),.06)}
.btn-sec:active{transform:translateY(1px)}
.land-kind{display:inline-flex;align-items:center;gap:4px;font-size:8.5px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;padding:2px 7px;border-radius:5px;background:rgba(167,139,250,.12);color:#a78bfa;border:1px solid rgba(167,139,250,.22);vertical-align:2px;margin-left:4px}
.lb-dlg{margin:max(16px,7vh) auto auto;width:min(620px,calc(100vw - 24px));max-height:calc(100dvh - 32px);background:var(--g7-bg-3);color:var(--txt);border:1px solid var(--g7-border-3);border-radius:16px;padding:0;overflow:hidden}
.lb-dlg::backdrop{background:rgba(0,0,0,.75);backdrop-filter:blur(4px)}
.lb-in{display:flex;flex-direction:column;max-height:calc(100dvh - 32px)}
.lb-h,.lb-f{display:flex;align-items:center;gap:10px;padding:14px 18px;border-bottom:1px solid var(--brd)}
.lb-h b{font-size:14px;flex:1}
.lb-h small{display:block;font-size:11px;color:var(--txt3);font-weight:500;margin-top:2px}
.lb-f{border-bottom:0;border-top:1px solid var(--brd);justify-content:flex-end}
.lb-x{background:none;border:0;color:var(--txt3);font-size:15px;cursor:pointer;padding:4px 8px;border-radius:6px}
.lb-x:hover{color:var(--txt)}
.lb-b{padding:16px 18px;overflow:auto;display:flex;flex-direction:column;gap:14px}
.lb-lbl{display:block;font-size:10px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--txt3);margin-bottom:6px}
.lb-drop{border:1.5px dashed var(--brd2);border-radius:12px;padding:22px 16px;text-align:center;display:flex;flex-direction:column;align-items:center;gap:10px;transition:border-color .15s,background .15s}
.lb-drop.over{border-color:var(--brand);background:rgba(var(--g7-accent-rgb),.06)}
.lb-drop>i{font-size:26px;color:var(--brand);opacity:.8}
.lb-drop p{font-size:12px;color:var(--txt2);line-height:1.5}
.lb-drop p small{display:block;color:var(--txt3);font-size:11px}
.lb-pick{display:flex;gap:8px;flex-wrap:wrap;justify-content:center}
.lb-pick label{cursor:pointer}
.lb-pick input{display:none}
.lb-sum{border:1px solid var(--brd);border-radius:12px;background:rgba(var(--g7-ov),.02);padding:12px 14px;font-size:12px;display:flex;flex-direction:column;gap:8px}
.lb-sum-row{display:flex;align-items:center;gap:8px;color:var(--txt2)}
.lb-sum-row i{width:14px;text-align:center;color:var(--txt3)}
.lb-sum-row b{color:var(--txt)}
.lb-sum-row.ok i{color:#4ade80}
.lb-sum details{font-size:11px;color:var(--txt3)}
.lb-sum summary{cursor:pointer;user-select:none}
.lb-sum ul{list-style:none;margin:6px 0 0;padding:0;max-height:140px;overflow:auto;display:flex;flex-direction:column;gap:2px;font-family:var(--g7-font-mono,monospace);font-size:10.5px}
.lb-sum li{display:flex;justify-content:space-between;gap:10px}
.lb-sum li span:last-child{color:var(--txt3);white-space:nowrap}
.lb-err{font-size:12px;color:#f87171;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);border-radius:9px;padding:9px 12px;line-height:1.5}
.lb-prog{height:6px;border-radius:99px;background:rgba(var(--g7-ov),.06);overflow:hidden}
.lb-prog div{height:100%;width:0;background:var(--brand);transition:width .25s}
.lb-pmsg{font-size:11px;color:var(--txt3);margin-top:6px}
.lb-f .btn-primary:disabled{opacity:.4;cursor:default;transform:none;box-shadow:none}
@media(max-width:560px){.lb-pick{flex-direction:column;width:100%}.lb-pick .btn-sec{width:100%;justify-content:center}}

/* === FILTER BAR === */
.filter-bar{display:flex;align-items:center;gap:8px;margin-bottom:14px;flex-wrap:wrap;padding:10px 12px;background:var(--s1);border:1px solid var(--brd);border-radius:12px}
.fb-search{display:flex;align-items:center;gap:7px;background:rgba(var(--g7-ov),.03);border:1px solid var(--brd2);border-radius:8px;padding:6px 10px;flex:1;min-width:140px}
.fb-search i{color:var(--txt3);font-size:10px;flex-shrink:0}
.fb-input{background:none;border:none;outline:none;color:var(--txt);font-size:12px;font-family:inherit;width:100%}
.fb-input::placeholder{color:var(--txt3)}
.fb-status{display:flex;gap:2px;background:rgba(var(--g7-ov),.03);border:1px solid var(--brd);border-radius:8px;padding:2px;flex-shrink:0}
.fst-btn{background:transparent;border:none;color:var(--txt3);font-size:10px;font-weight:700;cursor:pointer;border-radius:6px;padding:5px 10px;font-family:inherit;letter-spacing:.04em;text-transform:uppercase;transition:all .12s;white-space:nowrap}
.fst-btn.active{background:rgba(var(--g7-ov),.08);color:var(--txt)}
.fst-btn:hover:not(.active){color:var(--txt2)}
.fb-sort{background:rgba(var(--g7-ov),.03);border:1px solid var(--brd2);color:var(--txt2);border-radius:8px;padding:6px 10px;font-size:11px;font-family:inherit;outline:none;cursor:pointer;flex-shrink:0}
.fb-sort option{background:var(--g7-bg-4)}
.fb-view{display:flex;gap:2px;background:rgba(var(--g7-ov),.03);border:1px solid var(--brd);border-radius:8px;padding:2px;flex-shrink:0}
.fv-btn{background:transparent;border:none;color:var(--txt3);cursor:pointer;border-radius:6px;padding:5px 8px;font-size:11px;transition:all .12s;display:flex;align-items:center;justify-content:center}
.fv-btn.active{background:rgba(var(--g7-ov),.08);color:var(--txt)}
.fv-btn:hover:not(.active){color:var(--txt2)}

/* === GRID VIEW CARDS === */
.land-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:10px}
.land-gc{
  --pc:var(--g7-accent);
  background:var(--s1);border:1px solid var(--brd);border-radius:12px;
  overflow:hidden;display:flex;flex-direction:column;
  transition:border-color .15s,transform .15s,box-shadow .15s;
}
.land-gc:hover{border-color:color-mix(in srgb,var(--pc) 40%,transparent);transform:translateY(-2px);box-shadow:0 8px 28px rgba(0,0,0,.55)}
.land-gc-head{
  height:140px;display:flex;align-items:center;justify-content:center;
  background:linear-gradient(135deg,color-mix(in srgb,var(--pc) 16%,transparent),color-mix(in srgb,var(--pc) 5%,transparent));
  border-bottom:1px solid color-mix(in srgb,var(--pc) 14%,transparent);
  position:relative;overflow:hidden;flex-shrink:0;
}
.land-gc-head::after{content:'';position:absolute;right:-18px;top:-18px;width:72px;height:72px;border-radius:50%;background:color-mix(in srgb,var(--pc) 10%,transparent)}
.land-gc-icon{width:34px;height:34px;border-radius:9px;background:color-mix(in srgb,var(--pc) 18%,transparent);display:flex;align-items:center;justify-content:center;color:var(--pc);font-size:14px;position:relative;z-index:1}
/* Preview iframe en grid card */
.land-gc-head.has-hero{background:var(--g7-bg);padding:0}
.land-gc-head.has-hero::after{display:none}
.land-gc-hero{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;object-position:top;display:block}
.land-gc-head-ov{position:absolute;inset:0;background:linear-gradient(to bottom,transparent 40%,rgba(0,0,0,.6));z-index:2;pointer-events:none}
.land-gc-body{padding:12px 14px;flex:1;display:flex;flex-direction:column;gap:5px}
.land-gc-nm{font-size:13px;font-weight:700;color:var(--txt);line-height:1.3;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.land-gc-meta{font-size:10px;color:var(--txt3)}
.land-gc-status{display:inline-flex;align-items:center;gap:5px;font-size:9px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;padding:3px 8px;border-radius:999px;margin-top:3px;width:fit-content}
.land-gc-status.pub{background:rgba(34,197,94,.1);color:#4ade80;border:1px solid rgba(34,197,94,.2)}
.land-gc-status.draft{background:rgba(var(--g7-ov),.04);color:var(--txt3);border:1px solid var(--brd)}
.land-gc-foot{padding:9px 12px;border-top:1px solid var(--brd);display:flex;gap:4px;flex-wrap:wrap}
.land-gc-foot .land-btn{font-size:9px;padding:5px 9px}

/* List view status dot */
.land-st-dot{display:inline-flex;align-items:center;gap:4px;font-size:9px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#4ade80;margin-right:6px}
.land-st-dot i{font-size:5px}
/* Editing badge */
.land-editing-badge{display:inline-flex;align-items:center;gap:4px;font-size:9px;font-weight:700;color:#f59e0b;background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.25);border-radius:999px;padding:2px 7px;margin-left:6px;vertical-align:middle;letter-spacing:.03em}
.land-editing-badge i{font-size:8px}
.lg-x-hov:hover{color:#e4e4e7!important}
</style>
<link rel="stylesheet" href="/toolz/assets/g7-studio.css?v=<?= (int)@filemtime(__DIR__ . '/../assets/g7-studio.css') ?>">
<link rel="stylesheet" href="/toolz/assets/tool-studio.css?v=<?= (int)@filemtime(__DIR__ . '/../assets/tool-studio.css') ?>">
</head>
<body>
<?php include __DIR__ . '/../topbar.php'; ?>

<div class="lg-bar">
  <div class="lg-bar-brand"><i class="fa-solid fa-wand-magic-sparkles"></i> Landing Gen</div>
  <div class="lg-bar-breadcrumb" id="lg-breadcrumb"></div>
</div>

<div class="step-bar">
  <div class="step-item active" id="si-1" data-on-click="s1click">
    <div class="step-num">1</div>Plataforma
  </div>
  <div class="step-sep"></div>
  <div class="step-item" id="si-2">
    <div class="step-num">2</div>Mis Landings
  </div>
  <div class="step-sep"></div>
  <div class="step-item" id="si-3">
    <div class="step-num">3</div>Crear
  </div>
</div>

<div class="lg-scroll">
<div class="lg-wrap">

<!-- STEP 1 -->
<div id="step-1">
  <div class="s1-hero">
    <h2>Para que plataforma <em>creas hoy?</em></h2>
    <p>Selecciona el sitio y genera landings profesionales en segundos con IA</p>
  </div>
  <div class="plat-grid">
    <?php foreach($platforms as $p): ?>
    <div class="plat-card" data-pid="<?= htmlspecialchars($p['id']) ?>" style="--pc:<?= htmlspecialchars(preg_replace('/[^#a-zA-Z0-9(),.\s%-]/', '', (string)($p['card_color'] ?? $p['color']))) ?>" data-plat='<?= htmlspecialchars(json_encode($p), ENT_QUOTES) ?>'>
      <div class="plat-card-inner">
        <div class="plat-card-top">
          <div class="plat-icon"><i class="fa-solid <?= htmlspecialchars($p['icon']) ?>"></i></div>
          <i class="fa-solid fa-arrow-right plat-arrow"></i>
        </div>
        <div>
          <div class="plat-name"><?= htmlspecialchars($p['name']) ?></div>
          <div class="plat-url"><span class="plat-url-dot"></span><?= htmlspecialchars($p['url']) ?></div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- STEP 2 -->
<div id="step-2" style="display:none">
  <div class="list-hdr">
    <button class="btn-back" data-on-click="goStep" data-click-1="n:1"><i class="fa-solid fa-arrow-left"></i> Volver</button>
    <div class="list-hdr-info">
      <div class="list-hdr-plat" id="list-plat-name"></div>
      <div class="list-hdr-meta" id="list-meta"></div>
    </div>
    <button class="btn-sec" data-on-click="openBundle" title="Subir una landing ya armada (carpeta o ZIP con sus imágenes)"><i class="fa-solid fa-cloud-arrow-up"></i> Subir landing</button>
    <button class="btn-primary" data-on-click="goStep" data-click-1="n:3" data-click-2="s:new"><i class="fa-solid fa-plus"></i> Nueva landing</button>
  </div>

  <div class="filter-bar" id="filter-bar" style="display:none">
    <div class="fb-search">
      <i class="fa-solid fa-magnifying-glass"></i>
      <input type="text" id="filter-search" class="fb-input" placeholder="Buscar landing..." data-on-input="filterLandings">
    </div>
    <div class="fb-status">
      <button class="fst-btn active" data-on-click="setStatusFilter" data-click-1="this" data-click-2="s:all">Todos</button>
      <button class="fst-btn" data-on-click="setStatusFilter" data-click-1="this" data-click-2="s:published">Publicados</button>
      <button class="fst-btn" data-on-click="setStatusFilter" data-click-1="this" data-click-2="s:draft">Borradores</button>
    </div>
    <select class="fb-sort" id="filter-sort" data-on-change="filterLandings">
      <option value="newest">Más recientes</option>
      <option value="oldest">Más antiguos</option>
      <option value="az">Nombre A-Z</option>
    </select>
    <select class="fb-sort" id="filter-month" data-on-change="filterLandings" style="display:none">
      <option value="">Todos los meses</option>
    </select>
    <select class="fb-sort" id="filter-autor" data-on-change="filterLandings" style="display:none">
      <option value="">Todos los autores</option>
    </select>
    <div class="fb-view">
      <button class="fv-btn active" id="fv-list" data-on-click="setView" data-click-1="s:list" title="Vista lista"><i class="fa-solid fa-list"></i></button>
      <button class="fv-btn" id="fv-grid" data-on-click="setView" data-click-1="s:grid" title="Vista tarjetas"><i class="fa-solid fa-border-all"></i></button>
    </div>
  </div>

  <div id="land-list-wrap">
    <div class="empty-st"><div class="empty-st-icon"><i class="fa-solid fa-spinner fa-spin"></i></div><h3>Cargando...</h3></div>
  </div>
</div>

<!-- STEP 3 -->
<div id="step-3" style="display:none">
  <div class="creator-hdr">
    <button class="btn-back" data-on-click="goStep" data-click-1="n:2"><i class="fa-solid fa-arrow-left"></i> Volver</button>
    <div class="creator-hdr-info">
      <h2 id="creator-title">Nueva landing</h2>
      <p id="creator-sub"></p>
    </div>
  </div>

  <div class="creator-grid">

    <!-- LEFT: Form -->
    <div class="form-panel">
      <div class="fcard">
        <div class="fcard-head">
          <span class="fcard-label"><i class="fa-solid fa-tag"></i>Nombre de la landing</span>
        </div>
        <div class="fcard-body">
          <input type="text" id="landing-name" class="lg-input name-input" placeholder="Ej: Arranca Ganando - Agosto 2026">
        </div>
      </div>

      <div class="fcard">
        <div class="fcard-head">
          <span class="fcard-label"><i class="fa-solid fa-align-left"></i>Contenido de la promo</span>
          <span id="char-count" class="char-badge"></span>
        </div>
        <div class="fcard-body">
          <textarea id="raw-content" class="lg-input lg-textarea" data-on-input="updateCharCount" placeholder="Promo: Semana de descuentos&#10;&#10;Beneficio: 20% en la segunda compra&#10;Vigencia: 1 al 7 de diciembre&#10;Compra minima: $10.000&#10;Tope de descuento: $5.000&#10;&#10;URL: https://tusitio.com/promo&#10;CTA: Quiero participar&#10;&#10;T&C: Aplican condiciones..."></textarea>
        </div>
      </div>

      <details class="tips-box">
        <summary>
          <i class="fa-solid fa-lightbulb"></i>Que incluir para mejores resultados?
          <span class="tips-toggle">+</span>
        </summary>
        <div class="tips-body">
          <div class="tips-item">Nombre de la promo y plataforma</div>
          <div class="tips-item">Beneficios con valores exactos (ej: 100% hasta $200.000)</div>
          <div class="tips-item">Fechas de vigencia inicio y fin</div>
          <div class="tips-item">Requisitos: deposito minimo, rollover, ganancia maxima</div>
          <div class="tips-item">Juegos incluidos o excluidos si aplica</div>
          <div class="tips-item">URL del CTA y texto del boton</div>
          <div class="tips-item">Terminos y condiciones completos</div>
        </div>
      </details>

      <div style="display:flex;gap:8px">
        <button class="gen-btn" id="gen-btn" data-on-click="generateAI" style="flex:1">
          <i class="fa-solid fa-wand-magic-sparkles"></i> Generar con IA
        </button>
        <button class="gen-btn" data-on-click="openPasteModal" style="flex:0 0 auto;background:linear-gradient(135deg,var(--g7-bg-5),var(--g7-bg-5));box-shadow:none;min-width:48px" title="Pegar HTML directamente">
          <i class="fa-solid fa-code"></i>
        </button>
      </div>
      <div class="gen-loading" id="gen-loading">
        <div class="ld-dots"><span></span><span></span><span></span></div>
        <div id="ld-msg" class="ld-msg">Analizando contenido...</div>
      </div>
    </div>

    <!-- RIGHT: Preview -->
    <div class="prev-panel">
      <div class="prev-topbar">
        <div class="prev-label">
          <i id="prev-dot" class="fa-solid fa-circle prev-dot idle"></i>Preview
        </div>
        <div class="prev-actions" id="prev-actions">
          <div class="device-toggle">
            <button class="dev-btn on" id="dev-desk" data-on-click="setDevice" data-click-1="s:desktop"><i class="fa-solid fa-desktop"></i></button>
            <button class="dev-btn" id="dev-mob" data-on-click="setDevice" data-click-1="s:mobile"><i class="fa-solid fa-mobile-screen"></i></button>
          </div>
          <button class="pv-btn" data-on-click="copyHTML"><i class="fa-solid fa-copy"></i></button>
          <button class="pv-btn" data-on-click="dlHTML"><i class="fa-solid fa-download"></i></button>
          <button class="pv-btn save" data-on-click="saveLanding"><i class="fa-solid fa-floppy-disk"></i></button>
          <button class="pv-btn ok" id="open-editor-btn" data-on-click="openInEditor"><i class="fa-solid fa-pen-ruler"></i> Editor</button>
        </div>
      </div>
      <div class="prev-empty" id="prev-empty">
        <div class="prev-glow">&#10024;</div>
        <h3>El preview aparece aca</h3>
        <p>Completa el contenido y hace clic en <strong style="color:var(--brand)">Generar con IA</strong></p>
      </div>
      <iframe id="preview-iframe" scrolling="yes"></iframe>
    </div>

  </div>
</div>

</div>
</div>

<!-- MODAL PEGAR HTML -->
<div id="paste-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:9000;align-items:center;justify-content:center;padding:20px">
  <div style="background:var(--g7-bg-3);border:1px solid var(--g7-border-3);border-radius:16px;width:100%;max-width:680px;display:flex;flex-direction:column;max-height:90vh">
    <div style="padding:16px 20px;border-bottom:1px solid var(--g7-border-2);display:flex;align-items:center;justify-content:space-between;flex-shrink:0">
      <div style="display:flex;align-items:center;gap:10px">
        <i class="fa-solid fa-code" style="color:var(--g7-accent);font-size:15px"></i>
        <span style="font-size:14px;font-weight:700;color:var(--g7-text)">Pegar HTML directamente</span>
      </div>
      <button data-on-click="closePasteModal" style="background:none;border:none;color:var(--g7-text-4);font-size:16px;cursor:pointer;padding:4px 8px;border-radius:6px;transition:color .15s" class="lg-x-hov"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div style="padding:16px 20px;flex:1;overflow:auto;display:flex;flex-direction:column;gap:10px">
      <p style="font-size:11px;color:var(--g7-text-3);margin:0">Pega el código HTML completo de la landing. Se va a cargar directamente en el preview.</p>
      <textarea id="paste-html-input" style="width:100%;height:280px;background:var(--g7-bg);border:1px solid var(--g7-border-3);border-radius:8px;color:var(--g7-text);font-family:'Courier New',monospace;font-size:11px;padding:12px;resize:vertical;outline:none;box-sizing:border-box;line-height:1.5" placeholder="<!DOCTYPE html>&#10;<html>&#10;  ...&#10;</html>"></textarea>
    </div>
    <div style="padding:12px 20px;border-top:1px solid var(--g7-border-2);display:flex;gap:8px;justify-content:flex-end;flex-shrink:0">
      <button data-on-click="closePasteModal" style="background:var(--g7-bg-5);border:none;color:var(--g7-text-2);border-radius:8px;padding:9px 16px;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit">Cancelar</button>
      <button data-on-click="applyPastedHTML" style="background:linear-gradient(135deg,var(--g7-accent-hover),var(--g7-accent-hover));border:none;color:#000;border-radius:8px;padding:9px 18px;font-size:11px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;cursor:pointer;font-family:inherit;display:flex;align-items:center;gap:6px"><i class="fa-solid fa-check"></i> Cargar HTML</button>
    </div>
  </div>
</div>

<!-- SUBIR LANDING ARMADA -->
<dialog class="lb-dlg" id="lb-dlg" aria-labelledby="lb-title">
  <div class="lb-in">
    <div class="lb-h">
      <b id="lb-title"><span id="lb-title-t">Subir landing</span><small id="lb-sub">Una landing ya armada: su index.html con las imágenes, estilos y fuentes que use.</small></b>
      <button class="lb-x" type="button" data-on-click="closeBundle" aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="lb-b">
      <div>
        <label class="lb-lbl" for="lb-name">Nombre</label>
        <input type="text" id="lb-name" class="lg-input" placeholder="Ej: Cashback VIP" maxlength="120">
      </div>
      <div class="lb-drop" id="lb-drop">
        <i class="fa-solid fa-folder-open"></i>
        <p>Arrastra acá la carpeta o el ZIP de la landing<small>Se publica solo lo que usa la página: las imágenes que sobran quedan afuera.</small></p>
        <div class="lb-pick">
          <label class="btn-sec"><i class="fa-regular fa-folder"></i> Elegir carpeta<input type="file" id="lb-dir" webkitdirectory multiple></label>
          <label class="btn-sec"><i class="fa-regular fa-file-zipper"></i> Elegir ZIP<input type="file" id="lb-zip" accept=".zip,application/zip"></label>
        </div>
      </div>
      <div class="lb-sum" id="lb-sum" hidden></div>
      <div class="lb-err" id="lb-err" hidden></div>
      <div id="lb-progw" hidden><div class="lb-prog"><div id="lb-prog"></div></div><div class="lb-pmsg" id="lb-pmsg"></div></div>
    </div>
    <div class="lb-f">
      <button class="btn-back" type="button" data-on-click="closeBundle">Cancelar</button>
      <button class="btn-primary" type="button" id="lb-go" data-on-click="uploadBundle" disabled><i class="fa-solid fa-rocket"></i> Subir y publicar</button>
    </div>
  </div>
</dialog>

<div class="lg-toast" id="lg-toast"></div>

<script type="application/json" id="lg-data"><?= json_encode(['isAdmin' => (bool)$isAdmin]) ?></script>
<script src="/toolz/landing-gen/frame-host.js?v=<?= (int)@filemtime(__DIR__ . '/frame-host.js') ?>"></script>
<script src="/toolz/landing-gen/landings.js?v=<?= (int)@filemtime(__DIR__ . '/landings.js') ?>"></script>
<script src="/toolz/assets/tool-studio.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/tool-studio.js') ?>" data-ts="landing" data-ts-name="Landing Gen" data-ts-desc="Landings de promo y T&amp;C con IA" data-ts-icon="fa-wand-magic-sparkles" data-ts-grad="#22c55e,#0ea5e9" data-ts-head=".form-panel" data-ts-icons=".fcard-label > i" data-ts-stage=".prev-panel" data-ts-busy="#gen-loading"></script>
</body>
</html>
