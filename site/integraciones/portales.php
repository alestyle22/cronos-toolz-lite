<?php
/**
 * integraciones/portales.php — Portales inmobiliarios: el feed de propiedades disponibles (XML compatible con Trovit/Mitula
 * y la mayoría de los agregadores, y JSON propio) y la ficha pública para compartir una propiedad con un interesado.
 * El feed se lee con una URL con token (feed.php?t=…); la ficha con un link firmado (ficha.php?p=…&s=…).
 * Publicar directo en Mercado Libre queda como siguiente paso (INTEGRACIONES.md).
 * Doc: toolz/INTEGRACIONES.md
 */
require_once __DIR__ . '/lib.php';

const PT_TYPES_TROVIT = ['departamento' => 'Apartment', 'casa' => 'House', 'ph' => 'House', 'local' => 'Commercial', 'oficina' => 'Office', 'terreno' => 'Land',
    'cochera' => 'Parking', 'galpon' => 'Warehouse', 'campo' => 'Land', 'otro' => 'Other'];

function pt_feed_token(): string {
    $t = (string)(int_cfg('portales')['feed_token'] ?? '');
    if ($t === '') { $t = bin2hex(random_bytes(16)); int_store_update(function ($d) use ($t) { $d['portales']['feed_token'] = $t; return $d; }); }
    return $t;
}
function pt_feed_regen(): string {
    $t = bin2hex(random_bytes(16)); int_store_update(function ($d) use ($t) { $d['portales']['feed_token'] = $t; return $d; });
    int_log('portales', 'feed', true, 'Se generó un token nuevo: el link anterior del feed dejó de funcionar');
    return $t;
}
function pt_feed_url(string $fmt = 'xml'): string { return int_site_url() . '/toolz/integraciones/feed.php?t=' . pt_feed_token() . ($fmt === 'json' ? '&f=json' : ''); }
// ── Fichas públicas (SEC-37, 2026-11-10) ───────────────────
// Los links nuevos vencen (e = fecha en segundos, 0 = no vence) y la firma lleva la "versión" de los links de esa propiedad:
// revocar sube la versión y todos sus links (nuevos y viejos) dejan de andar. Los links viejos (sin e) siguen valiendo
// hasta que se revoque esa propiedad, para no romper los que ya se mandaron.
const PT_SHARE_DAYS = [7, 30, 90, 0];   // vigencias que se pueden elegir al compartir (0 = sin vencimiento)
function pt_share_rev(int $propId): int { return (int)((int_cfg('portales')['share_rev'][(string)$propId] ?? 0)); }
function pt_share_url(int $propId, int $days = 90): string {
    if (!in_array($days, PT_SHARE_DAYS, true)) $days = 90;
    $exp = $days > 0 ? strtotime('today +' . ($days + 1) . ' days') - 1 : 0;   // vale hasta el final del último día
    $sig = substr(int_sign("ficha2|$propId|$exp|" . pt_share_rev($propId)), 0, 22);
    return int_site_url() . '/toolz/integraciones/ficha.php?p=' . $propId . '&e=' . $exp . '&s=' . $sig;
}
/** ¿Vale el link? $exp null = link viejo (sin vencimiento en la URL). */
function pt_share_ok(int $propId, string $sig, ?int $exp = null): bool {
    if ($sig === '' || $propId <= 0) return false;
    $rev = pt_share_rev($propId);
    if ($exp === null) return $rev === 0 && hash_equals(substr(int_sign("ficha|$propId"), 0, 22), $sig);
    if ($exp !== 0 && $exp < time()) return false;
    return hash_equals(substr(int_sign("ficha2|$propId|$exp|$rev"), 0, 22), $sig);
}
/** Revoca todos los links de la ficha de una propiedad (los que ya se mandaron dejan de andar). */
function pt_share_revoke(int $propId): int {
    $d = int_store_update(function ($d) use ($propId) { $r = (int)($d['portales']['share_rev'][(string)$propId] ?? 0); $d['portales']['share_rev'][(string)$propId] = $r + 1; return $d; });
    return (int)$d['portales']['share_rev'][(string)$propId];
}

