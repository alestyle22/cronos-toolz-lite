<?php
/**
 * integraciones/ficha.php — Ficha pública de una propiedad para compartir con un interesado (link firmado: ficha.php?p=ID&s=FIRMA).
 * Muestra fotos, datos y descripción, sin notas privadas ni propietario. Se arma desde Propiedades › Compartir.
 * Doc: toolz/INTEGRACIONES.md
 */
require_once __DIR__ . '/portales.php';
require_once dirname(__DIR__) . '/csp_lib.php'; g7_csp_enforce();   // CSP estricta en modo bloqueo: esta página no tiene código en línea (SEC-24)
header('X-Robots-Tag: noindex, nofollow'); header('Referrer-Policy: no-referrer');
$id = (int)($_GET['p'] ?? 0); $sig = (string)($_GET['s'] ?? ''); $exp = isset($_GET['e']) ? (int)$_GET['e'] : null;
$p = null;
if (int_ready('portales') && $id > 0 && pt_share_ok($id, $sig, $exp)) { try { $p = pt_listings(getDB(), $id)[0] ?? null; } catch (Throwable $e) { error_log('[ficha] ' . $e->getMessage()); } }
if (!$p) http_response_code(404);
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$site = (string)(g7_setting('platform_name') ?: g7_site('name')); $acc = (string)(g7_setting('accent_color') ?: '#ff6600'); $logo = (string)g7_site('logo');
$ct = pt_contact(); $avail = $p && in_array($p['status'], ['disponible', 'reservada'], true);
$price = $p && $p['price'] !== null ? ($p['currency'] === 'USD' ? 'US$ ' : '$ ') . number_format((float)$p['price'], 0, ',', '.') . ($p['operation'] === 'venta' ? '' : ($p['operation'] === 'temporario' ? ' por día' : ' por mes')) : 'Consultar precio';
$facts = $p ? array_filter([$p['kind_label'], $p['m2_total'] ? (int)$p['m2_total'] . ' m² totales' : '', $p['m2_covered'] ? (int)$p['m2_covered'] . ' m² cubiertos' : '', $p['rooms'] ? $p['rooms'] . ($p['rooms'] == 1 ? ' ambiente' : ' ambientes') : '',
    $p['bedrooms'] ? $p['bedrooms'] . ($p['bedrooms'] == 1 ? ' dormitorio' : ' dormitorios') : '', $p['bathrooms'] ? $p['bathrooms'] . ($p['bathrooms'] == 1 ? ' baño' : ' baños') : '', $p['age'] !== null ? ($p['age'] ? $p['age'] . ' años' : 'A estrenar') : '',
    $p['expenses'] ? 'Expensas $ ' . number_format((float)$p['expenses'], 0, ',', '.') : '']) : [];
