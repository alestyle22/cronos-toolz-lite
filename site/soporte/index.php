<?php
/**
 * soporte/index.php — Soporte de Cronos: reportar un problema, sugerir una mejora o consultar por el plan.
 * Lo que se manda llega como ticket al Cronos principal; acá cada persona ve sus tickets y las respuestas. Lógica en api.php.
 */
require_once __DIR__ . '/../auth.php';
$currentUser = requireLogin();
require_once dirname(__DIR__) . '/csp_lib.php'; g7_csp_enforce();   // CSP estricta en modo bloqueo: esta página no tiene código en línea (SEC-24)
$db = getDB();
try {
    $_p = $db->prepare("SELECT pref_key, pref_value FROM g7_user_prefs WHERE user_id = ? AND pref_key IN ('theme','font_size')");
    $_p->execute([$currentUser['id']]); $_prefs = [];
    foreach ($_p->fetchAll() as $_r) $_prefs[$_r['pref_key']] = json_decode($_r['pref_value'], true);
} catch (Throwable $e) { $_prefs = []; }
$from = (string)($_GET['from'] ?? '');
if (!preg_match('#^/toolz/[^\s<>"]*$#', $from)) $from = '';
?>
<!DOCTYPE html>
<html lang="es" class="theme-<?= htmlspecialchars((string)($_prefs['theme'] ?? 'dark')) ?> font-<?= htmlspecialchars((string)($_prefs['font_size'] ?? 'medium')) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Soporte · <?= htmlspecialchars((string)g7_site('short')) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css" integrity="sha384-h/hnnw1Bi4nbpD6kE7nYfCXzovi622sY5WBxww8ARKwpdLj5kUWjRuyiXaD1U2JT" crossorigin="anonymous">
<link rel="stylesheet" href="/toolz/g7-theme.css?v=spc2">
<script src="/toolz/assets/g7-imgprep.js?v=1"></script>
<style>
*{box-sizing:border-box}body{margin:0;background:var(--g7-bg);color:var(--g7-text);font-family:var(--g7-font)}
.sp{max-width:1120px;margin:0 auto;padding:28px 24px 72px}
.sp-head h1{margin:0;font-size:28px;font-weight:800;letter-spacing:-.03em}.sp-head h1::after{content:".";color:var(--g7-accent)}
.sp-head p{margin:6px 0 22px;color:var(--g7-text-4);font-size:14px;max-width:680px}
.sp-grid{display:grid;grid-template-columns:minmax(0,1.05fr) minmax(0,1fr);gap:20px;align-items:start}
.sp-card{background:var(--g7-bg-3);border:1px solid var(--g7-border-2);border-radius:14px;padding:20px}
.sp-card h2{margin:0 0 14px;font-size:16px;font-weight:800}
.sp-types{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:16px}
.sp-type{position:relative;border:1px solid var(--g7-border-3);background:var(--g7-bg-4);border-radius:12px;padding:12px;cursor:pointer;text-align:left;color:var(--g7-text);font:inherit}
.sp-type i{font-size:18px;color:var(--g7-text-4);display:block;margin-bottom:6px}.sp-type b{display:block;font-size:13.5px}.sp-type span{display:block;font-size:11.5px;color:var(--g7-text-4);margin-top:2px;line-height:1.35}
.sp-type.on{border-color:var(--g7-accent);background:color-mix(in srgb,var(--g7-accent) 9%,var(--g7-bg-4))}.sp-type.on i{color:var(--g7-accent)}
.sp-f{margin-bottom:12px}.sp-f label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--g7-text-4);margin-bottom:5px}
.sp-in{width:100%;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:9px;padding:10px 12px;font:14px var(--g7-font)}
.sp-in:focus{outline:0;border-color:var(--g7-accent)}textarea.sp-in{resize:vertical;min-height:120px;line-height:1.5}
.sp-drop{border:1.5px dashed var(--g7-border-3);border-radius:10px;padding:12px;text-align:center;font-size:13px;color:var(--g7-text-4);cursor:pointer}.sp-drop:hover,.sp-drop.over{border-color:var(--g7-accent);color:var(--g7-text)}
.sp-shots{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}.sp-shot{position:relative}.sp-shot img{width:92px;height:64px;object-fit:cover;border-radius:8px;border:1px solid var(--g7-border-3)}
.sp-shot button{position:absolute;top:-6px;right:-6px;width:20px;height:20px;border-radius:50%;border:0;background:#ef4444;color:#fff;font-size:11px;cursor:pointer}
.sp-chk{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--g7-text-3);margin-bottom:14px;cursor:pointer}.sp-chk input{accent-color:var(--g7-accent)}
.sp-btn{background:var(--g7-accent);color:#000;border:0;border-radius:10px;padding:11px 18px;font:700 14px var(--g7-font);cursor:pointer;width:100%}.sp-btn:disabled{opacity:.5;cursor:default}
.sp-err{color:#ef4444;font-size:13px;margin-top:8px;min-height:1em}
.sp-ok{text-align:center;padding:24px 10px}.sp-ok i{font-size:34px;color:#22c55e}.sp-ok b{display:block;font-size:17px;margin:10px 0 4px}.sp-ok p{color:var(--g7-text-4);font-size:13px;margin:0 0 14px}
.sp-t{border-top:1px solid var(--g7-border);padding:12px 0;cursor:pointer}.sp-t:first-of-type{border-top:0}
.sp-t-h{display:flex;justify-content:space-between;gap:10px;align-items:flex-start}.sp-t-h b{font-size:14px}.sp-t-h small{display:block;color:var(--g7-text-4);font-size:12px;margin-top:2px}
.sp-st{flex-shrink:0;font-size:11px;font-weight:700;padding:2px 9px;border-radius:20px;background:var(--g7-bg-5);color:var(--g7-text-3)}
.sp-st.nuevo{color:var(--g7-accent);background:color-mix(in srgb,var(--g7-accent) 14%,transparent)}.sp-st.esperando{color:#f59e0b;background:rgba(245,158,11,.14)}.sp-st.resuelto,.sp-st.cerrado{color:#22c55e;background:rgba(34,197,94,.12)}
.sp-conv{margin-top:10px;cursor:default}.sp-msg{border-radius:10px;padding:9px 12px;margin-top:8px;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);font-size:13.5px;white-space:pre-wrap;line-height:1.5}
.sp-msg.cronos{background:color-mix(in srgb,var(--g7-accent) 8%,var(--g7-bg-4));border-color:color-mix(in srgb,var(--g7-accent) 35%,transparent)}.sp-msg small{display:block;font-size:11px;color:var(--g7-text-4);margin-bottom:3px;white-space:normal}
.sp-empty{color:var(--g7-text-4);font-size:13.5px;padding:18px 0;text-align:center}
@media(max-width:860px){.sp-grid{grid-template-columns:1fr}.sp{padding:20px 14px 60px}.sp-types{grid-template-columns:1fr}}
</style>
</head>
<body>
<?php include __DIR__ . '/../topbar.php'; ?>
<main class="sp">
  <header class="sp-head"><h1>Soporte</h1><p>¿Algo no anda o se te ocurre una mejora? Cuéntanos acá: le llega directo al equipo de Cronos y la respuesta aparece en esta página. Lo usamos para corregir errores y decidir qué sumar en las próximas versiones.</p></header>
  <div class="sp-grid">
    <section class="sp-card" id="sp-form-card">
      <h2>Nuevo reporte</h2>
      <form id="sp-form" novalidate>
        <div class="sp-types" role="radiogroup" aria-label="Tipo">
          <button type="button" class="sp-type on" data-type="problema" role="radio" aria-checked="true"><i class="fa-solid fa-bug"></i><b>Algo no anda</b><span>Un error, algo que no carga o se ve mal</span></button>
          <button type="button" class="sp-type" data-type="mejora" role="radio" aria-checked="false"><i class="fa-regular fa-lightbulb"></i><b>Sugerencia</b><span>Una mejora o algo que te gustaría tener</span></button>
          <button type="button" class="sp-type" data-type="plan" role="radio" aria-checked="false"><i class="fa-solid fa-id-card"></i><b>Mi plan</b><span>Cambiar de plan, sumar usuarios o espacio</span></button>
        </div>
        <div class="sp-f"><label for="sp-title">Resumen</label><input class="sp-in" id="sp-title" maxlength="150" placeholder="Ej: No puedo subir archivos en Materiales" required></div>
        <div class="sp-f"><label for="sp-body" id="sp-body-l">¿Qué pasó? Cuéntanos los pasos</label><textarea class="sp-in" id="sp-body" maxlength="6000" placeholder="1. Entré a… 2. Toqué… 3. Apareció…"></textarea></div>
        <div class="sp-f" id="sp-url-w"><label for="sp-url">¿En qué página?</label><input class="sp-in" id="sp-url" maxlength="300" value="<?= htmlspecialchars($from) ?>" placeholder="/toolz/…"></div>
        <div class="sp-f"><label>Capturas de pantalla (opcional, hasta 3)</label><div class="sp-drop" id="sp-drop" tabindex="0"><i class="fa-regular fa-image"></i> Elige, arrastra o pega (Ctrl+V) una imagen<input type="file" id="sp-file" accept="image/*" multiple hidden></div><div class="sp-shots" id="sp-shots"></div></div>
        <label class="sp-chk" id="sp-block-w"><input type="checkbox" id="sp-block"> No puedo seguir trabajando por esto</label>
        <button class="sp-btn" type="submit" id="sp-send"><i class="fa-regular fa-paper-plane"></i> Enviar</button>
        <div class="sp-err" id="sp-err" role="alert"></div>
      </form>
    </section>
    <section class="sp-card"><h2>Mis reportes</h2><div id="sp-list"><div class="sp-empty"><i class="fa-solid fa-spinner fa-spin"></i> Cargando…</div></div></section>
  </div>
</main>
<script src="soporte.js?v=<?= (int)@filemtime(__DIR__ . '/soporte.js') ?>"></script>
</body>
</html>
