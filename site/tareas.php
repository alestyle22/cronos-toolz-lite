<?php
/**
 * tareas.php — Tablero de tareas (sin Clientes, cada tarea es de una marca): Lista (tipo Monday), Kanban (tipo Trello) y Calendario.
 * Toda la lógica está en tareas.js, que habla con tareas_api.php. ?t=ID abre una tarea; ?c=ID filtra por cliente.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csp_lib.php'; g7_csp_enforce();   // sin código en línea (SEC-24)
$currentUser = requireLogin();
$isAdmin = ($currentUser['role'] === 'admin');
$db = getDB();
require_once __DIR__ . '/trabajo/lib.php';
wk_install($db);
// Un enlace viejo a una tarea (?t=ID) abre el tablero donde está esa tarea
if (!empty($_GET['t']) && empty($_GET['b'])) {
    $q = $db->prepare('SELECT board_id FROM g7_tasks WHERE id = ?'); $q->execute([(int)$_GET['t']]);
    if ($tb = (int)$q->fetchColumn()) { header('Location: /toolz/tareas.php?' . http_build_query(['b' => $tb] + $_GET)); exit; }
}
$wkBoard = wk_board($db, (int)($_GET['b'] ?? 0) ?: wk_general_id($db));
if (!$wkBoard) { header('Location: /toolz/trabajo/#tableros'); exit; }
$v = fn($f) => @filemtime(__DIR__ . '/' . $f);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= htmlspecialchars($wkBoard['name']) ?> · Tareas · <?= htmlspecialchars(g7_setting('platform_name') ?: g7_site('name')) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" integrity="sha384-blOohCVdhjmtROpu8+CfTnUWham9nkX7P7OZQMst+RUnhtoY/9qemFAkIKOYxDI3" crossorigin="anonymous">
<style>
*,*::before,*::after{box-sizing:border-box}
body{margin:0;background:var(--g7-bg,#000);color:var(--g7-text,#fff);font-family:var(--g7-font,'Inter',sans-serif);font-size:13px;min-height:100dvh}
button{font:inherit;color:inherit}
input,select,textarea{font:inherit;color:inherit}
.tk-wrap{max-width:1640px;margin:0 auto;padding:26px 24px 60px}

/* Encabezado */
.tk-head{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:20px}
.tk-head h1{margin:0;font-size:28px;font-weight:800;letter-spacing:-.6px;line-height:1.1}
.tk-head h1 span{color:var(--g7-accent)}
.tk-head h1 .tk-bicon{font-size:.72em;margin-right:12px;vertical-align:.08em}
.tk-back{display:inline-flex;align-items:center;gap:7px;font-size:12px;font-weight:600;color:var(--g7-text-3);text-decoration:none;margin-bottom:8px}
.tk-back:hover{color:var(--g7-accent)}
.tk-head p{margin:6px 0 0;color:var(--g7-text-3);font-size:13px}
.tk-head-a{display:flex;gap:8px;flex-wrap:wrap}
.tk-btn{display:inline-flex;align-items:center;gap:7px;height:36px;padding:0 14px;border-radius:9px;border:1px solid var(--g7-border-3);background:var(--g7-bg-4);color:var(--g7-text-2);font-weight:600;font-size:12.5px;cursor:pointer;transition:background .2s,border-color .2s,color .2s,transform .1s;white-space:nowrap;text-decoration:none}
.tk-btn:hover{border-color:var(--g7-accent-border);color:var(--g7-text)}
.tk-btn:active{transform:translateY(1px)}
.tk-btn:focus-visible,.tk-icon:focus-visible,.tk-seg button:focus-visible{outline:2px solid var(--g7-accent);outline-offset:2px}
.tk-btn.primary{background:linear-gradient(#ff7700,#e65500);border-color:transparent;color:#fff;box-shadow:0 6px 18px rgba(255,102,0,.3);font-weight:700}
.tk-btn.primary:hover{filter:brightness(1.08)}
.tk-btn.danger{color:#f87171;border-color:rgba(248,113,113,.3);background:rgba(248,113,113,.06)}
.tk-btn.sm{height:30px;padding:0 10px;font-size:12px}
.tk-btn[disabled]{opacity:.5;pointer-events:none}
.tk-icon{width:32px;height:32px;display:inline-flex;align-items:center;justify-content:center;border-radius:8px;border:1px solid transparent;background:none;color:var(--g7-text-3);cursor:pointer;transition:all .15s;flex-shrink:0}
.tk-icon:hover{background:var(--g7-bg-5);color:var(--g7-text)}

/* Barra de herramientas */
.tk-bar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:18px;padding:10px;background:var(--g7-bg-3);border:1px solid var(--g7-border-2);border-radius:14px}
.tk-seg{display:flex;background:var(--g7-bg);border:1px solid var(--g7-border-2);border-radius:10px;padding:3px}
.tk-seg button{border:0;background:none;color:var(--g7-text-3);height:30px;padding:0 13px;border-radius:7px;font-weight:600;font-size:12.5px;cursor:pointer;display:flex;align-items:center;gap:7px;transition:all .2s}
.tk-seg button:hover{color:var(--g7-text)}
.tk-seg button.on{background:var(--g7-bg-5);color:var(--g7-text);box-shadow:inset 0 0 0 1px var(--g7-border-3)}
.tk-seg button.on i{color:var(--g7-accent)}
.tk-client{display:flex;align-items:center;gap:8px;height:38px;padding:0 6px 0 12px;border-radius:10px;border:1px solid var(--g7-accent-border);background:var(--g7-accent-muted)}
.tk-client i{color:var(--g7-accent);font-size:12px}
.tk-client select{background:none;border:0;outline:0;font-weight:700;font-size:13px;color:var(--g7-text);max-width:220px;cursor:pointer;height:36px}
.tk-client select option,.tk-sel option{background:#141414;color:#fff}
.tk-sel{height:36px;border-radius:9px;border:1px solid var(--g7-border-2);background:var(--g7-bg);padding:0 10px;outline:0;font-size:12.5px;color:var(--g7-text-2);cursor:pointer;max-width:180px}
.tk-sel:focus,.tk-search input:focus{border-color:var(--g7-accent-border)}
.tk-search{position:relative;flex:1;min-width:180px;max-width:320px}
.tk-search i{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--g7-text-4);font-size:12px}
.tk-search input{width:100%;height:36px;border-radius:9px;border:1px solid var(--g7-border-2);background:var(--g7-bg);padding:0 12px 0 32px;outline:0;font-size:12.5px}
.tk-bar .grow{flex:1}
.tk-check{display:flex;align-items:center;gap:7px;color:var(--g7-text-3);font-size:12px;cursor:pointer;user-select:none;white-space:nowrap}
.tk-check input{accent-color:var(--g7-accent)}
.tk-count{color:var(--g7-text-4);font-size:12px;white-space:nowrap;font-variant-numeric:tabular-nums}

/* Piezas comunes */
.tk-av{width:26px;height:26px;border-radius:8px;object-fit:cover;flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;color:#000;border:2px solid var(--g7-bg-3)}
.tk-avs{display:flex;align-items:center}
.tk-avs .tk-av+.tk-av{margin-left:-7px}
.tk-avs .more{font-size:11px;color:var(--g7-text-3);margin-left:4px}
.tk-pill{display:inline-flex;align-items:center;gap:6px;height:24px;padding:0 10px;border-radius:7px;font-size:11.5px;font-weight:700;white-space:nowrap;background:color-mix(in srgb,var(--c) 18%,transparent);color:color-mix(in srgb,var(--c) 75%,#fff);border:1px solid color-mix(in srgb,var(--c) 35%,transparent)}
.tk-pill.dot::before{content:'';width:7px;height:7px;border-radius:50%;background:var(--c)}
.tk-lbl{display:inline-flex;height:18px;padding:0 7px;border-radius:5px;font-size:10.5px;font-weight:700;align-items:center;background:var(--c);color:#fff;text-shadow:0 1px 1px rgba(0,0,0,.25)}
.tk-due{display:inline-flex;align-items:center;gap:5px;font-size:12px;color:var(--g7-text-2);white-space:nowrap;font-variant-numeric:tabular-nums}
.tk-due.soon{color:#fbbf24}.tk-due.late{color:#f87171;font-weight:700}.tk-due.done{color:var(--g7-text-4);text-decoration:line-through}
.tk-meta{display:inline-flex;align-items:center;gap:4px;font-size:11.5px;color:var(--g7-text-4)}
.tk-client-chip{display:inline-flex;align-items:center;gap:5px;font-size:11.5px;color:var(--g7-text-3);max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.tk-empty{padding:60px 20px;text-align:center;color:var(--g7-text-3);border:1px dashed var(--g7-border-3);border-radius:16px}
.tk-empty i{font-size:30px;color:var(--g7-text-5);display:block;margin-bottom:12px}
.tk-empty b{display:block;color:var(--g7-text);font-size:15px;margin-bottom:6px}
.tk-loading{display:grid;gap:10px}
.tk-sk{height:46px;border-radius:12px;background:linear-gradient(90deg,var(--g7-bg-3),var(--g7-bg-4),var(--g7-bg-3));background-size:200% 100%;animation:tksk 1.2s infinite}
@keyframes tksk{to{background-position:-200% 0}}

/* LISTA (tipo Monday) */
.tk-group{margin-bottom:26px}
.tk-ghead{display:flex;align-items:center;gap:10px;margin-bottom:8px}
.tk-ghead button.tk-icon{width:26px;height:26px;color:var(--c)}
.tk-ghead .n{font-size:15px;font-weight:800;color:var(--c);letter-spacing:-.2px}
.tk-ghead .c{font-size:12px;color:var(--g7-text-4)}
.tk-group.closed .tk-table{display:none}
.tk-group.closed .tk-ghead button.tk-icon i{transform:rotate(-90deg)}
.tk-table{border:1px solid var(--g7-border-2);border-radius:12px;overflow-x:auto;background:var(--g7-bg-3)}
.tk-row{display:grid;grid-template-columns:34px minmax(260px,1fr) 160px 130px 150px 120px 130px 112px;min-width:1100px;align-items:stretch;border-bottom:1px solid var(--g7-border)}
.tk-row:last-child{border-bottom:0}
.tk-row>div{padding:0 12px;display:flex;align-items:center;min-height:46px;border-left:1px solid var(--g7-border);min-width:0}
.tk-row>div:first-child{padding:0 0 0 4px;box-shadow:inset 4px 0 0 var(--c);border-left:0;justify-content:center}
.tk-row>div:first-child input{accent-color:var(--g7-accent);width:15px;height:15px;cursor:pointer;opacity:.35;transition:opacity .15s}
.tk-tr:hover>div:first-child input,.tk-row>div:first-child input:checked,.tk-th:hover>div:first-child input{opacity:1}
.tk-tr.sel{background:rgba(var(--g7-accent-rgb),.07)}
.tk-row>div:nth-child(2){border-left:0}
.tk-th>div{min-height:36px;font-size:11px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.6px}
.tk-th>div.sortable{cursor:pointer}.tk-th>div.sortable:hover{color:var(--g7-text-2)}
.tk-tr{transition:background .15s}
.tk-tr:hover{background:rgba(255,255,255,.025)}
.tk-tr.arch{opacity:.55}
.tk-title{display:flex;align-items:center;gap:8px;min-width:0;flex:1;cursor:pointer}
/* Lista: la manija aparece al pasar el mouse; la fila se arrastra a otro grupo (o se reordena) */
.tk-grip{flex:none;width:14px;margin-left:-6px;color:var(--g7-text-5);font-size:11px;cursor:grab;opacity:0;transition:opacity .15s,color .15s}
.tk-tr:hover .tk-grip{opacity:1}
.tk-grip:hover{color:var(--g7-text-2)}
.tk-tr.dragging{opacity:.35}
.tk-ghost.tk-tr{background:var(--g7-bg-3);border-radius:10px;border:1px solid var(--g7-accent-border);overflow:hidden}
.tk-group.over .tk-ghead{color:var(--g7-accent)}
.tk-group.over.closed .tk-ghead{background:rgba(var(--g7-accent-rgb),.1);border-radius:10px}
.tk-table>.tk-drop-ph{margin:-2px 0 -2px 6px}
@media (hover:none){.tk-grip{opacity:1}}
.tk-title .t{font-weight:600;font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.tk-title:hover .t{color:var(--g7-accent)}
.tk-title .lbls{display:flex;gap:4px;flex-shrink:0}
.tk-title .ic{display:flex;gap:8px;margin-left:auto;flex-shrink:0}
.tk-cell-btn{width:100%;height:100%;min-height:46px;border:0;background:none;padding:0;display:flex;align-items:center;cursor:pointer;text-align:left;gap:6px}
.tk-cell-fill{margin:0 -12px;width:calc(100% + 24px);justify-content:center;font-weight:700;font-size:12px;color:#fff;background:var(--c);transition:filter .15s;text-shadow:0 1px 1px rgba(0,0,0,.2)}
.tk-cell-fill:hover{filter:brightness(1.12)}
.tk-cell-btn.ph{color:var(--g7-text-5)}
.tk-cell-btn:hover .ph,.tk-cell-btn.ph:hover{color:var(--g7-text-3)}
.tk-addrow>div:nth-child(2){padding:0}
.tk-addrow button{width:100%;height:42px;border:0;background:none;padding:0 12px;font-size:13px;color:var(--g7-text-4);text-align:left;cursor:pointer;transition:all .15s}
.tk-addrow button:hover,.tk-addrow button:focus-visible{background:rgba(255,255,255,.03);color:var(--g7-accent)}
.tk-upd{font-size:11.5px;color:var(--g7-text-4);white-space:nowrap}

/* KANBAN (tipo Trello) */
.tk-board{display:flex;gap:14px;overflow-x:auto;padding-bottom:12px;align-items:flex-start;min-height:60vh}
.tk-col{flex:0 0 300px;background:var(--g7-bg-3);border:1px solid var(--g7-border-2);border-radius:14px;display:flex;flex-direction:column;max-height:calc(100dvh - 230px)}
.tk-col-h{display:flex;align-items:center;gap:8px;padding:12px 12px 10px 14px;border-top:3px solid var(--c);border-radius:14px 14px 0 0}
.tk-col-h .n{font-weight:800;font-size:13px}
.tk-col-h .c{font-size:11.5px;color:var(--g7-text-4);background:var(--g7-bg-5);border-radius:6px;padding:2px 7px;font-variant-numeric:tabular-nums}
.tk-col-h .sp{flex:1}
.tk-col-b{padding:4px 10px 10px;overflow-y:auto;display:flex;flex-direction:column;gap:8px;min-height:60px;flex:1}
.tk-col-b.over{background:rgba(255,102,0,.04)}
.tk-card{background:var(--g7-bg-4);border:1px solid var(--g7-border-2);border-radius:11px;padding:11px 12px;cursor:pointer;display:flex;flex-direction:column;gap:8px;transition:border-color .15s,transform .15s,box-shadow .15s;position:relative}
.tk-card:hover{border-color:var(--g7-border-3);box-shadow:0 6px 18px rgba(0,0,0,.35)}
.tk-card:focus-visible{outline:2px solid var(--g7-accent);outline-offset:1px}
.tk-card.dragging{opacity:.35}
.tk-card.arch{opacity:.55}
.tk-card .t{font-weight:600;font-size:13px;line-height:1.4;word-break:break-word}
.tk-card .lbls{display:flex;gap:4px;flex-wrap:wrap}
.tk-card .row{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.tk-card .row .sp{flex:1}
.tk-card .prio{position:absolute;left:0;top:12px;bottom:12px;width:3px;border-radius:0 3px 3px 0;background:var(--c)}
.tk-card,.tk-chip{user-select:none;-webkit-user-select:none;-webkit-touch-callout:none}
.tk-ghost{position:fixed;left:0;top:0;z-index:1500;pointer-events:none;opacity:.95;box-shadow:0 22px 50px rgba(0,0,0,.6);border-color:var(--g7-accent-border)!important;transition:none!important}
body.tk-dragging,body.tk-dragging *{cursor:grabbing!important}
.tk-drop-ph{height:4px;border-radius:3px;background:var(--g7-accent);box-shadow:0 0 10px var(--g7-accent-glow);margin:-2px 0}
.tk-col-add{margin:0 10px 10px;border:1px dashed var(--g7-border-3);background:none;color:var(--g7-text-4);border-radius:10px;height:36px;cursor:pointer;font-weight:600;font-size:12px;transition:all .15s}
.tk-col-add:hover{color:var(--g7-accent);border-color:var(--g7-accent-border)}

/* CALENDARIO */
.tk-cal-h{display:flex;align-items:center;gap:10px;margin-bottom:12px;flex-wrap:wrap}
.tk-cal-h h2{margin:0;font-size:19px;font-weight:800;letter-spacing:-.3px;min-width:170px;text-transform:capitalize}
.tk-cal-h .sp{flex:1}
.tk-cal{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));border:1px solid var(--g7-border-2);border-radius:14px;overflow:hidden;background:var(--g7-bg-3)}
.tk-cal .dow{padding:9px 10px;font-size:11px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.6px;border-bottom:1px solid var(--g7-border-2)}
.tk-day{min-height:118px;border-right:1px solid var(--g7-border);border-bottom:1px solid var(--g7-border);padding:6px;display:flex;flex-direction:column;gap:4px;cursor:pointer;transition:background .15s;min-width:0}
.tk-day:nth-child(7n){border-right:0}
.tk-day:hover{background:rgba(255,255,255,.02)}
.tk-day.out{background:rgba(0,0,0,.35)}
.tk-day.out .d{color:var(--g7-text-5)}
.tk-day.sel{box-shadow:inset 0 0 0 2px var(--g7-accent-border)}
.tk-day.over{background:var(--g7-accent-muted)}
.tk-day .d{font-size:12px;font-weight:700;color:var(--g7-text-3);display:flex;align-items:center;justify-content:space-between;font-variant-numeric:tabular-nums}
.tk-day.today .d span{background:var(--g7-accent);color:#fff;border-radius:6px;padding:1px 7px}
.tk-chip{display:flex;align-items:center;gap:6px;padding:3px 7px;border-radius:6px;font-size:11.5px;font-weight:600;background:color-mix(in srgb,var(--c) 16%,transparent);border-left:3px solid var(--c);overflow:hidden;white-space:nowrap;text-overflow:ellipsis;cursor:pointer;color:var(--g7-text)}
.tk-chip.done{opacity:.5;text-decoration:line-through}
.tk-chip:hover{filter:brightness(1.2)}
.tk-day .more{font-size:11px;color:var(--g7-text-4);padding-left:4px}
.tk-cal-foot{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:16px;margin-top:16px}
.tk-panel{background:var(--g7-bg-3);border:1px solid var(--g7-border-2);border-radius:14px;padding:14px}
.tk-panel h3{margin:0 0 10px;font-size:12px;text-transform:uppercase;letter-spacing:1px;color:var(--g7-text-3);display:flex;align-items:center;gap:8px}
.tk-mini{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:9px;cursor:pointer;border:1px solid transparent}
.tk-mini:hover{background:var(--g7-bg-4);border-color:var(--g7-border-2)}
.tk-mini .t{flex:1;min-width:0;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.tk-mini .bar{width:4px;align-self:stretch;border-radius:3px;background:var(--c)}

/* Menús emergentes */
.tk-pop{position:fixed;z-index:1200;min-width:200px;max-width:300px;max-height:min(380px,70dvh);overflow:auto;background:#141414;border:1px solid var(--g7-border-3);border-radius:12px;box-shadow:0 18px 50px rgba(0,0,0,.6);padding:6px;animation:tkpop .14s ease-out}
@keyframes tkpop{from{opacity:0;transform:translateY(-4px)}}
.tk-pop input.f{width:100%;height:32px;border-radius:8px;border:1px solid var(--g7-border-3);background:#0d0d0d;padding:0 10px;outline:0;margin-bottom:6px;font-size:12.5px}
.tk-opt{display:flex;align-items:center;gap:9px;padding:8px 9px;border-radius:8px;cursor:pointer;font-size:12.5px;font-weight:600;color:var(--g7-text-2)}
.tk-opt:hover,.tk-opt.hl{background:var(--g7-bg-5);color:var(--g7-text)}
.tk-opt .sw{width:12px;height:12px;border-radius:4px;background:var(--c);flex-shrink:0}
.tk-opt .ck{margin-left:auto;color:var(--g7-accent);font-size:11px}
.tk-opt.danger{color:#f87171}
.tk-pop .sep{height:1px;background:var(--g7-border-2);margin:5px 2px}
.tk-pop .hint{font-size:11px;color:var(--g7-text-4);padding:6px 9px}

/* FICHA DE LA TAREA (panel lateral) */
.tk-ov{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1000;opacity:0;pointer-events:none;transition:opacity .25s}
.tk-ov.open{opacity:1;pointer-events:auto}
.tk-drawer{position:fixed;top:0;right:0;bottom:0;width:min(640px,100vw);background:#0b0b0b;border-left:1px solid var(--g7-border-3);z-index:1001;transform:translateX(100%);transition:transform .3s cubic-bezier(.2,.8,.2,1);display:flex;flex-direction:column;box-shadow:-30px 0 60px rgba(0,0,0,.5)}
.tk-drawer.open{transform:none}
body:has(.tk-drawer.open) #cw{display:none!important}   /* el botón del chat tapaba "Crear tarea" */
.tk-dh{display:flex;align-items:center;gap:8px;padding:12px 16px;border-bottom:1px solid var(--g7-border-2)}
.tk-dh .sp{flex:1}
.tk-dh .saved{font-size:11.5px;color:var(--g7-text-4);transition:opacity .3s}
.tk-db{flex:1;overflow-y:auto;padding:18px 22px 30px}
.tk-df{padding:12px 16px;border-top:1px solid var(--g7-border-2);display:flex;gap:8px;justify-content:flex-end}
.tk-dtitle{width:100%;border:0;background:none;outline:0;resize:none;font-size:22px;font-weight:800;letter-spacing:-.4px;line-height:1.3;padding:4px 6px;margin:0 -6px 14px;border-radius:8px;overflow:hidden;color:var(--g7-text)}
.tk-dtitle:hover{background:rgba(255,255,255,.03)}
.tk-dtitle:focus{background:rgba(255,255,255,.04);box-shadow:inset 0 0 0 1px var(--g7-border-3)}
.tk-props{display:grid;grid-template-columns:130px minmax(0,1fr);gap:4px 10px;margin-bottom:22px}
.tk-props .k{display:flex;align-items:center;gap:8px;color:var(--g7-text-4);font-size:12.5px;font-weight:600;min-height:36px}
.tk-props .k i{width:14px;text-align:center}
.tk-props .v{display:flex;align-items:center;gap:6px;flex-wrap:wrap;min-height:36px}
.tk-pbtn{border:1px solid transparent;background:none;border-radius:8px;min-height:32px;padding:3px 8px;margin-left:-8px;display:flex;align-items:center;gap:7px;cursor:pointer;flex-wrap:wrap;text-align:left;transition:background .15s}
.tk-pbtn:hover{background:var(--g7-bg-4);border-color:var(--g7-border-2)}
.tk-pbtn .ph{color:var(--g7-text-5)}
.tk-date{height:32px;border-radius:8px;border:1px solid transparent;background:none;padding:0 8px;margin-left:-8px;outline:0;color-scheme:dark;cursor:pointer;font-size:13px}
.tk-date:hover,.tk-date:focus{background:var(--g7-bg-4);border-color:var(--g7-border-2)}
.tk-sec{margin-top:24px}
.tk-sec h4{margin:0 0 10px;font-size:11.5px;text-transform:uppercase;letter-spacing:1px;color:var(--g7-text-3);font-weight:700;display:flex;align-items:center;gap:8px}
.tk-sec h4 .sp{flex:1}
.tk-sec h4 .n{color:var(--g7-text-5)}
.tk-ta{width:100%;min-height:96px;border-radius:10px;border:1px solid var(--g7-border-2);background:var(--g7-bg-3);padding:11px 12px;outline:0;resize:vertical;line-height:1.6;font-size:13px}
.tk-ta:focus{border-color:var(--g7-accent-border)}
.tk-ta::placeholder{color:var(--g7-text-5)}
.tk-cont{display:grid;gap:8px}
.tk-ci{display:flex;align-items:center;gap:11px;padding:8px;border-radius:10px;background:var(--g7-bg-3);border:1px solid var(--g7-border-2)}
.tk-ci img,.tk-ci .noimg{width:48px;height:48px;border-radius:8px;object-fit:cover;background:var(--g7-bg-5);flex-shrink:0;display:flex;align-items:center;justify-content:center;color:var(--g7-text-5)}
.tk-ci .b{flex:1;min-width:0}
.tk-ci .b a{font-weight:700;color:var(--g7-text);text-decoration:none;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.tk-ci .b a:hover{color:var(--g7-accent)}
.tk-ci .b small{color:var(--g7-text-4);font-size:11.5px}
.tk-cm{display:flex;gap:10px;margin-bottom:14px}
.tk-cm .bd{flex:1;min-width:0}
.tk-cm .hd{display:flex;align-items:baseline;gap:8px;margin-bottom:4px}
.tk-cm .hd b{font-size:12.5px}
.tk-cm .hd small{color:var(--g7-text-4);font-size:11px}
.tk-cm .hd .sp{flex:1}
.tk-cm .hd button{opacity:0;transition:opacity .15s}
.tk-cm:hover .hd button{opacity:1}
.tk-cm .tx{background:var(--g7-bg-3);border:1px solid var(--g7-border-2);border-radius:4px 12px 12px 12px;padding:9px 12px;line-height:1.6;word-break:break-word;white-space:pre-wrap;font-size:13px}
.tk-cm .tx a{color:var(--g7-accent)}
.tk-cm .ft{display:flex;flex-wrap:wrap;gap:5px;align-items:center;margin-top:6px}
.tk-rx{display:inline-flex;align-items:center;gap:5px;height:26px;padding:0 9px;border-radius:999px;border:1px solid var(--g7-border-3);background:var(--g7-bg-4);font-size:13px;line-height:1;cursor:pointer;transition:background .15s,border-color .15s,transform .1s}
.tk-rx b{font-size:11px;font-weight:600;color:var(--g7-text-3)}
.tk-rx:hover{border-color:var(--g7-accent-border)}
.tk-rx:active{transform:scale(.94)}
.tk-rx.on{border-color:var(--g7-accent-border);background:var(--g7-accent-muted)}
.tk-rx.on b{color:var(--g7-accent)}
.tk-rx.add{color:var(--g7-text-4);gap:2px;padding:0 8px}
.tk-rx.add .fa-plus{font-size:8px}
.tk-rx.add:hover{color:var(--g7-accent)}
.tk-cm-a{background:none;border:0;color:var(--g7-text-4);font-size:11.5px;font-weight:600;cursor:pointer;padding:3px 6px;border-radius:6px;display:inline-flex;gap:5px;align-items:center;transition:color .15s,background .15s}
.tk-cm-a:hover{color:var(--g7-accent);background:var(--g7-bg-4)}
.tk-cm-a i{font-size:10px}
.tk-rx:focus-visible,.tk-cm-a:focus-visible,.tk-rxpick button:focus-visible{outline:2px solid var(--g7-accent);outline-offset:2px}
.tk-thread{margin-bottom:4px}
.tk-replies{margin:-6px 0 14px 40px;padding-left:12px;border-left:2px solid var(--g7-border-2)}
.tk-cm.reply{margin-bottom:10px}
.tk-cm.reply .tx{font-size:12.5px;padding:7px 11px}
.tk-rbox{display:flex;gap:8px;margin:2px 0 10px}
.tk-rbox .w{flex:1;min-width:0;display:flex;flex-direction:column;gap:6px}
.tk-rbox textarea{min-height:64px}
.tk-rbox .a{display:flex;gap:6px;justify-content:flex-end}
.tk-rxpick{display:flex;gap:2px;padding:4px;flex-wrap:wrap;max-width:300px}
.tk-rxpick button{width:36px;height:36px;border:0;background:none;border-radius:9px;font-size:19px;cursor:pointer;transition:transform .12s,background .12s}
.tk-rxpick button:hover{background:var(--g7-bg-5);transform:scale(1.15)}
@media (max-width:640px){.tk-replies{margin-left:14px;padding-left:10px}}
.tk-mention{color:var(--g7-accent);font-weight:700;background:var(--g7-accent-muted);border-radius:4px;padding:0 3px}
.tk-cbox{display:flex;gap:10px;align-items:flex-start}
.tk-cbox .w{flex:1;display:flex;flex-direction:column;gap:8px;align-items:flex-end}
.tk-cbox textarea{min-height:64px}
.tk-act{display:grid;gap:7px}
.tk-act div{font-size:12px;color:var(--g7-text-3);display:flex;gap:8px;line-height:1.5}
.tk-act div b{color:var(--g7-text-2)}
.tk-act div small{color:var(--g7-text-5);margin-left:auto;white-space:nowrap;padding-left:8px}
.tk-sec details summary{cursor:pointer;list-style:none}
.tk-sec details summary::-webkit-details-marker{display:none}
.tk-sec details[open] summary i.fa-chevron-right{transform:rotate(90deg)}
.tk-sec details summary i{transition:transform .2s}
.tk-note{font-size:12px;color:var(--g7-text-4);line-height:1.5}

/* Ventanas (configuración, mis avisos, buscar contenido) */
.tk-dlg{margin:max(16px,7vh) auto auto;border:1px solid var(--g7-border-3);background:#0d0d0d;color:var(--g7-text);border-radius:18px;padding:0;width:min(760px,calc(100vw - 24px));max-height:calc(100dvh - 32px);overflow:hidden;box-shadow:0 30px 80px rgba(0,0,0,.7)}
.tk-dlg::backdrop{background:rgba(0,0,0,.7)}
.tk-dlg-in{display:flex;flex-direction:column;max-height:calc(100dvh - 32px)}
.tk-dlg-h{display:flex;align-items:center;gap:10px;padding:16px 20px;border-bottom:1px solid var(--g7-border-2)}
.tk-dlg-h h3{margin:0;font-size:16px;font-weight:800;flex:1}
.tk-dlg-b{padding:16px 20px 20px;overflow-y:auto}
.tk-dlg-f{display:flex;align-items:center;gap:8px;justify-content:flex-end;padding:14px 20px;border-top:1px solid var(--g7-border-2)}
.tk-dlg-f .msg{margin-right:auto;font-size:12px;color:var(--g7-text-3)}
.tk-dlg-f .msg.err{color:#f87171}
.tk-tabs{display:flex;gap:4px;border-bottom:1px solid var(--g7-border-2);margin:-16px -20px 16px;padding:0 14px;overflow-x:auto}
.tk-tabs button{border:0;background:none;padding:12px 12px;color:var(--g7-text-3);font-weight:700;font-size:12.5px;cursor:pointer;border-bottom:2px solid transparent;white-space:nowrap}
.tk-tabs button.on{color:var(--g7-text);border-bottom-color:var(--g7-accent)}
.tk-ed{display:grid;gap:6px}
.tk-er{display:flex;align-items:center;gap:8px;padding:6px;border-radius:10px;background:var(--g7-bg-3);border:1px solid var(--g7-border-2)}
.tk-er input[type=color]{width:34px;height:30px;border:0;border-radius:7px;background:none;padding:0;cursor:pointer;flex-shrink:0}
.tk-er input[type=text]{flex:1;min-width:0;height:32px;border-radius:8px;border:1px solid var(--g7-border-2);background:var(--g7-bg);padding:0 10px;outline:0}
.tk-er input[type=text]:focus{border-color:var(--g7-accent-border)}
.tk-er .cnt{font-size:11.5px;color:var(--g7-text-4);white-space:nowrap;min-width:64px;text-align:right}
.tk-er.gone{opacity:.8;background:rgba(248,113,113,.05);border-color:rgba(248,113,113,.25);flex-wrap:wrap}
.tk-er.gone .nm{flex:1;text-decoration:line-through;color:var(--g7-text-3);padding-left:6px}
.tk-er.gone select{height:30px;border-radius:7px;border:1px solid var(--g7-border-3);background:var(--g7-bg);padding:0 8px}
.tk-ntable{width:100%;border-collapse:collapse}
.tk-ntable th{font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:var(--g7-text-4);text-align:center;padding:6px 8px;font-weight:700}
.tk-ntable th:first-child{text-align:left}
.tk-ntable td{padding:9px 8px;border-top:1px solid var(--g7-border);text-align:center}
.tk-ntable td:first-child{text-align:left;font-weight:600;color:var(--g7-text-2)}
.tk-ntable input{accent-color:var(--g7-accent);width:16px;height:16px;cursor:pointer}
.tk-ntable input:disabled{cursor:not-allowed;opacity:.35}
.tk-form{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:16px}
.tk-form label{display:flex;flex-direction:column;gap:6px;font-size:12px;color:var(--g7-text-3);font-weight:600}
.tk-form input[type=text],.tk-form input[type=number]{height:36px;border-radius:9px;border:1px solid var(--g7-border-2);background:var(--g7-bg);padding:0 10px;outline:0;color:var(--g7-text)}
.tk-form .full{grid-column:1/-1}
.tk-sw{display:flex!important;flex-direction:row!important;align-items:center;gap:10px!important;cursor:pointer;color:var(--g7-text-2)!important}
.tk-sw input{accent-color:var(--g7-accent);width:16px;height:16px}
.tk-ulist{display:flex;flex-wrap:wrap;gap:6px}
.tk-ulist label{flex-direction:row;align-items:center;gap:7px;padding:5px 10px 5px 6px;border-radius:9px;border:1px solid var(--g7-border-2);background:var(--g7-bg-3);cursor:pointer;color:var(--g7-text-2)}
.tk-ulist label:has(input:checked){border-color:var(--g7-accent-border);background:var(--g7-accent-muted);color:var(--g7-text)}
.tk-ulist input{display:none}
.tk-picker-list{display:grid;gap:6px;margin-top:12px}
.tk-picker-list .tk-ci{cursor:pointer;transition:border-color .15s}
.tk-picker-list .tk-ci:hover{border-color:var(--g7-accent-border)}
.tk-picker-list .tk-ci.on{border-color:var(--g7-accent);background:var(--g7-accent-muted)}
.tk-toast{position:fixed;bottom:22px;left:50%;transform:translateX(-50%) translateY(20px);background:#1c1c1c;border:1px solid var(--g7-border-3);color:#fff;padding:11px 18px;border-radius:11px;font-weight:600;font-size:13px;z-index:1300;opacity:0;transition:all .25s;pointer-events:none;box-shadow:0 12px 30px rgba(0,0,0,.5)}
.tk-toast.show{opacity:1;transform:translateX(-50%)}
.tk-toast.err{border-color:rgba(248,113,113,.5);color:#fca5a5}

@media (max-width:900px){
  .tk-cal-foot{grid-template-columns:1fr}
  .tk-day{min-height:84px}
  .tk-chip{font-size:10.5px;padding:2px 5px}
}
@media (max-width:640px){
  .tk-wrap{padding:18px 16px 50px}
  .tk-head h1{font-size:23px}
  .tk-bar{padding:8px}
  .tk-search{max-width:none;min-width:100%;order:5}
  .tk-client{flex:1}.tk-client select{max-width:none;flex:1}
  .tk-seg{width:100%}.tk-seg button{flex:1;justify-content:center;padding:0 6px}
  .tk-sel{flex:1 1 calc(50% - 5px);max-width:none}
  .tk-row{grid-template-columns:34px minmax(190px,1fr) 160px 130px 150px 120px 130px 112px;min-width:1030px}
  .tk-row>div:nth-child(2){position:sticky;left:0;z-index:1;background:var(--g7-bg-3);box-shadow:6px 0 10px -6px rgba(0,0,0,.6)}
  .tk-col{flex-basis:84vw}
  .tk-day{min-height:58px;padding:4px}
  .tk-day .tk-chip{display:none}
  .tk-day .more{display:none}
  .tk-day .dots{display:flex!important}
  .tk-cal .dow{padding:7px 4px;text-align:center;font-size:10px}
  .tk-props{grid-template-columns:104px minmax(0,1fr)}
  .tk-db{padding:16px 16px 26px}
  .tk-form{grid-template-columns:1fr}
  .tk-head-a .tk-btn .lb{display:none}
}
.tk-day .dots{display:none;gap:3px;flex-wrap:wrap}
.tk-day .dots i{width:6px;height:6px;border-radius:50%;background:var(--c)}
/* Pasos, adjuntos, horas, selección y plantillas */
.tk-sec h4 .tk-btn{text-transform:none;letter-spacing:0;font-weight:600}
.tk-split{display:inline-flex}
.tk-split .tk-btn:first-child{border-radius:9px 0 0 9px}
.tk-split .tk-btn:last-child{border-radius:0 9px 9px 0;padding:0 10px;margin-left:1px}
.tk-timer{border-color:rgba(239,68,68,.45);color:var(--g7-text);max-width:300px}
.tk-timer[hidden],.tk-client[hidden]{display:none}
.tk-timer .dot{width:8px;height:8px;border-radius:50%;background:#ef4444;box-shadow:0 0 0 0 rgba(239,68,68,.6);animation:tkpulse 1.6s infinite;flex:none}
.tk-timer .t{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0}
.tk-timer b{font-variant-numeric:tabular-nums}
.tk-timer .stop{width:22px;height:22px;border-radius:6px;display:inline-flex;align-items:center;justify-content:center;background:rgba(239,68,68,.15);color:#f87171;font-size:10px;flex:none}
.tk-timer .stop:hover{background:rgba(239,68,68,.3)}
@keyframes tkpulse{70%{box-shadow:0 0 0 7px rgba(239,68,68,0)}100%{box-shadow:0 0 0 0 rgba(239,68,68,0)}}
.tk-meta.ok{color:#4ade80}
.tk-meta.run{color:#f87171}
.tk-rep{font-size:10px;color:var(--g7-text-4)}
.tk-prog{position:relative;display:flex;align-items:center;height:6px;border-radius:6px;background:var(--g7-bg-5)}
.tk-prog>span{display:block;height:100%;border-radius:6px;background:var(--g7-accent);transition:width .3s}
.tk-prog.full>span{background:#22c55e}
.tk-prog b{position:absolute;right:0;top:-16px;font-size:10.5px;color:var(--g7-text-4);font-weight:600}
.tk-card .tk-prog{margin-top:12px}
.tk-prog.big{height:7px;margin-bottom:12px}
.tk-cks{display:flex;flex-direction:column;gap:2px}
.tk-ck{display:flex;align-items:center;gap:8px;padding:5px 6px;border-radius:8px;transition:background .15s}
.tk-ck:hover{background:var(--g7-bg-3)}
.tk-ck.dragging{opacity:.4}
.tk-ck .h{color:var(--g7-text-5);font-size:11px;cursor:grab;opacity:0;width:10px}
.tk-ck:hover .h{opacity:1}
.tk-ck input[type=checkbox]{accent-color:#22c55e;width:16px;height:16px;cursor:pointer;flex:none}
.tk-ck .b{flex:1;min-width:0;cursor:text;line-height:1.45;padding:2px 0}
.tk-ck.done .b{color:var(--g7-text-4);text-decoration:line-through}
.tk-ck small{color:var(--g7-text-5);font-size:11px;white-space:nowrap}
.tk-ck .tk-icon{width:26px;height:26px;opacity:0}
.tk-ck:hover .tk-icon,.tk-ck .tk-icon:focus-visible{opacity:1}
.tk-ck input.e{flex:1;height:30px;border-radius:7px;border:1px solid var(--g7-accent);background:var(--g7-bg);padding:0 9px;outline:0}
.tk-ck-add{display:flex;align-items:center;gap:10px;padding:4px 8px;margin-top:4px;color:var(--g7-text-4)}
.tk-ck-add input{flex:1;height:34px;border:1px dashed var(--g7-border-3);border-radius:8px;background:none;padding:0 10px;outline:0}
.tk-ck-add input:focus{border-style:solid;border-color:var(--g7-accent)}
#tk-files-sec.over{outline:2px dashed var(--g7-accent);outline-offset:6px;border-radius:10px}
.tk-fi{display:flex;align-items:center;gap:11px;padding:7px 8px;border-radius:10px;background:var(--g7-bg-3);border:1px solid var(--g7-border-2);margin-bottom:6px}
.tk-fi .ic{width:42px;height:42px;border-radius:8px;flex:none;display:flex;align-items:center;justify-content:center;background:var(--g7-bg-5);color:var(--g7-text-3);font-size:17px;overflow:hidden}
.tk-fi .ic img{width:100%;height:100%;object-fit:cover}
.tk-fi .b{flex:1;min-width:0;display:flex;flex-direction:column;gap:2px}
.tk-fi .b b{font-size:12.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tk-fi .b small{font-size:11px;color:var(--g7-text-4)}
.tk-fi .b small a{color:var(--g7-text-3)}
.tk-fi .bar{display:block;height:4px;border-radius:4px;background:var(--g7-bg-5);overflow:hidden;margin-top:3px}
.tk-fi .bar span{display:block;height:100%;background:var(--g7-accent);transition:width .2s}
.tk-fdrop{border:1.5px dashed var(--g7-border-3);border-radius:12px;padding:18px;text-align:center;color:var(--g7-text-4);font-size:12.5px;cursor:pointer;line-height:1.5}
.tk-fdrop i{display:block;font-size:20px;margin-bottom:6px}
.tk-fdrop:hover{border-color:var(--g7-accent-border);color:var(--g7-text-3)}
.tk-mp-f{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--g7-text-4);margin:14px 0 6px}
.tk-mp-f:first-child{margin-top:0}
.tk-mp-f i{color:#fbbf24}
.tk-mp{display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:9px;cursor:pointer}
.tk-mp:hover{background:var(--g7-bg-3)}
.tk-mp.off{opacity:.5;cursor:default}
.tk-mp input{accent-color:var(--g7-accent)}
.tk-mp span{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.tk-mp small{color:var(--g7-text-4);font-size:11px;white-space:nowrap}
.tk-btn.sm.running{border-color:rgba(239,68,68,.5);color:#f87171}
.tk-btn.sm.running span{font-variant-numeric:tabular-nums}
.tk-tm{display:flex;flex-direction:column;gap:2px}
.tk-tm-r{display:flex;align-items:center;gap:10px;padding:5px 6px;border-radius:8px}
.tk-tm-r:hover{background:var(--g7-bg-3)}
.tk-tm-r .w{flex:1;min-width:0;color:var(--g7-text-3);font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.tk-tm-r b{font-size:12.5px;font-variant-numeric:tabular-nums}
.tk-tm-r .tk-icon{width:26px;height:26px;opacity:0}
.tk-tm-r:hover .tk-icon{opacity:1}
.tk-live{color:#f87171;font-weight:600}
.tk-tm-sum{display:flex;flex-wrap:wrap;gap:8px 16px;margin-bottom:10px;font-size:12px;color:var(--g7-text-3)}
.tk-tm-sum span{display:inline-flex;align-items:center;gap:6px}
.tk-tm-f{display:grid;grid-template-columns:80px 80px 150px minmax(0,1fr);gap:8px;align-items:end;padding:12px;border-radius:12px;background:var(--g7-bg-3);border:1px solid var(--g7-border-2);margin-bottom:10px}
.tk-tm-f label{display:flex;flex-direction:column;gap:5px;font-size:11px;color:var(--g7-text-4);font-weight:600}
.tk-tm-f input{height:34px;border-radius:8px;border:1px solid var(--g7-border-2);background:var(--g7-bg);padding:0 9px;outline:0;color:var(--g7-text);min-width:0}
.tk-tm-f input:focus{border-color:var(--g7-accent)}
.tk-tm-f .a{grid-column:1/-1;display:flex;justify-content:flex-end;gap:6px}
.tk-bulk{position:fixed;left:50%;bottom:20px;transform:translate(-50%,160%);z-index:120;display:flex;align-items:center;gap:6px;flex-wrap:wrap;justify-content:center;max-width:calc(100vw - 24px);padding:10px 12px 10px 18px;border-radius:14px;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);box-shadow:0 16px 44px rgba(0,0,0,.55);transition:transform .25s cubic-bezier(.2,.8,.2,1);visibility:hidden}
.tk-bulk.on{transform:translate(-50%,0);visibility:visible}
.tk-bulk>b{font-size:13px;margin-right:6px;white-space:nowrap}
.tk-btn.danger{color:#f87171}
.tk-btn.danger:hover{border-color:rgba(239,68,68,.5)}
.tk-mine-sum{color:var(--g7-text-3);font-size:13px;margin:-4px 0 18px}
.tk-mine-sum b{color:var(--g7-text);font-size:15px}
.tk-tpl{display:flex;align-items:flex-start;gap:10px;padding:12px;border-radius:12px;background:var(--g7-bg-3);border:1px solid var(--g7-border-2);margin-bottom:8px}
.tk-tpl .b{flex:1;min-width:0;display:flex;flex-direction:column;gap:3px}
.tk-tpl small{color:var(--g7-text-4);font-size:11.5px}
.tk-tpl .ck{display:flex;flex-wrap:wrap;gap:4px;margin-top:5px}
.tk-tpl .ck span{font-size:11px;padding:2px 7px;border-radius:6px;background:var(--g7-bg-5);color:var(--g7-text-3)}
.tk-hr-bar{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:16px}
.tk-hr-bar input[type=date]{height:34px;border-radius:8px;border:1px solid var(--g7-border-2);background:var(--g7-bg);padding:0 8px;color:var(--g7-text)}
.tk-hr-bar .grow{flex:1}
.tk-hr-tot{display:flex;align-items:baseline;gap:10px;margin-bottom:16px;flex-wrap:wrap}
.tk-hr-tot b{font-size:28px;font-weight:800;letter-spacing:-.5px}
.tk-hr-tot span{color:var(--g7-text-3)}
.tk-hr-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}
.tk-hr-grid h4,.tk-hr-h4{margin:0 0 8px;font-size:11px;text-transform:uppercase;letter-spacing:1px;color:var(--g7-text-4)}
.tk-hr-h4{margin-top:20px}
.tk-hr-r{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(0,1fr) auto;align-items:center;gap:10px;padding:5px 0;font-size:12.5px}
.tk-hr-r span:first-child{display:flex;align-items:center;gap:6px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}
.tk-hr-r b{font-variant-numeric:tabular-nums}
.tk-hbar{height:6px;border-radius:6px;background:var(--g7-bg-5);overflow:hidden}
.tk-hbar span{display:block;height:100%;background:var(--g7-accent);border-radius:6px}
.tk-hr-list{display:flex;flex-direction:column;max-height:300px;overflow:auto;border:1px solid var(--g7-border-2);border-radius:10px}
.tk-hr-row{display:grid;grid-template-columns:62px 22px minmax(0,1.3fr) minmax(0,1fr) auto;align-items:center;gap:10px;padding:8px 12px;border-bottom:1px solid var(--g7-border);font-size:12.5px}
.tk-hr-row:last-child{border-bottom:0}
.tk-hr-row .d{color:var(--g7-text-4);font-size:11.5px}
.tk-hr-row a{color:var(--g7-text);text-decoration:none;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;font-weight:600}
.tk-hr-row a:hover{color:var(--g7-accent)}
.tk-hr-row .c{color:var(--g7-text-4);overflow:hidden;white-space:nowrap;text-overflow:ellipsis}
.tk-hr-row b{font-variant-numeric:tabular-nums}
@media (max-width:640px){.tk-hr-grid{grid-template-columns:1fr}.tk-hr-row{grid-template-columns:52px 22px minmax(0,1fr) auto}.tk-hr-row .c{display:none}.tk-tm-f{grid-template-columns:1fr 1fr}.tk-tm-f .w{grid-column:1/-1}.tk-timer .t{display:none}}
@media (prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}}
</style>
<link rel="stylesheet" href="/toolz/tareas2.css?v=<?= $v('tareas2.css') ?>">
</head>
<body>
<?php include __DIR__ . '/topbar.php'; ?>
<main class="tk-wrap" id="tk-app">
  <div class="tk-head">
    <div>
      <a class="tk-back" href="/toolz/trabajo/#tableros"><i class="fa-solid fa-arrow-left"></i> Gestión de trabajo</a>
      <h1><i class="fa-solid <?= htmlspecialchars($wkBoard['icon']) ?> tk-bicon" style="color:<?= htmlspecialchars($wkBoard['color']) ?>"></i><?= htmlspecialchars($wkBoard['name']) ?><span>.</span></h1>
      <p><?= $wkBoard['description'] !== '' ? htmlspecialchars($wkBoard['description']) : 'Pedidos, trabajo en curso y entregas del equipo, por marca.' ?></p>
    </div>
    <div class="tk-head-a">
      <button class="tk-btn tk-timer" type="button" id="tk-timer" hidden title="Reloj andando: abre la tarea"></button>
      <button class="tk-btn" type="button" id="tk-hours-btn" title="Horas por cliente y por persona"><i class="fa-regular fa-clock"></i><span class="lb">Horas</span></button>
      <button class="tk-btn" type="button" id="tk-prefs-btn" title="Elige qué avisos te llegan"><i class="fa-regular fa-bell"></i><span class="lb">Mis avisos</span></button>
      <?php if ($isAdmin): ?><button class="tk-btn" type="button" id="tk-cfg-btn" title="Estados, prioridades, etiquetas y avisos"><i class="fa-solid fa-sliders"></i><span class="lb">Configurar</span></button><?php endif; ?>
      <span class="tk-split"><button class="tk-btn primary" type="button" id="tk-new-btn"><i class="fa-solid fa-plus"></i> Nueva tarea</button><button class="tk-btn primary" type="button" id="tk-tpl-btn" title="Crear desde una plantilla" aria-label="Crear desde una plantilla"><i class="fa-solid fa-chevron-down"></i></button></span>
    </div>
  </div>

  <div class="tk-bar" role="toolbar" aria-label="Vista y filtros">
    <div class="tk-seg" id="tk-views">
      <button type="button" data-view="list"><i class="fa-solid fa-table-list"></i> Lista</button>
      <button type="button" data-view="mine"><i class="fa-solid fa-user-check"></i> Mis tareas</button>
      <button type="button" data-view="kanban"><i class="fa-solid fa-table-columns"></i> Kanban</button>
      <button type="button" data-view="calendar"><i class="fa-regular fa-calendar"></i> Calendario</button>
    </div>
    <label class="tk-client" id="f-group-w" title="Cómo se arman los grupos de la Lista"><i class="fa-solid fa-layer-group"></i><select id="f-group" aria-label="Agrupar por">
      <option value="status">Agrupar por estado</option><option value="client">Agrupar por marca</option><option value="user">Agrupar por responsable</option><option value="prio">Agrupar por prioridad</option></select></label>
    <label class="tk-client" title="Ver todas las tareas o solo las de una marca"><i class="fa-solid fa-tag"></i><select id="f-client" aria-label="Marca"></select></label>
    <select class="tk-sel" id="f-user" aria-label="Responsable"></select>
    <select class="tk-sel" id="f-prio" aria-label="Prioridad"></select>
    <select class="tk-sel" id="f-label" aria-label="Etiqueta" hidden></select>
    <div class="tk-search"><i class="fa-solid fa-magnifying-glass"></i><input id="f-q" type="search" placeholder="Buscar tareas…" autocomplete="off"></div>
    <span class="grow"></span>
    <label class="tk-check"><input type="checkbox" id="f-arch"> Archivadas</label>
    <span class="tk-count" id="tk-count"></span>
  </div>

  <div id="tk-view"><div class="tk-loading"><div class="tk-sk"></div><div class="tk-sk"></div><div class="tk-sk"></div></div></div>
</main>

<div class="tk-ov" id="tk-ov"></div>
<aside class="tk-drawer" id="tk-drawer" aria-label="Tarea" aria-hidden="true"></aside>
<dialog class="tk-dlg" id="tk-dlg"></dialog>
<div class="tk-toast" id="tk-toast" role="status"></div>

<script type="application/json" data-global="TK_BOARD"><?= (int)$wkBoard['id'] ?></script><script type="application/json" data-global="TK_CLI"><?= json_encode(wk_cli_label($db), JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="/toolz/assets/json-globals.js?v=<?= (int)@filemtime(__DIR__ . '/assets/json-globals.js') ?>"></script>
<script src="/toolz/tareas.js?v=<?= $v('tareas.js') ?>"></script>
<script src="/toolz/tareas2.js?v=<?= $v('tareas2.js') ?>"></script>
</body>
</html>