/** Las propiedades que salen en el feed: disponibles y sin archivar. Ya listas para publicar (sin datos privados). */
function pt_listings(PDO $db, ?int $onlyId = null): array {
    if (!is_file(dirname(__DIR__) . '/propiedades/lib.php')) return [];   // edición lite: sin Propiedades no hay nada que publicar
    require_once dirname(__DIR__) . '/propiedades/lib.php';
    $c = int_cfg('portales'); $base = int_site_url();
    $sql = 'SELECT * FROM g7_props WHERE archived = 0 AND ' . ($onlyId ? 'id = ' . (int)$onlyId : "status = 'disponible'") . ' ORDER BY updated_at DESC LIMIT 2000';
    $out = [];
    foreach ($db->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $p = pr_shape($p);
        // url: el feed se relee seguido, así que el link no vence (revocar la ficha igual lo corta)
        $out[] = ['id' => $p['code'], 'internal_id' => (int)$p['id'], 'url' => pt_share_url((int)$p['id'], 0), 'title' => $p['title'],
            'operation' => $p['operation'], 'operation_label' => PR_OPERATIONS[$p['operation']] ?? $p['operation'], 'kind' => $p['kind'], 'kind_label' => PR_KINDS[$p['kind']] ?? $p['kind'],
            'status' => $p['status'], 'price' => !empty($c['show_price']) ? $p['price'] : null, 'currency' => $p['currency'], 'expenses' => !empty($c['show_price']) ? $p['expenses'] : null,
            'address' => !empty($c['show_address']) ? $p['address'] : '', 'zone' => $p['zone'], 'city' => $p['city'],
            'rooms' => $p['rooms'], 'bedrooms' => $p['bedrooms'], 'bathrooms' => $p['bathrooms'], 'm2_total' => $p['m2_total'], 'm2_covered' => $p['m2_covered'], 'age' => $p['age'],
            'amenities' => array_values(array_map(fn($a) => PR_AMENITIES[$a] ?? $a, $p['amenities'])), 'description' => (string)$p['description'],
            'photos' => array_map(fn($f) => $base . $f['url'], $p['photos']), 'updated_at' => $p['updated_at']];
    }
    return $out;
}
function pt_contact(): array {
    $c = int_cfg('portales');
    return ['name' => (string)(g7_setting('platform_name') ?: g7_site('name')), 'phone' => (string)$c['contact_phone'], 'email' => (string)$c['contact_email']];
}
function pt_feed_json(array $items): string {
    return json_encode(['generated_at' => date('c'), 'agency' => pt_contact(), 'count' => count($items), 'properties' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}
/** XML con el formato de Trovit (lo aceptan Trovit, Mitula, Nestoria y la mayoría de los agregadores). */
function pt_feed_xml(array $items): string {
    $x = new XMLWriter(); $x->openMemory(); $x->setIndent(true); $x->startDocument('1.0', 'UTF-8');
    $x->startElement('trovit'); $ct = pt_contact();
    $cd = function (string $tag, $v) use ($x) { if ($v === null || $v === '') return; $x->startElement($tag); $x->writeCdata(str_replace(']]>', ']] >', (string)$v)); $x->endElement(); };
    foreach ($items as $p) {
        $x->startElement('ad');
        $cd('id', $p['id']); $cd('url', $p['url']); $cd('title', $p['title']);
        $cd('type', $p['operation'] === 'venta' ? 'For Sale' : 'For Rent');
        $cd('content', $p['description']);
        if ($p['price'] !== null) { $x->startElement('price'); $x->writeAttribute('currency', $p['currency']); if ($p['operation'] !== 'venta') $x->writeAttribute('period', $p['operation'] === 'temporario' ? 'daily' : 'monthly'); $x->writeCdata(number_format((float)$p['price'], 0, '.', '')); $x->endElement(); }
        $cd('property_type', PT_TYPES_TROVIT[$p['kind']] ?? 'Other');
        $cd('address', $p['address']); $cd('neighborhood', $p['zone']); $cd('city', $p['city']); $cd('country', 'Argentina');
        if ($p['m2_total']) { $x->startElement('floor_area'); $x->writeAttribute('unit', 'meters'); $x->writeCdata((string)(int)$p['m2_total']); $x->endElement(); }
        $cd('rooms', $p['bedrooms'] ?? $p['rooms']); $cd('bathrooms', $p['bathrooms']);
        if ($p['photos']) { $x->startElement('pictures'); foreach ($p['photos'] as $u) { $x->startElement('picture'); $cd('picture_url', $u); $x->endElement(); } $x->endElement(); }
        $cd('agency', $ct['name']); $cd('contact_telephone', $ct['phone']); $cd('contact_email', $ct['email']);
        $cd('date', substr((string)$p['updated_at'], 0, 10));
        $x->endElement();
    }
    $x->endElement(); $x->endDocument();
    return $x->outputMemory();
}
function pt_test(PDO $db): array {
    try { $n = count(pt_listings($db)); } catch (Throwable $e) { return [false, 'No se pudo leer Propiedades: ' . $e->getMessage()]; }
    return [true, "El feed está listo con $n propiedad" . ($n === 1 ? '' : 'es') . ' disponible' . ($n === 1 ? '' : 's') . '.'];
}