$wa = $ct['phone'] !== '' && $p ? 'https://wa.me/' . preg_replace('/\D/', '', $ct['phone']) . '?text=' . rawurlencode('Hola, me interesa la propiedad ' . $p['id'] . ' (' . $p['title'] . ').') : '';
?><!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title><?= $p ? $h($p['title']) . ' · ' : '' ?><?= $h($site) ?></title>
<?php if ($p && $p['photos']): ?><meta property="og:image" content="<?= $h($p['photos'][0]) ?>"><meta property="og:title" content="<?= $h($p['title']) ?>"><?php endif; ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css" integrity="sha384-h/hnnw1Bi4nbpD6kE7nYfCXzovi622sY5WBxww8ARKwpdLj5kUWjRuyiXaD1U2JT" crossorigin="anonymous">
<style>
:root{--acc:<?= $h($acc) ?>;--bg:#f5f6f8;--card:#fff;--ink:#14171c;--dim:#4a5160;--line:rgba(15,20,30,.1)}
*{box-sizing:border-box}body{margin:0;font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:var(--bg);color:var(--ink)}
header{display:flex;align-items:center;gap:10px;padding:12px 18px;background:var(--card);border-bottom:1px solid var(--line)}header img{height:28px}header b{font-size:14px}
main{max-width:980px;margin:0 auto;padding:18px 16px 40px}
.gal{display:grid;grid-template-columns:2fr 1fr;gap:8px;border-radius:14px;overflow:hidden}.gal img{width:100%;height:100%;object-fit:cover;display:block;cursor:zoom-in;background:#ddd}
.gal .main{grid-row:span 2;aspect-ratio:4/3}.gal .side{aspect-ratio:4/3}.gal.one{grid-template-columns:1fr}.gal.one .main{aspect-ratio:16/9;max-height:460px}.gal.one .side{display:none}
.thumbs{display:flex;gap:6px;overflow-x:auto;margin-top:8px}.thumbs img{height:64px;border-radius:8px;cursor:pointer;opacity:.85}
.top{display:grid;grid-template-columns:1fr auto;gap:16px;margin:18px 0 8px;align-items:start}
h1{font-size:24px;margin:0 0 4px}.loc{color:var(--dim)}.op{display:inline-block;background:var(--acc);color:#fff;border-radius:999px;padding:3px 10px;font-size:12px;font-weight:700;margin-bottom:8px}
.price{font-size:24px;font-weight:800;text-align:right}.code{color:var(--dim);font-size:12px;text-align:right}
.card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:16px;margin-top:12px}
.facts{display:flex;flex-wrap:wrap;gap:8px}.facts span{background:var(--bg);border-radius:8px;padding:6px 10px;font-size:13px}
.desc{white-space:pre-line;line-height:1.6;color:var(--dim)}
.cta{display:flex;gap:10px;flex-wrap:wrap}.cta a{display:inline-flex;gap:8px;align-items:center;padding:11px 16px;border-radius:10px;text-decoration:none;font-weight:700}
.cta .wa{background:#25d366;color:#fff}.cta .em{border:1px solid var(--line);color:var(--ink)}
.gone{text-align:center;padding:60px 20px}.gone i{font-size:40px;color:var(--dim)}
#lb{position:fixed;inset:0;background:rgba(0,0,0,.9);display:none;align-items:center;justify-content:center;z-index:9}#lb img{max-width:94vw;max-height:90vh}#lb.on{display:flex}
@media (max-width:640px){.gal{grid-template-columns:1fr}.gal .side{display:none}.top{grid-template-columns:1fr}.price,.code{text-align:left}}
</style></head><body>
<header><?php if ($logo): ?><img src="<?= $h($logo) ?>" alt=""><?php endif; ?><b><?= $h($site) ?></b></header>
<main>
<?php if (!$p || !$avail): ?>
  <div class="gone"><i class="fa-solid fa-house-circle-xmark"></i><h1><?= $p ? 'Esta propiedad ya no está disponible' : 'No encontramos esta propiedad' ?></h1><p class="loc">Escríbenos y te mostramos otras parecidas.</p>
  <?php if ($wa || $ct['email']): ?><div class="cta" style="justify-content:center"><?php if ($ct['phone']): ?><a class="wa" href="https://wa.me/<?= $h(preg_replace('/\D/', '', $ct['phone'])) ?>"><i class="fa-brands fa-whatsapp"></i> WhatsApp</a><?php endif; ?><?php if ($ct['email']): ?><a class="em" href="mailto:<?= $h($ct['email']) ?>"><i class="fa-regular fa-envelope"></i> Email</a><?php endif; ?></div><?php endif; ?></div>
<?php else: $ph = $p['photos']; ?>
  <?php if ($ph): ?><div class="gal<?= count($ph) < 3 ? ' one' : '' ?>"><img class="main" src="<?= $h($ph[0]) ?>" alt="<?= $h($p['title']) ?>"><?php foreach (array_slice($ph, 1, 2) as $u): ?><img class="side" src="<?= $h($u) ?>" alt=""><?php endforeach; ?></div>
  <?php $rest = count($ph) < 3 ? array_slice($ph, 1) : array_slice($ph, 3); if ($rest): ?><div class="thumbs"><?php foreach ($rest as $u): ?><img src="<?= $h($u) ?>" alt="" loading="lazy"><?php endforeach; ?></div><?php endif; endif; ?>
  <div class="top"><div><span class="op"><?= $h($p['operation_label']) ?></span><?= $p['status'] === 'reservada' ? ' <span class="op" style="background:#f59e0b">Reservada</span>' : '' ?><h1><?= $h($p['title']) ?></h1>
    <div class="loc"><i class="fa-solid fa-location-dot"></i> <?= $h(implode(', ', array_filter([$p['address'], $p['zone'], $p['city']]))) ?></div></div>
    <div><div class="price"><?= $h($price) ?></div><div class="code">Código <?= $h($p['id']) ?></div></div></div>
  <?php if ($facts): ?><div class="card facts"><?php foreach ($facts as $f): ?><span><?= $h($f) ?></span><?php endforeach; ?></div><?php endif; ?>
  <?php if ($p['description'] !== ''): ?><div class="card"><div class="desc"><?= $h($p['description']) ?></div></div><?php endif; ?>
  <?php if ($p['amenities']): ?><div class="card facts"><?php foreach ($p['amenities'] as $a): ?><span><i class="fa-solid fa-check" style="color:var(--acc)"></i> <?= $h($a) ?></span><?php endforeach; ?></div><?php endif; ?>
  <?php if ($wa || $ct['email']): ?><div class="card"><b>¿Te interesa?</b><p class="loc" style="margin:6px 0 12px">Escríbenos y coordinamos una visita.</p><div class="cta">
    <?php if ($wa): ?><a class="wa" href="<?= $h($wa) ?>"><i class="fa-brands fa-whatsapp"></i> WhatsApp</a><?php endif; ?>
    <?php if ($ct['email']): ?><a class="em" href="mailto:<?= $h($ct['email']) ?>?subject=<?= rawurlencode('Propiedad ' . $p['id']) ?>"><i class="fa-regular fa-envelope"></i> <?= $h($ct['email']) ?></a><?php endif; ?></div></div><?php endif; ?>
<?php endif; ?>
</main>
<div id="lb"><img alt=""></div>
<script src="ficha.js?v=<?= (int)@filemtime(__DIR__ . '/ficha.js') ?>"></script>
</body></html>
