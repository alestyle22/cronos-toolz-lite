<?php
error_reporting(0); ini_set('display_errors', 0);
require_once '../auth.php';
require_once __DIR__ . '/bundle_lib.php';      // lg_pub_dir() / lg_pub_base() / lg_beacon(): dónde se publica
require_once __DIR__ . '/templates_lib.php';
$currentUser = requireLogin();
require_once __DIR__ . '/../csp_lib.php'; g7_csp_enforce();   // CSP estricta (SEC-24 fase 2): sin código en línea; la vista previa corre aislada en frame.php
$db = getDB();
$id = (int)($_GET['id'] ?? 0);
$landing = null;
if ($id) {
    $stmt = $db->prepare("SELECT * FROM g7_landings WHERE id=?");
    $stmt->execute([$id]);
    $landing = $stmt->fetch();
}
// Marca: una landing de una marca ajena no se puede abrir ni modificar
if ($landing && !g7_brand_allows_text((int)$currentUser['id'], (string)$landing['platform'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { header('Content-Type: application/json'); echo json_encode(['error' => 'Esa landing es de una marca a la que no tienes acceso']); exit; }
    header('Location: index.php'); exit;
}
// Las landings subidas tal cual (carpeta o ZIP) no pasan por el editor: se reemplazan desde la lista
$lgMeta = $landing ? json_decode((string)($landing['content_raw'] ?? ''), true) : null;
if (is_array($lgMeta) && ($lgMeta['type'] ?? '') === 'bundle') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { header('Content-Type: application/json'); echo json_encode(['error' => 'Esta landing se subió armada: para cambiarla usa Reemplazar en la lista']); exit; }
    header('Location: index.php'); exit;
}
// Agregar columnas si no existen (idempotente)
try { $db->exec("ALTER TABLE g7_landings ADD COLUMN public_url VARCHAR(600) DEFAULT NULL"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE g7_landings ADD COLUMN published_at DATETIME DEFAULT NULL"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE g7_landings ADD COLUMN editing_by VARCHAR(100) DEFAULT NULL"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE g7_landings ADD COLUMN editing_since DATETIME DEFAULT NULL"); } catch(Exception $e) {}

/** Guarda el HTML editado. content_raw puede ser JSON o el texto crudo de la promo: se conserva y se le suma heroImg
    (antes un JSON_SET sobre texto crudo lo dejaba en NULL o hacía fallar el guardado según el motor de la base). */
function lgSaveHtml(PDO $db, array $landing, int $id, string $html, string $heroImg): void {
    $raw = (string)($landing['content_raw'] ?? '');
    $meta = json_decode($raw, true);
    if (!is_array($meta)) $meta = $raw !== '' ? ['raw' => $raw] : [];
    $meta['heroImg'] = $heroImg;
    $db->prepare("UPDATE g7_landings SET html_content=?, content_raw=?, updated_at=NOW() WHERE id=?")
       ->execute([$html, json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $id]);
}
function lgFail(Throwable $e): void {
    $m = $e->getMessage();
    if (stripos($m, 'Incorrect string value') !== false) $m = 'La base no acepta algunos caracteres del texto (por ejemplo emojis). Sacalos y guarda de nuevo.';
    elseif (stripos($m, 'max_allowed_packet') !== false || stripos($m, 'too large') !== false) $m = 'La landing pesa demasiado para guardarse (¿imágenes pegadas dentro del HTML?). Sube las imágenes con el selector.';
    else $m = 'No se pudo guardar: ' . mb_substr($m, 0, 200);
    echo json_encode(['error' => $m]); exit;
}

function landingSlug(string $name, int $id, string $platform = ''): string {
    $s = mb_strtolower($name, 'UTF-8');
    $s = str_replace(['á','é','í','ó','ú','ñ','ü'], ['a','e','i','o','u','n','u'], $s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return lg_platform_info($platform)['slug'] . '-' . trim($s, '-') . '-' . $id;
}

// Imágenes de hero subidas desde el editor: en la carpeta pública del dominio, bajo la ruta de Toolz
function lgHeroesDir(): string { return lg_pub_root() . lg_toolz_path() . '/landing-gen/heroes'; }
function lgHeroesUrl(): string { return lg_pub_origin() . lg_toolz_path() . '/landing-gen/heroes/'; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $d = json_decode(file_get_contents('php://input'), true) ?? [];

    if (($d['action'] ?? '') === 'save' && $id) {
        $html    = $d['html']    ?? '';
        $heroImg = $d['heroImg'] ?? '';
        if ($html) {
            try { lgSaveHtml($db, $landing, $id, $html, $heroImg); } catch (Throwable $e) { lgFail($e); }
            echo json_encode(['ok' => true]);
        } else { echo json_encode(['error' => 'La landing llegó vacía: recarga el editor y prueba de nuevo']); }
        exit;
    }

    if (($d['action'] ?? '') === 'publish' && $id) {
        $html    = $d['html']    ?? '';
        $heroImg = $d['heroImg'] ?? '';
        if (!$html) { echo json_encode(['error' => 'Sin contenido']); exit; }

        // Guardar en DB
        try { lgSaveHtml($db, $landing, $id, $html, $heroImg); } catch (Throwable $e) { lgFail($e); }

        // Escribir archivo público
        $slug    = landingSlug($landing['name'] ?? 'landing', $id, (string)($landing['platform'] ?? ''));
        $pubDir  = lg_pub_dir();
        $pubFile = $pubDir . '/' . $slug . '.html';
        $pubUrl  = lg_pub_base() . $slug . '.html';

        if (!is_dir($pubDir)) mkdir($pubDir, 0755, true);

        // Seguimiento de visitas en el HTML publicado (bundle_lib.php: cuenta en track.php del mismo dominio)
        $trackScript = lg_beacon($id);
        $publishHtml = strpos($html, '</body>') !== false
            ? str_replace('</body>', $trackScript . '</body>', $html)
            : $html . $trackScript;
        $publishHtml = lg_cta_new_tab($publishHtml);   // el botón de la promo abre en una pestaña nueva
        $ok = file_put_contents($pubFile, $publishHtml) !== false;

        if ($ok) {
            $db->prepare("UPDATE g7_landings SET public_url=?,published_at=NOW() WHERE id=?")->execute([$pubUrl, $id]);
            echo json_encode(['ok' => true, 'url' => $pubUrl]);
        } else {
            echo json_encode(['error' => 'No se pudo escribir el archivo']);
        }
        exit;
    }

    if (($d['action'] ?? '') === 'upload_hero') {
        $dataUrl = $d['dataUrl'] ?? '';
        if (!preg_match('/^data:image\/(jpeg|jpg|png|webp|gif);base64,/i', $dataUrl, $m)) {
            echo json_encode(['error' => 'Formato inválido']); exit;
        }
        $ext = strtolower($m[1]) === 'jpeg' ? 'jpg' : strtolower($m[1]);
        $dir = lgHeroesDir();
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $fname = 'hero-' . time() . '-' . rand(100,999) . '.' . $ext;
        $data  = base64_decode(substr($dataUrl, strpos($dataUrl, ',')+1));
        if (!$data || file_put_contents($dir.'/'.$fname, $data) === false) {
            echo json_encode(['error' => 'No se pudo guardar la imagen']); exit;
        }
        echo json_encode(['ok'=>true, 'url'=>lgHeroesUrl().$fname]);
        exit;
    }

    if (($d['action'] ?? '') === 'list_heroes') {
        $dir = lgHeroesDir();
        $images = [];
        if (is_dir($dir)) {
            foreach (glob($dir.'/*.{jpg,jpeg,png,webp,gif}', GLOB_BRACE) ?: [] as $f) {
                $images[] = ['url'=>lgHeroesUrl().basename($f), 'time'=>filemtime($f)];
            }
            usort($images, fn($a,$b) => $b['time'] - $a['time']);
        }
        echo json_encode(['ok'=>true, 'images'=>$images]);
        exit;
    }

    if (($d['action'] ?? '') === 'lock_claim' && $id) {
        $me = $currentUser['display_name'] ?: $currentUser['username'];
        $db->prepare("UPDATE g7_landings SET editing_by=?, editing_since=NOW() WHERE id=?")->execute([$me, $id]);
        echo json_encode(['ok'=>true]); exit;
    }
    if (($d['action'] ?? '') === 'lock_heartbeat' && $id) {
        $me = $currentUser['display_name'] ?: $currentUser['username'];
        $db->prepare("UPDATE g7_landings SET editing_since=NOW() WHERE id=? AND editing_by=?")->execute([$id, $me]);
        echo json_encode(['ok'=>true]); exit;
    }
    if (($d['action'] ?? '') === 'lock_release' && $id) {
        $me = $currentUser['display_name'] ?: $currentUser['username'];
        $db->prepare("UPDATE g7_landings SET editing_by=NULL, editing_since=NULL WHERE id=? AND editing_by=?")->execute([$id, $me]);
        echo json_encode(['ok'=>true]); exit;
    }

    echo json_encode(['error' => 'unknown']); exit;
}
if (!$landing) { header('Location: index.php'); exit; }
// Solo plataformas que el editor entiende (las del paquete pueden marcar 'editor' => false)
if (!lg_platform_editable((string)($landing['platform'] ?? ''))) { header('Location: index.php'); exit; }
$kind = lg_template_kind((string)$landing['platform']);
$isTx = ($kind === 'textos');   // plantilla de textos del paquete de marcas

// ── Chequeo de lock de edición ──────────────────────────────────────────────
$myName = $currentUser['display_name'] ?: $currentUser['username'];
if (!empty($landing['editing_by']) && $landing['editing_by'] !== $myName) {
    $editingSince = $landing['editing_since'] ? strtotime($landing['editing_since']) : 0;
    if ((time() - $editingSince) < 120) {
        $editor = htmlspecialchars($landing['editing_by']);
        header('Location: index.php?locked_by=' . urlencode($editor));
        exit;
    }
}
// Reclamar el lock
$db->prepare("UPDATE g7_landings SET editing_by=?, editing_since=NOW() WHERE id=?")->execute([$myName, $id]);

$src = $landing['html_content'] ?? '<html><body style="background:#111;color:#fff;padding:20px"><p>Sin contenido.</p></body></html>';
$inject = <<<'EDJS'
<style data-editor>
.ed-h{outline:2px dashed rgba(255,102,0,.55)!important;border-radius:3px!important;cursor:text!important;outline-offset:2px}
.ed-a{outline:2px solid #ff6600!important;border-radius:3px!important;outline-offset:2px;background:rgba(255,102,0,.05)!important}
.ed-tip{position:fixed;bottom:14px;left:50%;transform:translateX(-50%);background:rgba(18,18,18,.97);color:#ff6600;font-size:11px;font-weight:700;padding:5px 14px;border-radius:20px;pointer-events:none;opacity:0;transition:opacity .2s;z-index:9999;font-family:sans-serif;border:1px solid rgba(255,102,0,.3);white-space:nowrap}
.ed-tip.v{opacity:1}
*{-webkit-user-select:text;user-select:text}
#ed-fmt{position:fixed;display:none;flex-direction:column;background:#1c1c1c;border:1px solid rgba(255,102,0,.45);border-radius:9px;z-index:9998;box-shadow:0 6px 20px rgba(0,0,0,.65);overflow:hidden}
#ed-fmt-btns{display:flex;align-items:center;gap:1px;padding:3px}
#ed-fmt button{background:transparent;border:none;color:#ccc;font-family:-apple-system,sans-serif;width:30px;height:26px;border-radius:5px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .1s,color .1s}
#ed-fmt button:hover{background:rgba(255,102,0,.2);color:#ff6600}
.ef-b{font-size:14px;font-weight:900}
.ef-i{font-size:14px;font-style:italic;font-weight:700}
.ef-u{font-size:13px;text-decoration:underline;font-weight:700}
.ef-sep{width:1px;height:16px;background:rgba(255,255,255,.12);flex-shrink:0;margin:0 2px}
#ed-fmt-lr{display:none;align-items:center;gap:4px;padding:4px 5px;border-top:1px solid rgba(255,255,255,.07)}
#ed-fmt-inp{background:#111;border:1px solid rgba(255,255,255,.13);border-radius:5px;color:#e4e4e7;font-size:11px;padding:4px 8px;outline:none;width:165px;font-family:sans-serif}
#ed-fmt-inp:focus{border-color:rgba(255,102,0,.4)}
#ed-fmt-ok{font-size:10px!important;font-weight:800!important;width:auto!important;padding:0 9px!important;color:#ff6600!important;letter-spacing:.04em}
</style>
<script data-editor>
(function(){
  if(window.lucide)lucide.createIcons();

  /* ── elementos que NUNCA deben ser editables ── */
  var SKIP={HTML:1,BODY:1,HEAD:1,SCRIPT:1,STYLE:1,IFRAME:1,SVG:1,PATH:1,CIRCLE:1,G:1,DEFS:1,USE:1,SYMBOL:1,POLYLINE:1,LINE:1,RECT:1,IMG:1,INPUT:1,SELECT:1,TEXTAREA:1,BUTTON:1,BR:1,HR:1,DETAILS:1,SUMMARY:1};

  /* ── tooltip ── */
  var tip=document.createElement("div");tip.className="ed-tip";document.body.appendChild(tip);
  var curEl=null,hEl=null;

  /* ── encuentra el mejor elemento editable al clickear ── */
  function findT(el){
    while(el&&el!==document.body){
      if(SKIP[el.tagName])return null;
      /* dentro de un summary solo se edita el título marcado (.acc-title, plantilla de textos) */
      if(el.closest("summary")&&!el.closest(".acc-title"))return null;
      if(el.closest("[data-editor]"))return null;
      /* tiene texto directo (nodo de texto hijo) */
      var ok=false;
      for(var i=0;i<el.childNodes.length;i++){
        if(el.childNodes[i].nodeType===3&&el.childNodes[i].textContent.trim()){ok=true;break;}
      }
      if(ok)return el;
      el=el.parentElement;
    }
    return null;
  }

  /* ── obtiene selector + índice para postMessage ── */
  function getInfo(el){
    var known=[".cta",".benefit-value",".benefit-label",".promo-narrative",".summary-date-value",
               ".section-title",".hero-title",".card-text",".benefit-title",".tyc-content",
               ".hero-t1",".hero-t2",".hero-sub",".acc-title"];
    var cls=typeof el.className==="string"?el.className.trim().split(/\s+/):[];
    for(var i=0;i<cls.length;i++){
      if(cls[i]&&known.indexOf("."+cls[i])!==-1)
        return {sel:"."+cls[i],i:Array.from(document.querySelectorAll("."+cls[i])).indexOf(el)};
    }
    var tag=el.tagName.toLowerCase();
    return {sel:tag,i:Array.from(document.querySelectorAll(tag)).indexOf(el)};
  }

  /* ── hover ── */
  document.addEventListener("mouseover",function(e){
    var t=findT(e.target);
    if(t===hEl)return;
    if(hEl&&hEl!==curEl)hEl.classList.remove("ed-h");
    hEl=t;
    if(t&&t!==curEl)t.classList.add("ed-h");
  });

  /* ── click = activar edición ── */
  document.addEventListener("mousedown",function(e){
    var t=findT(e.target);
    /* desactivar el anterior */
    if(curEl&&curEl!==t){
      curEl.removeAttribute("contenteditable");curEl.removeAttribute("spellcheck");
      curEl.classList.remove("ed-a","ed-h");curEl=null;
      tip.classList.remove("v");
    }
    if(!t)return;
    /* prevenir navegación en links */
    if(t.tagName==="A"||t.closest("a"))e.preventDefault();
    if(t===curEl)return;
    t.classList.remove("ed-h");t.classList.add("ed-a");
    t.contentEditable="true";t.spellcheck=false;curEl=t;
    var s=getInfo(t);
    var label=cls2label(t);
    tip.textContent="✎ "+label;tip.classList.add("v");
    /* poner cursor al final */
    setTimeout(function(){
      try{var r=document.createRange();r.selectNodeContents(t);r.collapse(false);
        var sel=window.getSelection();sel.removeAllRanges();sel.addRange(r);t.focus();}catch(e){}
    },0);
    parent.postMessage({t:"focus",sel:s.sel,i:s.i},"*");
    /* rastrear cambios */
    if(!t._ei){t._ei=true;t.addEventListener("input",function(){
      var s=getInfo(t);parent.postMessage({t:"change",sel:s.sel,i:s.i,text:t.innerText,raw:t.textContent,html:t.innerHTML},"*");
    });}
  },true);

  document.addEventListener("click",function(e){if(e.target.closest("a")&&curEl)e.preventDefault();},true);

  /* título de un desplegable: el click edita, no abre ni cierra (eso queda para la flecha) */
  document.addEventListener("click",function(e){
    if(e.target.closest&&e.target.closest(".acc-title")){e.preventDefault();e.stopPropagation();}
  },true);
  document.addEventListener("keydown",function(e){
    if(e.target.closest&&e.target.closest(".acc-title")){
      if(e.key==="Enter")e.preventDefault();
      e.stopPropagation();
    }
  },true);
  document.addEventListener("keyup",function(e){
    if(e.target.closest&&e.target.closest(".acc-title")&&e.key===" ")e.preventDefault();
  },true);

  function cls2label(el){
    var c=typeof el.className==="string"&&el.className.trim()?"."+el.className.trim().split(/\s+/)[0]:"";
    return c||el.tagName.toLowerCase();
  }

  /* ── acordeones: dejar el comportamiento nativo de <details> ──
     No agregar handler manual: causa double-toggle y rompe la apertura */

  /* ── datos iniciales ── */
  function sendInit(){
    var bens=[];
    document.querySelectorAll(".benefit-badge").forEach(function(b){
      var ie=b.querySelector("[data-lucide]"),svg=b.querySelector("svg");
      bens.push({icon:ie?ie.getAttribute("data-lucide"):(svg?svg.getAttribute("data-icon")||"flame":"flame"),
        value:(b.querySelector(".benefit-value")||{}).innerText||"",
        label:(b.querySelector(".benefit-label")||{}).innerText||""});
    });
    var cta=document.querySelector(".cta");
    var arte=document.querySelector(".hero-art img"),bg=document.querySelector(".ambiente .bg");
    var bgUrl=bg&&bg.style.backgroundImage?bg.style.backgroundImage.replace(/^url\(["']?|["']?\)$/g,""):"";
    parent.postMessage({t:"init",
      t1:(document.querySelector(".hero-t1")||{}).textContent||"",   /* textContent: innerText trae las mayúsculas del CSS */
      t2:(document.querySelector(".hero-t2")||{}).textContent||"",
      sub:(document.querySelector(".hero-sub")||{}).innerHTML||"",
      arte:arte?arte.getAttribute("src")||"":"",
      fondo:bgUrl,
      bodyClass:document.body.className||"",
      title:(document.querySelector("h1")||{}).innerText||"",
      ctaText:cta?(cta.textContent||"").trim():"",
      ctaUrl:cta?cta.getAttribute("href")||"":"",
      vigencia:(document.querySelector(".summary-date-value")||{}).innerText||"",
      narrative:(document.querySelector(".promo-narrative")||{}).innerHTML||"",
      heroImg:"",benefits:bens},"*");
  }
  if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",function(){setTimeout(sendInit,400);});
  else setTimeout(sendInit,400);

  /* ── mensajes del padre ── */
  window.addEventListener("message",function(e){
    var d=e.data;if(!d||!d.t)return;

    if(d.t==="getHTML"){
      tip.classList.remove("v");
      if(curEl){curEl.removeAttribute("contenteditable");curEl.removeAttribute("spellcheck");curEl.classList.remove("ed-a","ed-h");curEl=null;}
      var c=document.documentElement.cloneNode(true);
      c.querySelectorAll("[contenteditable]").forEach(function(el){el.removeAttribute("contenteditable");el.removeAttribute("spellcheck");el.classList.remove("ed-a","ed-h");});
      c.querySelectorAll("[data-editor]").forEach(function(el){el.remove();});
      c.querySelectorAll(".ed-tip").forEach(function(el){el.remove();});
      /* plantilla de textos: lo que sus animaciones dejan en el DOM no se guarda */
      c.querySelectorAll(".tyc details").forEach(function(el){el.classList.remove("visible");el.removeAttribute("open");el.removeAttribute("data-animando");if(!el.className)el.removeAttribute("class");});
      c.querySelectorAll(".tyc .contenido").forEach(function(el){el.style.height="";if(!el.getAttribute("style"))el.removeAttribute("style");});
      var tl=c.querySelector("#tilt");if(tl)tl.removeAttribute("style");
      var cv=c.querySelector("canvas#brasas");if(cv){cv.removeAttribute("width");cv.removeAttribute("height");}
      parent.postMessage({t:"html",html:"<!DOCTYPE html>\n"+c.outerHTML},"*");
    }

    else if(d.t==="set"){
      var els=document.querySelectorAll(d.sel);
      if(els[d.i]&&document.activeElement!==els[d.i]){if(d.isHtml)els[d.i].innerHTML=d.val;else els[d.i].innerText=d.val;}
    }

    else if(d.t==="setIcon"){
      var badges=document.querySelectorAll(".benefit-badge");var b=badges[d.i];if(!b)return;
      var old=b.querySelector("[data-lucide],svg");
      if(old){var ni=document.createElement("i");ni.setAttribute("data-lucide",d.icon);old.replaceWith(ni);if(window.lucide)lucide.createIcons({nodes:[b]});}
    }

    else if(d.t==="setHero"&&document.querySelector(".hero-art img")){
      /* plantilla de textos: el "hero" es el arte de la promo, una imagen recortada */
      document.querySelector(".hero-art img").setAttribute("src",d.url||"");
    }

    else if(d.t==="setFx"){
      /* efectos de la plantilla de textos: los murciélagos se prenden, el resto se apaga */
      if(d.k==="murcielagos")document.body.classList.toggle("fx-murcielagos",!!d.on);
      else document.body.classList.toggle("fx-no-"+d.k,!d.on);
      if(!document.body.className)document.body.removeAttribute("class");
    }

    else if(d.t==="setBg"){
      var bgEl=document.querySelector(".ambiente .bg");if(!bgEl)return;
      bgEl.style.backgroundImage=d.url?"url("+JSON.stringify(d.url)+")":"";
      if(!bgEl.getAttribute("style"))bgEl.removeAttribute("style");
    }

    else if(d.t==="setHero"){
      var hero=document.querySelector(".hero");if(!hero)return;
      if(d.url){
        /* 1. background-image en .hero */
        hero.style.backgroundImage="url("+JSON.stringify(d.url)+")";
        hero.style.backgroundSize="cover";
        hero.style.backgroundPosition="center";
        /* 2. si ya hay una img existente, actualizar src también */
        var img=hero.querySelector("img");if(img)img.src=d.url;
        /* 3. ocultar placeholders */
        hero.querySelectorAll("[class*=placeholder],[class*=Placeholder],[class*=bg]").forEach(function(ph){ph.style.opacity="0.08";});
      } else {
        hero.style.backgroundImage="";
        hero.querySelectorAll("[class*=placeholder],[class*=Placeholder]").forEach(function(ph){ph.style.opacity="";});
      }
    }

    else if(d.t==="setCTA"){
      var cta=document.querySelector(".cta");if(!cta)return;
      if(d.href!=null)cta.href=d.href||"#";
      if(d.text!=null&&document.activeElement!==cta)cta.innerText=d.text;
      if(d.bg)cta.style.background=d.bg;
      if(d.color)cta.style.color=d.color;
    }
    else if(d.t==="addBenefit"){
      var row=document.querySelector(".benefits-row");if(!row)return;
      var badge=document.createElement("div");badge.className="benefit-badge";
      badge.innerHTML='<i data-lucide="flame"></i><span class="benefit-value"> Valor</span><span class="benefit-label"> Descripcion</span>';
      row.appendChild(badge);if(window.lucide)lucide.createIcons({nodes:[badge]});
    }
    else if(d.t==="removeBenefit"){
      var bds=document.querySelectorAll(".benefit-badge");
      if(d.i>=0&&bds[d.i])bds[d.i].remove();
    }
  });

  /* ── Barra de formato flotante ── */
  var fmt=document.createElement("div");fmt.id="ed-fmt";fmt.setAttribute("data-editor","");
  fmt.innerHTML='<div id="ed-fmt-btns">'
    +'<button onclick="edFmt(\'bold\')" title="Negrita"><span class="ef-b">B</span></button>'
    +'<button onclick="edFmt(\'italic\')" title="Itálica"><span class="ef-i">I</span></button>'
    +'<button onclick="edFmt(\'underline\')" title="Subrayado"><span class="ef-u">U</span></button>'
    +'<span class="ef-sep"></span>'
    +'<button onclick="edFmtLink()" title="Hipervínculo"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="pointer-events:none"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg></button>'
    +'</div>'
    +'<div id="ed-fmt-lr"><input type="url" id="ed-fmt-inp" placeholder="https://"'
    +' onkeydown="if(event.key===\'Enter\'){edFmtApply();}if(event.key===\'Escape\'){edFmtHideLr();}">'
    +'<button id="ed-fmt-ok" onclick="edFmtApply()">OK</button></div>';
  document.body.appendChild(fmt);

  /* prevent selection loss when clicking toolbar buttons */
  fmt.addEventListener("mousedown",function(e){e.preventDefault();});

  var _savedRange=null;
  function edFmt(cmd){document.execCommand(cmd,false,null);}
  function edFmtLink(){
    var lr=document.getElementById("ed-fmt-lr");
    if(lr.style.display==="flex"){edFmtHideLr();return;}
    var sel=window.getSelection();
    _savedRange=sel&&!sel.isCollapsed?sel.getRangeAt(0):null;
    lr.style.display="flex";
    var inp=document.getElementById("ed-fmt-inp");
    /* pre-fill URL if selection is inside a link */
    var a=sel&&sel.anchorNode;
    while(a&&a.tagName!=="A"&&a!==document.body)a=a.parentElement;
    inp.value=a&&a.tagName==="A"?a.href:"https://";
    setTimeout(function(){inp.focus();inp.select();},0);
  }
  function edFmtApply(){
    var url=document.getElementById("ed-fmt-inp").value.trim();
    if(_savedRange){var sel=window.getSelection();sel.removeAllRanges();sel.addRange(_savedRange);}
    if(url&&url!=="https://")document.execCommand("createLink",false,url);
    edFmtHideLr();
  }
  function edFmtHideLr(){document.getElementById("ed-fmt-lr").style.display="none";}
  function hideFmt(){fmt.style.display="none";edFmtHideLr();}

  document.addEventListener("mouseup",function(e){
    if(e.target.closest("#ed-fmt"))return;
    setTimeout(function(){
      var sel=window.getSelection();
      if(!sel||sel.isCollapsed||!sel.toString().trim()||!curEl){hideFmt();return;}
      try{
        var r=sel.getRangeAt(0).getBoundingClientRect();
        var x=r.left+r.width/2-67;
        var y=r.top+window.scrollY-52;
        fmt.style.left=Math.max(4,x)+"px";
        fmt.style.top=Math.max(4,y)+"px";
        fmt.style.display="flex";
        fmt.style.flexDirection="column";
      }catch(ex){}
    },10);
  });
  document.addEventListener("mousedown",function(e){
    if(!e.target.closest("#ed-fmt"))hideFmt();
  });
})();
</script>
<script data-editor>
/* ── Opciones de la plantilla de textos: formato del fondo, imagen en vez del título, arte apagado y hero arriba ──
   El CSS va en <style data-lg-opts>, que se guarda con la landing (las viejas lo reciben al abrirse en el editor). */
(function(){
  var bg=document.querySelector(".ambiente .bg"),hero=document.querySelector(".hero");
  if(!bg||!hero)return;
  var CSS=".ambiente .bg::after{content:\"\";position:absolute;inset:0;background:rgba(0,0,0,var(--bg-dim,0));pointer-events:none}\n"
    +".titulo-img{display:none}\n"
    +".lg-titulo-img .titulo-img{display:block;width:var(--ti-w,100%);max-width:100%}\n"
    +".hero-art .tilt{max-width:var(--arte-w,480px)}\n"
    +"@media (max-width:1024px){.lg-titulo-img .titulo-img{width:var(--ti-w-t,var(--ti-w,100%))}.hero-art .tilt{max-width:var(--arte-w-t,var(--arte-w,480px))}}\n"
    +".lg-titulo-sub .titulo-img{margin-bottom:22px}\n"
    +".titulo-img img,.banner-hero img{display:block;width:100%;height:auto}\n"
    +".titulo-img img[src=\"\"],.banner-hero img[src=\"\"]{display:none}\n"
    +".img-vacia{display:flex;align-items:center;justify-content:center;aspect-ratio:3/1;border:2px dashed rgba(255,255,255,.25);border-radius:16px;color:rgba(255,255,255,.5);font-size:13px;font-weight:700;letter-spacing:.08em;text-transform:uppercase}\n"
    +"img:not([src=\"\"])+.img-vacia,picture:has(img:not([src=\"\"]))+.img-vacia{display:none}\n"
    +".lg-titulo-img .hero h1,.lg-titulo-img .hero .cta,.lg-titulo-img:not(.lg-titulo-sub) .hero-sub{display:none}\n"
    +".lg-sin-arte .hero-art{display:none}\n"
    +".lg-sin-arte .hero{grid-template-columns:1fr;text-align:center}\n"
    +".lg-sin-arte .hero-sub,.lg-sin-arte .titulo-img{margin-left:auto;margin-right:auto}\n"
    +".banner-hero{display:block;margin-top:24px;border-radius:18px;overflow:hidden}\n"
    +".banner-hero.ancho-pantalla{margin-top:0;margin-left:calc(50% - 50vw);margin-right:calc(50% - 50vw);border-radius:0}\n"
    +".banner-hero .img-vacia{aspect-ratio:4/1;border-radius:inherit}\n"
    +"@media (max-width:767px){.lg-titulo-img .titulo-img{width:var(--ti-w-m,var(--ti-w-t,var(--ti-w,100%)))}.hero-art .tilt{max-width:var(--arte-w-m,320px)}}\n"
    +"@media (max-width:767px){.titulo-img{margin-left:auto;margin-right:auto}.banner-hero{margin-top:16px;border-radius:12px}.banner-hero .img-vacia{aspect-ratio:2/1}}\n";
  var st=document.querySelector("style[data-lg-opts]");
  if(!st){st=document.createElement("style");st.setAttribute("data-lg-opts","");document.head.appendChild(st);}
  if(st.textContent!==CSS)st.textContent=CSS;

  var B=document.body,col=hero.querySelector(":scope > div:not(.hero-art)");
  function cls(c,on){B.classList.toggle(c,!!on);if(!B.className)B.removeAttribute("class");}
  function ctaHref(){var c=hero.querySelector(".cta");return c?c.getAttribute("href")||"":"";}
  function titulo(){return((hero.querySelector(".hero-t1")||{}).textContent||"")+" "+((hero.querySelector(".hero-t2")||{}).textContent||"");}
  function tit(make){
    var a=document.querySelector(".titulo-img");
    if(!a&&make&&col){a=document.createElement("a");a.className="titulo-img entra";a.style.setProperty("--d",".05s");a.target="_top";
      a.innerHTML='<img src="" alt=""><div class="img-vacia">Imagen del título</div>';col.insertBefore(a,col.firstChild);}
    return a;
  }
  function ban(make){
    var a=document.querySelector(".banner-hero"),m=hero.parentElement;
    if(!a&&make){a=document.createElement("a");a.className="banner-hero entra";a.target="_top";
      a.innerHTML='<picture><img src="" alt=""></picture><div class="img-vacia">Imagen del hero</div>';m.insertBefore(a,m.firstChild);}
    return a;
  }
  function links(){
    var h=ctaHref(),a=tit(false),b=ban(false);
    if(a){if(h)a.setAttribute("href",h);else a.removeAttribute("href");}
    if(b){if(h&&b.hasAttribute("data-link"))b.setAttribute("href",h);else b.removeAttribute("href");}
  }
  /* El formato del fondo vive en data-* de .bg; de ahí salen los estilos */
  var BG_DEF={fit:"cover",pos:"center top",zoom:100,dim:0,blur:0};
  function bgApply(){
    var s=bg.style,f=bg.dataset.fit||"cover";
    s.backgroundSize=f==="cover"?"":f==="width"?"100% auto":f==="contain"?"contain":"auto";
    s.backgroundRepeat=f==="repeat"?"repeat":"";
    s.backgroundPosition=bg.dataset.pos||"";
    var z=+bg.dataset.zoom||100;if(z!==100)s.setProperty("scale",String(z/100));else s.removeProperty("scale");
    var dm=+bg.dataset.dim||0;if(dm)s.setProperty("--bg-dim",String(dm/100));else s.removeProperty("--bg-dim");
    var bl=+bg.dataset.blur||0;s.filter=bl?"blur("+bl+"px)":"";
    if(!bg.getAttribute("style"))bg.removeAttribute("style");
  }
  /* Tamaño por dispositivo: --x (compu), --x-t (tablet), --x-m (celular); sin valor = hereda */
  var DEV=[["d",""],["t","-t"],["m","-m"]];
  function getVars(el,base){var o={};DEV.forEach(function(p){var v=el?parseFloat(el.style.getPropertyValue(base+p[1])):NaN;o[p[0]]=isNaN(v)?null:v;});return o;}
  function setVars(el,base,w,unit){
    DEV.forEach(function(p){var v=w&&w[p[0]];if(v!=null&&v!=="")el.style.setProperty(base+p[1],v+unit);else el.style.removeProperty(base+p[1]);});
    if(!el.getAttribute("style"))el.removeAttribute("style");
  }
  var art=hero.querySelector(".hero-art");
  function state(){
    var a=tit(false),b=ban(false),src=b&&b.querySelector("source");
    return {
      bg:{fit:bg.dataset.fit||BG_DEF.fit,pos:bg.dataset.pos||BG_DEF.pos,zoom:+bg.dataset.zoom||100,dim:+bg.dataset.dim||0,blur:+bg.dataset.blur||0},
      tit:{on:B.classList.contains("lg-titulo-img"),url:a?a.querySelector("img").getAttribute("src")||"":"",w:getVars(a,"--ti-w"),sub:B.classList.contains("lg-titulo-sub")},
      arte:{on:!B.classList.contains("lg-sin-arte"),w:getVars(art,"--arte-w")},
      ban:{on:!!b,url:b?b.querySelector("img").getAttribute("src")||"":"",urlM:src?src.getAttribute("srcset")||"":"",full:!!b&&b.classList.contains("ancho-pantalla"),link:b?b.hasAttribute("data-link"):true}
    };
  }
  setTimeout(function(){parent.postMessage({t:"initOpts",o:state()},"*");},450);

  window.addEventListener("message",function(e){
    var d=e.data;if(!d||!d.t)return;
    if(d.t==="setBgFmt"){
      ["fit","pos","zoom","dim","blur"].forEach(function(k){
        if(d[k]==null||String(d[k])===String(BG_DEF[k]))delete bg.dataset[k];else bg.dataset[k]=d[k];
      });
      bgApply();
    }
    else if(d.t==="setTitulo"){
      cls("lg-titulo-img",d.on);cls("lg-titulo-sub",d.on&&d.sub);
      var a=tit(d.on);if(!a)return;
      if(!d.on){a.remove();return;}
      var img=a.querySelector("img");img.setAttribute("src",d.url||"");img.setAttribute("alt",titulo().trim());
      setVars(a,"--ti-w",d.w,"%");
      links();
    }
    else if(d.t==="setArte"){cls("lg-sin-arte",!d.on);if(art)setVars(art,"--arte-w",d.w,"px");}
    else if(d.t==="setBanner"){
      var b=ban(d.on);if(!b)return;
      if(!d.on){b.remove();return;}
      var pic=b.querySelector("picture"),im=pic.querySelector("img"),so=pic.querySelector("source");
      im.setAttribute("src",d.url||"");im.setAttribute("alt",titulo().trim());
      if(d.urlM){if(!so){so=document.createElement("source");so.setAttribute("media","(max-width: 767px)");pic.insertBefore(so,im);}so.setAttribute("srcset",d.urlM);}
      else if(so)so.remove();
      b.classList.toggle("ancho-pantalla",!!d.full);
      if(d.link)b.setAttribute("data-link","");else b.removeAttribute("data-link");
      links();
    }
    else if(d.t==="setCTA")links();
  });
})();
</script>
EDJS;
if (strpos($src, '</body>') !== false) {
    $iframeSrc = str_replace('</body>', $inject . '</body>', $src);
} else {
    $iframeSrc = $src . $inject;
}
/** Subir / Galería / URL para una imagen del panel. $target va a applyImage(); el input lleva el id dado.
    $onInput son los atributos data-on-input… (assets/g7-on.js) del campo URL: texto fijo, sin código en línea (CSP estricta). */
function lgImgPicker(string $target, string $inputId, string $onInput, string $label = 'O pegar URL'): string {
    $t = htmlspecialchars($target);
    return '<div class="field" style="display:flex;gap:6px">'
        . '<label class="upload-btn" style="flex:1"><i class="fa-solid fa-cloud-arrow-up"></i> Subir imagen'
        . '<input type="file" accept="image/*" data-on-change="uploadHero" data-change-1="this" data-change-2="s:' . $t . '"></label>'
        . '<button class="upload-btn" style="flex:none;width:auto;padding:7px 12px" data-on-click="openGallery" data-click-1="s:' . $t . '" title="Galería de imágenes"><i class="fa-solid fa-images"></i> Galería</button>'
        . '</div><div class="field"><label>' . htmlspecialchars($label) . '</label>'
        . '<input type="url" id="' . htmlspecialchars($inputId) . '" placeholder="https://..." ' . $onInput . '></div>';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Editor — <?= htmlspecialchars($landing['name']) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" integrity="sha384-/o6I2CkkWC//PSjvWC/eYN7l3xM3tJm8ZzVkCOfp//W05QcE3mlGskpoHB6XqI+B" crossorigin="anonymous">
<style>
:root{--bg:#060606;--s1:#0b0b0b;--bd:rgba(255,255,255,.07);--bdo:rgba(255,102,0,.14);--brand:#ff6600;--text:#e4e4e7;--t2:#a1a1aa;--t3:#52525b;--r:8px}
*{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%;overflow:hidden;background:var(--bg);color:var(--text);font-family:'Inter',-apple-system,sans-serif;font-size:13px}
.layout{display:flex;flex-direction:column;height:100vh}

/* ACTION BAR */
.ed-bar{height:46px;background:#080808;border-bottom:1px solid var(--bdo);display:flex;align-items:center;padding:0 14px;gap:6px;flex-shrink:0}
.ed-title{flex:1;font-size:13px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0}
.ed-title em{font-style:normal;font-size:10px;font-weight:600;letter-spacing:1px;color:var(--brand);background:rgba(255,102,0,.1);border:1px solid rgba(255,102,0,.2);padding:2px 7px;border-radius:20px;margin-left:8px}
.sep{width:1px;height:22px;background:rgba(255,255,255,.07);flex-shrink:0;margin:0 2px}
.dev-group{display:flex;background:rgba(255,255,255,.04);border:1px solid var(--bd);border-radius:var(--r);overflow:hidden}
.dev-btn{width:32px;height:28px;display:flex;align-items:center;justify-content:center;border:none;background:transparent;color:var(--t3);font-size:11px;cursor:pointer;transition:.12s;border-right:1px solid var(--bd);font-family:inherit}
.dev-btn:last-child{border-right:none}
.dev-btn:hover{color:var(--t2);background:rgba(255,255,255,.06)}
.dev-btn.active{color:var(--brand);background:rgba(255,102,0,.1)}
.btn{display:inline-flex;align-items:center;gap:5px;padding:5px 11px;border:1px solid var(--bd);border-radius:var(--r);background:rgba(255,255,255,.04);color:var(--t2);font-size:11px;font-weight:600;cursor:pointer;transition:.15s;font-family:inherit;white-space:nowrap}
.btn:hover{background:rgba(255,255,255,.08);color:var(--text)}
.btn.pri{background:linear-gradient(135deg,#ff7b2e,#e85500);color:#000;border:none;font-weight:700;padding:5px 14px}
.btn.pri:hover{box-shadow:0 0 22px rgba(255,102,0,.35);transform:translateY(-1px)}
.btn.ghost{color:var(--t3);border-color:transparent;background:transparent}
.btn.ghost:hover{color:var(--t2);background:rgba(255,255,255,.04)}
.btn:disabled{opacity:.5;pointer-events:none}

/* BODY */
.body{display:flex;flex:1;overflow:hidden;min-height:0}
.btn.active{color:var(--brand);background:rgba(255,102,0,.1);border-color:rgba(255,102,0,.3)}
#code-panel{display:none}

/* CANVAS */
.canvas{flex:1;display:flex;align-items:center;justify-content:center;overflow:auto;background:radial-gradient(ellipse at 50% 40%,#0e0e0e 0%,#060606 80%);padding:28px}
.canvas::-webkit-scrollbar{width:5px;height:5px}
.canvas::-webkit-scrollbar-thumb{background:#1a1a1a;border-radius:3px}

/* MOBILE SHELL */
.shell-mobile{flex-shrink:0;background:#141414;border-radius:52px;padding:14px 10px 28px;position:relative;
  box-shadow:0 0 0 1px #222,0 0 0 3px #141414,0 0 0 4px #1e1e1e,inset 0 0 0 1px rgba(255,255,255,.05),0 50px 120px rgba(0,0,0,.9)}
.shell-mobile .ph-side{position:absolute;background:#1a1a1a;border-radius:3px}
.shell-mobile .v1{left:-3px;top:108px;width:3px;height:28px}
.shell-mobile .v2{left:-3px;top:146px;width:3px;height:44px}
.shell-mobile .v3{left:-3px;top:200px;width:3px;height:44px}
.shell-mobile .pw{right:-3px;top:160px;width:3px;height:64px}
.ph-notch{position:absolute;top:0;left:50%;transform:translateX(-50%);width:116px;height:34px;background:#141414;border-radius:0 0 18px 18px;z-index:10}
.ph-status{height:36px;display:flex;align-items:flex-end;justify-content:space-between;padding:0 22px 8px;pointer-events:none;position:relative;z-index:2}
.ph-time{font-size:12px;font-weight:700;color:#fff;font-family:-apple-system,sans-serif;letter-spacing:-.3px}
.ph-icons{display:flex;align-items:center;gap:5px;color:#fff;font-size:10px}
.ph-screen{background:#010101;border-radius:44px;overflow:hidden;position:relative}
.ph-home{position:absolute;bottom:9px;left:50%;transform:translateX(-50%);width:130px;height:5px;background:rgba(255,255,255,.28);border-radius:3px}

/* TABLET SHELL */
.shell-tablet{flex-shrink:0;background:#131313;border-radius:22px;padding:20px 12px 28px;position:relative;
  box-shadow:0 0 0 1px #252525,0 0 0 3px #131313,inset 0 0 0 1px rgba(255,255,255,.04),0 40px 100px rgba(0,0,0,.8)}
.tb-cam{position:absolute;top:10px;left:50%;transform:translateX(-50%);width:8px;height:8px;border-radius:50%;background:#1e1e1e;box-shadow:inset 0 0 0 2px #171717}
.tb-screen{background:#010101;border-radius:10px;overflow:hidden;position:relative}
.tb-home{position:absolute;bottom:10px;left:50%;transform:translateX(-50%);width:90px;height:4px;background:rgba(255,255,255,.2);border-radius:3px}

/* DESKTOP SHELL */
.shell-desktop{flex-shrink:0;background:#1a1a1a;border-radius:10px 10px 6px 6px;overflow:hidden;
  box-shadow:0 0 0 1px #2a2a2a,0 40px 100px rgba(0,0,0,.8)}
.db-bar{height:38px;background:#1e1e1e;border-bottom:1px solid #2a2a2a;display:flex;align-items:center;padding:0 14px;gap:10px;flex-shrink:0}
.db-dots{display:flex;gap:6px}
.db-dot{width:12px;height:12px;border-radius:50%}
.db-dot.r{background:#ff5f57}.db-dot.y{background:#febc2e}.db-dot.g{background:#28c840}
.db-url{flex:1;background:#141414;border:1px solid #2a2a2a;border-radius:5px;height:22px;display:flex;align-items:center;padding:0 10px;font-size:10px;color:#52525b;font-family:monospace;max-width:480px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;cursor:default}
.db-screen{overflow:hidden;position:relative}

/* SHARED IFRAME */
.preview-iframe{border:none;display:block}

/* SIDEBAR */
.sidebar{width:300px;flex-shrink:0;background:var(--s1);border-right:1px solid var(--bdo);display:flex;flex-direction:column;overflow:hidden}
.sb-head{padding:14px 18px 13px;background:linear-gradient(160deg,#0f0f0f,#0c0800);border-bottom:1px solid var(--bdo);flex-shrink:0}
.sb-head h2{font-size:10px;font-weight:700;letter-spacing:2.5px;text-transform:uppercase;color:rgba(255,102,0,.85)}
.sb-head p{font-size:11px;color:var(--t3);margin-top:3px}
.sb-body{flex:1;overflow-y:auto}
.sb-body::-webkit-scrollbar{width:3px}
.sb-body::-webkit-scrollbar-thumb{background:#1f1f1f;border-radius:3px}
.focus-bar{height:0;overflow:hidden;display:flex;align-items:center;gap:6px;padding:0 18px;font-size:10px;color:rgba(255,102,0,.7);background:rgba(255,102,0,.07);border-bottom:0 solid var(--bdo);transition:height .2s,border .2s;flex-shrink:0}
.focus-bar.show{height:28px;border-bottom-width:1px}
.focus-bar i{font-size:9px}
details.sec{border-bottom:1px solid var(--bdo)}
details.sec>summary{display:flex;align-items:center;gap:9px;padding:11px 18px;cursor:pointer;user-select:none;list-style:none;transition:background .1s}
details.sec>summary::-webkit-details-marker{display:none}
details.sec>summary:hover{background:rgba(255,255,255,.02)}
details.sec.hl>summary{background:rgba(255,102,0,.05)}
.sec-ic{width:15px;text-align:center;color:var(--brand);font-size:11px;flex-shrink:0}
.sec-lbl{flex:1;font-size:10px;font-weight:700;letter-spacing:1.6px;text-transform:uppercase;color:var(--t2)}
.sec-chev{color:var(--t3);font-size:9px;transition:transform .2s;flex-shrink:0}
details.sec[open]>summary .sec-chev{transform:rotate(180deg)}
.sec-body{padding:14px 18px 18px}
.field{margin-bottom:12px}.field:last-child{margin-bottom:0}
.field>label:not(.upload-btn){display:block;font-size:10px;font-weight:600;color:var(--t3);margin-bottom:5px;letter-spacing:.3px}
.field input[type=text],.field input[type=url],.field textarea{width:100%;background:rgba(0,0,0,.45);border:1px solid var(--bd);border-radius:var(--r);color:var(--text);font-size:12px;padding:7px 10px;font-family:inherit;outline:none;transition:border-color .15s,box-shadow .15s;resize:none;-webkit-appearance:none}
.field input:focus,.field textarea:focus{border-color:rgba(255,102,0,.5);box-shadow:0 0 0 3px rgba(255,102,0,.07)}
.field textarea{min-height:68px;line-height:1.6}
.fhint{font-size:10px;color:var(--t3);margin-top:4px;line-height:1.5}
.fhint code{background:rgba(255,255,255,.06);padding:1px 4px;border-radius:3px;font-size:10px}
.row{display:flex;gap:8px}.row .field{flex:1;min-width:0}
.upload-btn{display:flex;align-items:center;gap:7px;padding:7px 12px;border:1px dashed rgba(255,102,0,.35);border-radius:var(--r);background:rgba(255,102,0,.04);color:rgba(255,102,0,.7);font-size:11px;font-weight:600;cursor:pointer;transition:.15s;width:100%}
.upload-btn:hover{border-color:rgba(255,102,0,.6);background:rgba(255,102,0,.08);color:var(--brand)}
.upload-btn input{display:none}
.color-row{display:flex;gap:8px;align-items:center}
.color-row input[type=color]{width:36px;height:30px;padding:2px;border:1px solid var(--bd);border-radius:6px;cursor:pointer;flex-shrink:0;background:transparent}
.color-row input[type=text]{flex:1}
.benefit-card{background:rgba(0,0,0,.3);border:1px solid var(--bd);border-radius:10px;padding:12px;margin-bottom:10px;transition:border-color .15s}
.benefit-card.hl{border-color:rgba(255,102,0,.3)}
.b-num{font-size:10px;font-weight:700;color:var(--t3);letter-spacing:.5px;text-transform:uppercase;margin-bottom:9px}
.icon-grid{display:flex;flex-wrap:wrap;gap:4px;margin-bottom:10px}
.ico{width:28px;height:28px;display:flex;align-items:center;justify-content:center;border:1px solid var(--bd);border-radius:6px;cursor:pointer;background:rgba(255,255,255,.03);color:var(--t3);font-size:11px;transition:.12s}
.ico:hover{border-color:rgba(255,102,0,.4);color:rgba(255,102,0,.7)}
.ico.on{border-color:var(--brand);color:var(--brand);background:rgba(255,102,0,.1)}
.fx-row{display:flex;align-items:center;gap:8px;padding:5px 0;font-size:12px;color:var(--t2);cursor:pointer}
.fx-row input{accent-color:var(--brand);width:14px;height:14px;cursor:pointer}
.field select{width:100%;background:rgba(0,0,0,.45);border:1px solid var(--bd);border-radius:var(--r);color:var(--text);font-size:12px;padding:7px 10px;font-family:inherit;outline:none;cursor:pointer}
.field select:focus{border-color:rgba(255,102,0,.5)}
.rng{display:flex;align-items:center;gap:10px}
.rng input{flex:1;accent-color:var(--brand);cursor:pointer}
.rng output{min-width:42px;text-align:right;font-size:11px;color:var(--t2);font-variant-numeric:tabular-nums}
.seg{display:flex;border:1px solid var(--bd);border-radius:var(--r);overflow:hidden}
.seg button{flex:1;padding:7px 8px;border:none;border-right:1px solid var(--bd);background:transparent;color:var(--t3);font-size:11px;font-weight:600;font-family:inherit;cursor:pointer;transition:.12s}
.seg button:last-child{border-right:none}
.seg button:hover{color:var(--t2);background:rgba(255,255,255,.04)}
.seg button.on{color:var(--brand);background:rgba(255,102,0,.1)}
.pos-grid{display:grid;grid-template-columns:repeat(3,30px);gap:4px}
.pos-grid button{height:22px;border:1px solid var(--bd);border-radius:4px;background:rgba(255,255,255,.03);cursor:pointer;transition:.12s}
.pos-grid button:hover{border-color:rgba(255,102,0,.4)}
.pos-grid button.on{background:var(--brand);border-color:var(--brand)}
[hidden]{display:none!important}
.lbl-dev{display:flex!important;align-items:center;gap:8px}
.devs{display:inline-flex;gap:2px;margin-left:auto}
.devs button{width:22px;height:20px;border:1px solid transparent;border-radius:4px;background:transparent;color:var(--t3);font-size:10px;cursor:pointer;transition:.12s}
.devs button:hover{color:var(--t2)}
.devs button.on{color:var(--brand);border-color:rgba(255,102,0,.35);background:rgba(255,102,0,.1)}
.rng.inh output{color:var(--t3);font-style:italic}
.rsp-x{border:none;background:transparent;color:var(--t3);font-size:10px;cursor:pointer;padding:2px}
.rsp-x:hover{color:var(--brand)}
.empty-b{text-align:center;padding:18px 0;color:var(--t3);font-size:12px}
.empty-b i{display:block;font-size:20px;margin-bottom:7px;color:#222}
/* Publish button */
.pub-btn{background:linear-gradient(135deg,#1a6e2e,#14531f);color:#4ade80;border-color:rgba(74,222,128,.25);font-weight:700}
.pub-btn:hover{box-shadow:0 0 18px rgba(74,222,128,.25);transform:translateY(-1px);color:#86efac}
.pub-btn:disabled{opacity:.5;pointer-events:none}

/* Publish banner */
#pub-banner{display:none;flex-shrink:0;align-items:center;gap:10px;padding:0 16px;height:40px;background:linear-gradient(90deg,rgba(20,53,20,.9),rgba(16,40,16,.9));border-bottom:1px solid rgba(74,222,128,.2)}
#pub-banner.show{display:flex}
#pub-banner .pub-ico{color:#4ade80;font-size:13px;flex-shrink:0}
#pub-banner .pub-lbl{font-size:10px;font-weight:700;color:rgba(74,222,128,.7);letter-spacing:.8px;text-transform:uppercase;flex-shrink:0}
#pub-banner .pub-url{font-size:12px;color:#86efac;font-family:monospace;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0}
#pub-banner .pub-url a{color:inherit;text-decoration:none}
#pub-banner .pub-url a:hover{text-decoration:underline}
#pub-banner .pub-actions{display:flex;gap:6px;flex-shrink:0}
#pub-banner .pb{display:inline-flex;align-items:center;gap:5px;padding:4px 9px;border:1px solid rgba(74,222,128,.25);border-radius:6px;background:rgba(74,222,128,.07);color:#4ade80;font-size:10px;font-weight:700;cursor:pointer;font-family:inherit;transition:.12s}
#pub-banner .pb:hover{background:rgba(74,222,128,.14)}
#pub-banner .pb-close{color:var(--t3);border-color:transparent;background:transparent;font-size:12px}
#pub-banner .pb-close:hover{color:var(--t2)}

/* Toast */
#toast{position:fixed;bottom:22px;left:50%;transform:translateX(-50%) translateY(80px);background:#111;border:1px solid #2a2a2a;color:var(--text);border-radius:10px;padding:8px 18px;font-size:12px;font-weight:600;transition:transform .3s cubic-bezier(.34,1.4,.64,1);z-index:9999;pointer-events:none}
#toast.show{transform:translateX(-50%) translateY(0)}
#toast.ok{border-color:rgba(34,197,94,.4);color:#4ade80}
#toast.err{border-color:rgba(239,68,68,.4);color:#f87171}

/* Benefit add/remove */
.b-add{width:100%;margin-top:8px;padding:7px;border:1px dashed rgba(255,102,0,.3);border-radius:8px;background:rgba(255,102,0,.04);color:rgba(255,102,0,.7);font-size:11px;font-weight:600;cursor:pointer;transition:.14s;display:flex;align-items:center;justify-content:center;gap:6px;font-family:inherit}
.b-add:hover{border-color:rgba(255,102,0,.55);background:rgba(255,102,0,.09);color:var(--brand)}
.b-del{background:transparent;border:none;color:var(--t3);cursor:pointer;font-size:10px;padding:0 4px;transition:.12s;margin-left:auto}
.b-del:hover{color:#f87171}
.b-num{display:flex;align-items:center}

/* Hero gallery modal */
#hero-modal{display:none;position:fixed;inset:0;z-index:200;background:rgba(0,0,0,.88);backdrop-filter:blur(12px);flex-direction:column;align-items:center;justify-content:center;padding:20px}
#hero-modal.open{display:flex}
.hm-box{background:#0d0d0d;border:1px solid rgba(255,102,0,.15);border-radius:16px;width:100%;max-width:700px;max-height:80vh;display:flex;flex-direction:column;overflow:hidden}
.hm-head{display:flex;align-items:center;gap:10px;padding:14px 18px;border-bottom:1px solid rgba(255,255,255,.07);flex-shrink:0}
.hm-head h3{flex:1;font-size:13px;font-weight:700}
.hm-upload{display:inline-flex;align-items:center;gap:6px;padding:5px 12px;border:1px solid rgba(255,102,0,.35);border-radius:8px;background:rgba(255,102,0,.07);color:rgba(255,102,0,.85);font-size:11px;font-weight:600;cursor:pointer;transition:.14s;white-space:nowrap}
.hm-upload:hover{border-color:rgba(255,102,0,.6);background:rgba(255,102,0,.12);color:var(--brand)}
.hm-close{background:transparent;border:1px solid rgba(255,255,255,.1);color:var(--t3);border-radius:7px;padding:5px 11px;font-size:11px;cursor:pointer;font-family:inherit;transition:.12s}
.hm-close:hover{color:var(--text);border-color:rgba(255,255,255,.2)}
.hm-body{flex:1;overflow-y:auto;padding:16px}
.hm-body::-webkit-scrollbar{width:4px}
.hm-body::-webkit-scrollbar-thumb{background:#1f1f1f;border-radius:3px}
.hm-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px}
.hm-img-wrap{position:relative;border-radius:10px;overflow:hidden;border:2px solid transparent;cursor:pointer;transition:.15s;aspect-ratio:16/9;background:#111}
.hm-img-wrap:hover{border-color:rgba(255,102,0,.5);transform:scale(1.02)}
.hm-img-wrap img{width:100%;height:100%;object-fit:cover;display:block}
.hm-img-ov{position:absolute;inset:0;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center;opacity:0;transition:.14s}
.hm-img-wrap:hover .hm-img-ov{opacity:1}
.hm-img-ov span{background:var(--brand);color:#000;font-size:10px;font-weight:800;padding:4px 12px;border-radius:20px;letter-spacing:.05em;text-transform:uppercase}
.hm-empty{text-align:center;padding:40px 20px;color:var(--t3)}
.hm-empty i{font-size:28px;margin-bottom:10px;display:block;opacity:.3}
.hm-empty p{font-size:12px}
.hm-loading{text-align:center;padding:40px;color:var(--t3);font-size:12px}
.lg-hov-or:hover{background:rgba(255,102,0,.25)!important}
.lg-hov-vi:hover{background:rgba(167,139,250,.25)!important}
.lg-foc-vi:focus{border-color:rgba(167,139,250,.5)!important}
</style>
<link rel="stylesheet" href="/toolz/assets/tool-studio.css?v=<?= (int)@filemtime(__DIR__ . '/../assets/tool-studio.css') ?>">
</head>
<body class="layout">
<?php include __DIR__ . '/../topbar.php'; ?>

<div class="ed-bar">
  <a href="index.php" class="btn ghost"><i class="fa-solid fa-arrow-left"></i></a>
  <div class="sep"></div>
  <div class="ed-title">
    <?= htmlspecialchars($landing['name']) ?>
    <em><?= htmlspecialchars($landing['platform']) ?></em>
  </div>
  <div class="sep"></div>
  <div class="dev-group">
    <button class="dev-btn"        data-dev="mobile"  data-on-click="setDevice" data-click-1="s:mobile"  title="Móvil (375px)"><i class="fa-solid fa-mobile-screen"></i></button>
    <button class="dev-btn"        data-dev="tablet"  data-on-click="setDevice" data-click-1="s:tablet"  title="Tablet (768px)"><i class="fa-solid fa-tablet-screen-button"></i></button>
    <button class="dev-btn active" data-dev="desktop" data-on-click="setDevice" data-click-1="s:desktop" title="Desktop (1280px)"><i class="fa-solid fa-desktop"></i></button>
  </div>
  <div class="sep"></div>
  <button class="btn" data-on-click="previewLanding" title="Abrir vista previa en nueva pestaña"><i class="fa-solid fa-eye"></i> Preview</button>
  <button class="btn" id="code-btn" data-on-click="toggleCodeEditor" title="Editar código HTML"><i class="fa-solid fa-code"></i> Código</button>
  <div class="sep"></div>
  <button class="btn" data-on-click="openCMSModal" title="Generar código para insertar en CMS" style="border-color:rgba(167,139,250,.35);color:#a78bfa"><i class="fa-solid fa-code"></i> CMS</button>
  <button class="btn" data-on-click="downloadHTML" title="Descargar HTML"><i class="fa-solid fa-download"></i></button>
  <button class="btn" data-on-click="copyHTML"><i class="fa-solid fa-copy"></i> Copiar</button>
  <button class="btn" id="save-btn" data-on-click="saveLanding"><i class="fa-solid fa-floppy-disk"></i> Guardar</button>
  <button class="btn pub-btn" id="pub-btn" data-on-click="publishLanding"><i class="fa-solid fa-rocket"></i> Publicar</button>
</div>

<!-- Publish banner -->
<div id="pub-banner">
  <i class="fa-solid fa-circle-check pub-ico"></i>
  <span class="pub-lbl">Publicada</span>
  <span class="pub-url"><a id="pub-url-link" href="#" target="_blank" rel="noopener"></a></span>
  <div class="pub-actions">
    <button class="pb" data-on-click="copyPubUrl"><i class="fa-solid fa-copy"></i> Copiar URL</button>
    <a id="pub-open-btn" href="#" target="_blank" rel="noopener" class="pb"><i class="fa-solid fa-external-link-alt"></i> Abrir</a>
    <button class="pb pb-close" data-on-click="lgHidePubBanner" title="Cerrar"><i class="fa-solid fa-xmark"></i></button>
  </div>
</div>

<div class="body">
  <div class="sidebar">
    <div class="sb-head">
      <h2>Panel de Edición</h2>
      <p>Click en preview para editar inline</p>
    </div>
    <div id="focus-bar" class="focus-bar"><i class="fa-solid fa-pen-to-square"></i><span id="focus-txt">-</span></div>
    <div class="sb-body">

      <?php if ($isTx): ?>
      <details class="sec" id="sec-hero">
        <summary>
          <span class="sec-ic"><i class="fa-solid fa-image"></i></span>
          <span class="sec-lbl">Arte de la promo</span>
          <span class="sec-chev"><i class="fa-solid fa-chevron-down"></i></span>
        </summary>
        <div class="sec-body">
          <label class="fx-row" style="margin-bottom:8px"><input type="checkbox" id="o-arte-on" data-on-change="optSet" data-change-1="s:arte" data-change-2="s:on" data-change-3="checked"> Mostrar la imagen de la promo</label>
          <div id="o-arte-blk">
            <?= lgImgPicker('hero', 'f-hero-img', 'data-on-input="lgFrameUrl" data-input-1="s:setHero" data-input-2="value"') ?>
            <div class="fhint" style="margin-bottom:12px">Va a la derecha del título, flotando. Mejor un PNG o WebP con fondo transparente.</div>
            <div class="field"><label class="lbl-dev">Tamaño <span class="devs"></span></label>
              <div class="rng"><input type="range" id="o-arte-w" min="120" max="640" step="10" data-on-input="optRsp" data-input-1="s:arte" data-input-2="nvalue"><output></output><button type="button" class="rsp-x" data-on-click="optRsp" data-click-1="s:arte" data-click-2="null" title="Quitar el ajuste de este dispositivo"><i class="fa-solid fa-rotate-left"></i></button></div>
              <div class="fhint">El tamaño se ajusta por dispositivo: el ícono marcado es el que estás tocando. Tablet y celular usan el de arriba hasta que los cambies.</div></div>
          </div>
          <div class="fhint" id="o-arte-off" hidden>Sin la imagen, el título queda solo y centrado.</div>
        </div>
      </details>

      <details class="sec" id="sec-bg">
        <summary>
          <span class="sec-ic"><i class="fa-solid fa-panorama"></i></span>
          <span class="sec-lbl">Fondo</span>
          <span class="sec-chev"><i class="fa-solid fa-chevron-down"></i></span>
        </summary>
        <div class="sec-body">
          <?= lgImgPicker('bg', 'f-bg-img', 'data-on-input="lgFrameUrl" data-input-1="s:setBg" data-input-2="value"') ?>
          <div class="fhint" style="margin-bottom:14px">Vacío = fondo oscuro con brillo naranja. Una imagen horizontal (1920×1080 o 1200×630) queda bien.</div>
          <div class="field">
            <label>Cómo se acomoda</label>
            <select id="o-bg-fit" data-on-change="optSet" data-change-1="s:bg" data-change-2="s:fit" data-change-3="value">
              <option value="cover">Cubrir toda la pantalla (recorta)</option>
              <option value="width">Al ancho de la pantalla</option>
              <option value="contain">Entera, sin recortar</option>
              <option value="auto">Tamaño original</option>
              <option value="repeat">Mosaico (se repite)</option>
            </select>
          </div>
          <div class="field">
            <label>Posición</label>
            <div class="pos-grid" id="o-bg-pos">
              <?php foreach (['left top','center top','right top','left center','center center','right center','left bottom','center bottom','right bottom'] as $p): ?>
              <button type="button" data-pos="<?= $p ?>" title="<?= $p ?>" data-on-click="lgOptPos" data-click-1="this"></button>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="field"><label>Zoom</label>
            <div class="rng"><input type="range" id="o-bg-zoom" min="100" max="250" step="5" data-on-input="optSet" data-input-1="s:bg" data-input-2="s:zoom" data-input-3="nvalue"><output></output></div></div>
          <div class="field"><label>Oscurecer</label>
            <div class="rng"><input type="range" id="o-bg-dim" min="0" max="90" step="5" data-on-input="optSet" data-input-1="s:bg" data-input-2="s:dim" data-input-3="nvalue"><output></output></div></div>
          <div class="field"><label>Desenfoque</label>
            <div class="rng"><input type="range" id="o-bg-blur" min="0" max="20" data-on-input="optSet" data-input-1="s:bg" data-input-2="s:blur" data-input-3="nvalue"><output></output></div></div>
          <button class="b-add" data-on-click="optResetBg"><i class="fa-solid fa-rotate-left"></i> Volver al formato original</button>
        </div>
      </details>

      <details class="sec" id="sec-ban">
        <summary>
          <span class="sec-ic"><i class="fa-solid fa-rectangle-ad"></i></span>
          <span class="sec-lbl">Hero arriba</span>
          <span class="sec-chev"><i class="fa-solid fa-chevron-down"></i></span>
        </summary>
        <div class="sec-body">
          <label class="fx-row" style="margin-bottom:8px"><input type="checkbox" id="o-ban-on" data-on-change="optSet" data-change-1="s:ban" data-change-2="s:on" data-change-3="checked"> Agregar un hero arriba de todo</label>
          <div id="o-ban-blk">
            <?= lgImgPicker('ban', 'o-ban-url', 'data-on-input="optSet" data-input-1="s:ban" data-input-2="s:url" data-input-3="tvalue"', 'O pegar URL (compu)') ?>
            <div class="fhint" style="margin:-4px 0 12px">Una imagen ancha queda bien: 1920×500 o 1440×400.</div>
            <div class="field"><label>Imagen para celular (opcional)</label></div>
            <?= lgImgPicker('banm', 'o-ban-urlm', 'data-on-input="optSet" data-input-1="s:ban" data-input-2="s:urlM" data-input-3="tvalue"', 'O pegar URL (celular)') ?>
            <div class="fhint" style="margin:-4px 0 12px">Si queda vacía, en celular se ve la misma de la compu. Una de 1080×540 queda bien.</div>
            <div class="field"><label>Ancho</label>
              <div class="seg" id="o-ban-full">
                <button type="button" data-v="0" data-on-click="optSet" data-click-1="s:ban" data-click-2="s:full" data-click-3="false">Del contenido</button>
                <button type="button" data-v="1" data-on-click="optSet" data-click-1="s:ban" data-click-2="s:full" data-click-3="true">Toda la pantalla</button>
              </div></div>
            <label class="fx-row"><input type="checkbox" id="o-ban-link" data-on-change="optSet" data-change-1="s:ban" data-change-2="s:link" data-change-3="checked"> Al tocarlo, va a la URL del botón</label>
          </div>
        </div>
      </details>
      <?php else: ?>
      <details class="sec" id="sec-hero">
        <summary>
          <span class="sec-ic"><i class="fa-solid fa-image"></i></span>
          <span class="sec-lbl">Hero / Imagen</span>
          <span class="sec-chev"><i class="fa-solid fa-chevron-down"></i></span>
        </summary>
        <div class="sec-body">
          <?= lgImgPicker('hero', 'f-hero-img', 'data-on-input="lgFrameUrl" data-input-1="s:setHero" data-input-2="value"') ?>
          <div class="fhint">Deja vacío para mostrar el color placeholder</div>
        </div>
      </details>
      <?php endif; ?>

      <details class="sec" id="sec-cta" open>
        <summary>
          <span class="sec-ic"><i class="fa-solid fa-heading"></i></span>
          <span class="sec-lbl">Título y CTA</span>
          <span class="sec-chev"><i class="fa-solid fa-chevron-down"></i></span>
        </summary>
        <div class="sec-body">
          <?php if ($isTx): ?>
          <div class="field"><label>Mostrar</label>
            <div class="seg" id="o-tit-mode">
              <button type="button" data-v="0" data-on-click="optSet" data-click-1="s:tit" data-click-2="s:on" data-click-3="false">Título y botón</button>
              <button type="button" data-v="1" data-on-click="optSet" data-click-1="s:tit" data-click-2="s:on" data-click-3="true">Una imagen</button>
            </div></div>
          <div id="o-tit-blk">
            <?= lgImgPicker('tit', 'o-tit-url', 'data-on-input="optSet" data-input-1="s:tit" data-input-2="s:url" data-input-3="tvalue"') ?>
            <div class="field"><label class="lbl-dev">Tamaño <span class="devs"></span></label>
              <div class="rng"><input type="range" id="o-tit-w" min="20" max="100" step="5" data-on-input="optRsp" data-input-1="s:tit" data-input-2="nvalue"><output></output><button type="button" class="rsp-x" data-on-click="optRsp" data-click-1="s:tit" data-click-2="null" title="Quitar el ajuste de este dispositivo"><i class="fa-solid fa-rotate-left"></i></button></div></div>
            <label class="fx-row" style="margin-bottom:6px"><input type="checkbox" id="o-tit-sub" data-on-change="optSet" data-change-1="s:tit" data-change-2="s:sub" data-change-3="checked"> Mostrar el subtítulo debajo</label>
            <div class="fhint" style="margin-bottom:12px">La imagen reemplaza al título y al botón, y al tocarla lleva a la URL del botón. Mejor un PNG o WebP con fondo transparente.</div>
          </div>
          <div id="o-tit-texto">
          <div class="field">
            <label>Título, primera línea</label>
            <input type="text" id="f-t1" data-on-input="frameSet" data-input-1="s:.hero-t1" data-input-2="n:0" data-input-3="value">
          </div>
          <div class="field">
            <label>Título, línea destacada (brillo dorado)</label>
            <input type="text" id="f-t2" data-on-input="frameSet" data-input-1="s:.hero-t2" data-input-2="n:0" data-input-3="value">
          </div>
          </div>
          <div class="field" id="o-tit-subf">
            <label>Subtítulo</label>
            <textarea id="f-sub" rows="3" data-on-input="frameSet" data-input-1="s:.hero-sub" data-input-2="n:0" data-input-3="value" data-input-4="true"></textarea>
            <div class="fhint">Lo que va entre <code>&lt;strong&gt;</code> brilla en dorado (el monto o premio).</div>
          </div>
          <?php else: ?>
          <div class="field">
            <label>Título principal</label>
            <textarea id="f-title" rows="2" data-on-input="frameSet" data-input-1="s:h1" data-input-2="n:0" data-input-3="value"></textarea>
          </div>
          <?php endif; ?>
          <div class="field" id="o-cta-textf">
            <label>Texto del botón CTA</label>
            <input type="text" id="f-cta-text" data-on-input="lgCtaText" data-input-1="value">
          </div>
          <div class="field">
            <label>URL destino del botón</label>
            <input type="url" id="f-cta-url" placeholder="https://casino.com/oferta" data-on-input="lgCtaHref" data-input-1="value">
          </div>
        </div>
      </details>

      <?php if (!$isTx): /* la plantilla de textos no tiene beneficios, narrativa ni caja de vigencia */ ?>
      <details class="sec" id="sec-bens" open>
        <summary>
          <span class="sec-ic"><i class="fa-solid fa-star"></i></span>
          <span class="sec-lbl">Beneficios</span>
          <span class="sec-chev"><i class="fa-solid fa-chevron-down"></i></span>
        </summary>
        <div class="sec-body">
          <div id="benefits-list"><div class="empty-b"><i class="fa-solid fa-circle-notch fa-spin"></i>Cargando…</div></div>
        </div>
      </details>

      <details class="sec" id="sec-narr">
        <summary>
          <span class="sec-ic"><i class="fa-solid fa-align-left"></i></span>
          <span class="sec-lbl">Narrativa</span>
          <span class="sec-chev"><i class="fa-solid fa-chevron-down"></i></span>
        </summary>
        <div class="sec-body">
          <div class="field">
            <label>Texto persuasivo</label>
            <textarea id="f-narr" rows="5" data-on-input="frameSet" data-input-1="s:.promo-narrative" data-input-2="n:0" data-input-3="value" data-input-4="true"></textarea>
            <div class="fhint">Soporta HTML: <code>&lt;strong&gt;</code> <code>&lt;em&gt;</code> <code>&lt;br&gt;</code></div>
          </div>
        </div>
      </details>

      <details class="sec" id="sec-vig">
        <summary>
          <span class="sec-ic"><i class="fa-solid fa-calendar-days"></i></span>
          <span class="sec-lbl">Vigencia</span>
          <span class="sec-chev"><i class="fa-solid fa-chevron-down"></i></span>
        </summary>
        <div class="sec-body">
          <div class="field">
            <label>Fechas de vigencia</label>
            <input type="text" id="f-vig" placeholder="01/01/2025 – 31/12/2025" data-on-input="frameSet" data-input-1="s:.summary-date-value" data-input-2="n:0" data-input-3="value">
          </div>
        </div>
      </details>
      <?php endif; ?>

      <?php if ($isTx): ?>
      <details class="sec" id="sec-fx">
        <summary>
          <span class="sec-ic"><i class="fa-solid fa-wand-magic-sparkles"></i></span>
          <span class="sec-lbl">Efectos</span>
          <span class="sec-chev"><i class="fa-solid fa-chevron-down"></i></span>
        </summary>
        <div class="sec-body">
          <?php foreach (LG_TX_EFECTOS as $k => $fxDef): ?>
          <label class="fx-row"><input type="checkbox" data-fx="<?= $k ?>" data-on-change="lgSetFx" data-change-1="this"> <?= htmlspecialchars($fxDef['label']) ?></label>
          <?php endforeach; ?>
          <div class="fhint" style="margin-top:8px">Se ven al instante en el preview y quedan guardados en la landing.</div>
        </div>
      </details>
      <?php endif; ?>

      <details class="sec" id="sec-tyc">
        <summary>
          <span class="sec-ic"><i class="fa-solid fa-file-contract"></i></span>
          <span class="sec-lbl">T&amp;C</span>
          <span class="sec-chev"><i class="fa-solid fa-chevron-down"></i></span>
        </summary>
        <div class="sec-body">
          <p style="font-size:11px;color:var(--t3);line-height:1.6">Haz click directamente sobre cualquier cláusula en el preview para editarla inline. El borde naranja indica los elementos editables.<?php if ($isTx): ?><br><br>Cada desplegable se abre con la flecha; un click sobre su título lo edita.<?php endif; ?></p>
        </div>
      </details>

    </div>
  </div>
  <div class="canvas" id="canvas"></div>
  <div id="code-panel" style="display:none;flex:1;flex-direction:column;min-height:0;background:#080808">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 14px;background:#0d0d0d;border-bottom:1px solid #1a1a1a;flex-shrink:0">
      <span style="font-size:10px;font-weight:700;color:#52525b;letter-spacing:.08em;text-transform:uppercase">Editor de código</span>
      <div style="display:flex;gap:6px">
        <button data-on-click="applyCodeChanges" style="background:rgba(255,102,0,.15);border:1px solid rgba(255,102,0,.3);color:#ff6600;border-radius:6px;padding:4px 12px;font-size:10px;font-weight:700;cursor:pointer;font-family:inherit;letter-spacing:.05em;text-transform:uppercase;transition:.15s" class="lg-hov-or"><i class="fa-solid fa-rotate"></i> Aplicar</button>
      </div>
    </div>
    <textarea id="code-textarea" spellcheck="false" style="flex:1;width:100%;background:#080808;border:none;color:#e4e4e7;font-family:'Courier New',Consolas,monospace;font-size:12px;line-height:1.6;padding:16px;resize:none;outline:none;box-sizing:border-box;overflow:auto;tab-size:2"></textarea>
  </div>
</div>

<!-- Hero gallery modal -->
<div id="hero-modal">
  <div class="hm-box">
    <div class="hm-head">
      <h3><i class="fa-solid fa-images" style="color:var(--brand);margin-right:7px"></i>Galería de imágenes hero</h3>
      <label class="hm-upload">
        <i class="fa-solid fa-cloud-arrow-up"></i> Subir imagen
        <input type="file" accept="image/*" style="display:none" data-on-change="galleryUpload" data-change-1="this">
      </label>
      <button class="hm-close" data-on-click="closeGallery">Cerrar</button>
    </div>
    <div class="hm-body">
      <div id="hm-grid-wrap" class="hm-loading"><i class="fa-solid fa-spinner fa-spin"></i> Cargando...</div>
    </div>
  </div>
</div>

<div id="toast"></div>

<!-- CMS Modal -->
<div id="cms-modal" style="display:none;position:fixed;inset:0;z-index:300;background:rgba(0,0,0,.85);backdrop-filter:blur(10px);align-items:center;justify-content:center;padding:20px">
  <div style="background:#0d0d0d;border:1px solid rgba(167,139,250,.2);border-radius:16px;width:100%;max-width:620px;display:flex;flex-direction:column;overflow:hidden">
    <div style="display:flex;align-items:center;gap:10px;padding:16px 20px;border-bottom:1px solid rgba(255,255,255,.07)">
      <i class="fa-solid fa-code" style="color:#a78bfa;font-size:14px"></i>
      <h3 style="flex:1;font-size:14px;font-weight:700;color:#e4e4e7">Insertar en CMS</h3>
      <button data-on-click="closeCMSModal" style="background:transparent;border:1px solid rgba(255,255,255,.1);color:#71717a;border-radius:6px;padding:4px 10px;font-size:12px;cursor:pointer;font-family:inherit">✕</button>
    </div>
    <div style="padding:20px;display:flex;flex-direction:column;gap:14px">
      <div>
        <label style="display:block;font-size:10px;font-weight:700;color:#52525b;letter-spacing:.08em;text-transform:uppercase;margin-bottom:6px">URL de la landing publicada</label>
        <input id="cms-url-input" type="url" placeholder="<?= htmlspecialchars(lg_pub_base()) ?>..." style="width:100%;background:rgba(0,0,0,.5);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#e4e4e7;font-size:12px;padding:9px 12px;outline:none;font-family:inherit;transition:border-color .15s" class="lg-foc-vi" data-on-input="updateCMSPreview">
        <p id="cms-no-url-hint" style="font-size:10px;color:#f87171;margin-top:5px;display:none"><i class="fa-solid fa-triangle-exclamation"></i> Publica la landing primero para obtener la URL automáticamente.</p>
      </div>
      <div>
        <label style="display:block;font-size:10px;font-weight:700;color:#52525b;letter-spacing:.08em;text-transform:uppercase;margin-bottom:6px">Código generado</label>
        <textarea id="cms-code-output" readonly spellcheck="false" style="width:100%;height:160px;background:#060606;border:1px solid rgba(255,255,255,.07);border-radius:8px;color:#a78bfa;font-family:'Courier New',Consolas,monospace;font-size:11px;line-height:1.6;padding:12px;resize:none;outline:none;box-sizing:border-box"></textarea>
      </div>
      <div style="display:flex;gap:8px;justify-content:flex-end">
        <button data-on-click="closeCMSModal" style="background:transparent;border:1px solid rgba(255,255,255,.1);color:#71717a;border-radius:8px;padding:8px 16px;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;transition:.15s">Cancelar</button>
        <button data-on-click="copyCMSCode" style="background:rgba(167,139,250,.15);border:1px solid rgba(167,139,250,.35);color:#a78bfa;border-radius:8px;padding:8px 18px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;transition:.15s" class="lg-hov-vi"><i class="fa-solid fa-copy"></i> Copiar código</button>
      </div>
    </div>
  </div>
</div>

<?php
// Datos para editor.js (CSP estricta: sin scripts ejecutables en línea). IFRAME_SRC e INJECT_SCRIPT son HTML que corre en frame.php, aislado.
$lgPubUrl = !empty($landing['public_url'])
    ? $landing['public_url']
    : ($landing['published_at'] ? lg_pub_base() . landingSlug($landing['name'] ?? 'landing', $id, (string)$landing['platform']) . '.html' : '');
$lgBoot = ['id' => $id, 'kind' => $kind, 'host' => (string)parse_url(lg_pub_origin(), PHP_URL_HOST), 'name' => $landing['name'], 'src' => $iframeSrc, 'inject' => $inject, 'pubUrl' => $lgPubUrl];
?>
<script type="application/json" id="lge-data"><?= json_encode($lgBoot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="/toolz/landing-gen/frame-host.js?v=<?= (int)@filemtime(__DIR__ . '/frame-host.js') ?>"></script>
<script src="/toolz/landing-gen/editor.js?v=<?= (int)@filemtime(__DIR__ . '/editor.js') ?>"></script>
<script src="/toolz/assets/tool-studio.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/tool-studio.js') ?>" data-ts="editor" data-ts-name="Editor de landing" data-ts-desc="Tocá el preview para editar en el lugar" data-ts-icon="fa-pen-ruler" data-ts-grad="#22c55e,#0ea5e9" data-ts-head=".sb-head" data-ts-icons=".sec-ic" data-ts-stage=".canvas"></script>
</body>
</html>
