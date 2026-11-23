<?php
/**
 * social/report_lib.php — Social Studio, Stage 5: Report Maker. Reports for clients with the synced data of Analytics.
 *  - Each report freezes a "snapshot" (the data of that moment): the history does not change on its own. "Update data" builds it again.
 *  - Sections (cover, summary, metrics, chart, networks, accounts, top posts, goals, conclusions, recommendations),
 *    agency brand (name and logo of the site) and client brand (logo and color of the brand or client).
 *  - Two forms of PDF (the user's decision): the printable page (social/report.php, beautiful) and the server PDF (EvPdf, for the
 *    email and the automatic monthly report). Public link with a token that can be revoked (social_report.php).
 *  - Templates, history, duplicate, send by email (with the PDF attached and the link) and automatic monthly report.
 *  - Automatic summary written from the data (it does not invent anything); conclusions with AI only as a draft to review.
 * Permission: reports. Isolation by brand. Doc: toolz/SOCIAL_STUDIO.md (Stage 5)
 */
require_once __DIR__ . '/analytics_lib.php';

const SS_REP_SECTIONS = ['cover' => 'Portada', 'summary' => 'Resumen', 'kpis' => 'Métricas principales', 'chart' => 'Evolución por día', 'networks' => 'Comparación entre redes',
    'accounts' => 'Por cuenta', 'top' => 'Publicaciones destacadas', 'goals' => 'Objetivos del mes', 'conclusions' => 'Conclusiones', 'recommendations' => 'Recomendaciones'];
const SS_REP_DEFAULT_TPL = [
    ['Informe mensual completo', ['cover', 'summary', 'kpis', 'chart', 'networks', 'accounts', 'top', 'goals', 'conclusions', 'recommendations'], 'interactions'],
    ['Resumen ejecutivo', ['cover', 'summary', 'kpis', 'top', 'conclusions'], 'reach'],
];

function ss_rep_seed(PDO $db): void {
    if ((int)$db->query('SELECT COUNT(*) FROM g7_ss_report_tpl')->fetchColumn()) return;
    $ins = $db->prepare('INSERT INTO g7_ss_report_tpl (name, sections, metric, intro, is_default, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
    foreach (SS_REP_DEFAULT_TPL as $i => [$n, $s, $m]) $ins->execute([$n, json_encode($s), $m, '', $i === 0 ? 1 : 0, ss_now()]);
}

// ═══════════════════════════════════════════════════════════
// SCOPE (brand / client / all) AND ACCESS
// ═══════════════════════════════════════════════════════════
function ss_rep_scope_check(PDO $db, array $ctx, string $scope, int $sid): void {
    if ($scope === 'brand' && $sid) {
        if (!isset(g7_brands(true)[$sid])) ss_fail('Esa marca no existe.');
        if ($ctx['brands'] !== null && !in_array($sid, $ctx['brands'], true)) ss_fail('No tenés acceso a esa marca.', 403);
    } elseif ($scope === 'client' && $sid) {
        $q = $db->prepare('SELECT 1 FROM g7_clients WHERE id = ?'); $q->execute([$sid]); if (!$q->fetchColumn()) ss_fail('Ese cliente no existe.');
    }
}
function ss_rep_row(PDO $db, array $ctx, int $id): array {
    ss_need($ctx, 'reports');
    $q = $db->prepare('SELECT * FROM g7_ss_reports WHERE id = ?'); $q->execute([$id]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!$r || ($r['scope'] === 'brand' && $r['scope_id'] && $ctx['brands'] !== null && !in_array((int)$r['scope_id'], $ctx['brands'], true))) ss_fail('Ese informe no existe o es de una marca que no ves.', 404);
    return $r;
}
/** Brand of the client for the report: name, logo and color (of the brand of Cronos or of the client's file). */
function ss_rep_party(PDO $db, string $scope, int $sid): array {
    if ($scope === 'brand' && $sid && ($b = g7_brands(true)[$sid] ?? null)) return ['name' => $b['name'], 'logo' => (string)$b['logo_url'], 'color' => $b['color'] ?: '#ff6600'];
    if ($scope === 'client' && $sid) {
        $q = $db->prepare('SELECT * FROM g7_clients WHERE id = ?'); $q->execute([$sid]);
        if ($c = $q->fetch(PDO::FETCH_ASSOC)) {
            $col = '#ff6600'; if (preg_match('/#[0-9a-f]{6}/i', (string)($c['brand_colors'] ?? ''), $m)) $col = $m[0];
            return ['name' => $c['name'], 'logo' => function_exists('cli_avatar_url') ? (string)cli_avatar_url($c) : '', 'color' => $col];
        }
    }
    return ['name' => 'Todas las cuentas', 'logo' => '', 'color' => '#ff6600'];
}
function ss_rep_agency(): array {
    $logo = (string)g7_site('logo', '');
    return ['name' => (string)((function_exists('g7_setting') ? g7_setting('platform_name') : '') ?: g7_site('name')), 'logo' => $logo !== '' ? (str_starts_with($logo, 'http') ? $logo : g7_site_origin() . '/' . ltrim($logo, '/')) : ''];
}

// ═══════════════════════════════════════════════════════════
// BUILD, EDIT, DUPLICATE
// ═══════════════════════════════════════════════════════════
/** Takes the data of Analytics for the scope and range and freezes it in the report, with the automatic summary. */
function ss_rep_build(PDO $db, array $ctx, array $r): array {
    $f = ['from' => $r['date_from'], 'to' => $r['date_to'], 'compare' => $r['compare']];
    if ($r['scope_id']) $f[$r['scope']] = (int)$r['scope_id'];
    $c2 = $ctx; $c2['can']['analytics'] = true;   // whoever builds reports reads the metrics of the brands they see (the scope does not change)
    $an = ss_an_report($db, $c2, $f);
    $snap = ['an' => $an, 'party' => ss_rep_party($db, $r['scope'], (int)$r['scope_id']), 'agency' => ss_rep_agency(), 'built_at' => ss_now(), 'auto_summary' => ss_rep_auto_summary($an)];
    $db->prepare('UPDATE g7_ss_reports SET snapshot = ?, built_at = ?, updated_at = ? WHERE id = ?')->execute([json_encode($snap, JSON_UNESCAPED_UNICODE), ss_now(), ss_now(), (int)$r['id']]);
    return $snap;
}
/** Summary in words, only with what the data says (without AI): main changes, top post, goals. */
function ss_rep_auto_summary(array $an): array {
    $L = []; $n = fn($v) => number_format((float)$v, 0, ',', '.');
    $K = array_column($an['kpis'], null, 'key');
    foreach (['reach', 'views', 'interactions', 'followers', 'new_followers'] as $k) {
        if (!isset($K[$k]) || $K[$k]['value'] === null) continue;
        $x = $K[$k]; $lbl = mb_strtolower($x['label']);
        if ($k === 'followers') { $L[] = (count($an['accounts'] ?? []) > 1 ? 'Las cuentas cerraron el período con ' : 'La cuenta cerró el período con ') . $n($x['value']) . ' seguidores' . ($an['growth'] !== null ? ' (' . ($an['growth'] >= 0 ? '+' : '') . $n($an['growth']) . ' en el período)' : '') . '.'; continue; }
        $L[] = ucfirst($lbl) . ': ' . $n($x['value']) . ($x['delta'] !== null ? ' (' . ($x['delta'] >= 0 ? '+' : '') . str_replace('.', ',', (string)$x['delta']) . '% contra ' . ($an['compare'] === 'year' ? 'el año anterior' : 'el período anterior') . ')' : '') . '.';
    }
    if ($an['engagement']['value'] !== null) $L[] = 'Engagement: ' . str_replace('.', ',', (string)$an['engagement']['value']) . '% de interacciones sobre ' . $an['engagement']['base'] . '.';
    if ($an['top']) { $t = $an['top'][0]; $L[] = 'La publicación con más interacciones fue de ' . $t['account'] . ' (' . $n($t['interactions']) . ' interacciones' . ($t['engagement'] !== null ? ', ' . str_replace('.', ',', (string)$t['engagement']) . '% de engagement' : '') . ').'; }
    foreach ($an['goals'] as $g) if ($g['pct'] !== null) $L[] = 'Objetivo de ' . mb_strtolower($g['label']) . ': ' . $g['pct'] . '% del mes' . ($g['on_track'] === false ? ' (viene atrasado)' : '') . '.';
    if (!$L) $L[] = 'Todavía no hay métricas sincronizadas para este período.';
    return $L;
}
function ss_rep_tpl(PDO $db, ?int $id): array {
    ss_rep_seed($db);
    $q = $db->prepare('SELECT * FROM g7_ss_report_tpl WHERE id = ?'); $q->execute([(int)$id]);
    $t = $q->fetch(PDO::FETCH_ASSOC) ?: $db->query('SELECT * FROM g7_ss_report_tpl ORDER BY is_default DESC, id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $t['sections'] = array_values(array_intersect(json_decode((string)$t['sections'], true) ?: [], array_keys(SS_REP_SECTIONS)));
    return $t;
}
/** New report. $in: title, scope, scope_id, from, to, compare, template_id. */
function ss_rep_create(PDO $db, array $ctx, array $in, bool $auto = false): int {
    ss_need($ctx, 'reports');
    $scope = in_array($in['scope'] ?? '', ['brand', 'client', 'all'], true) ? $in['scope'] : 'brand'; $sid = $scope === 'all' ? 0 : (int)($in['scope_id'] ?? 0);
    ss_rep_scope_check($db, $ctx, $scope, $sid);
    [$from, $to] = ss_an_range(['from' => $in['from'] ?? null, 'to' => $in['to'] ?? null]);
    $tpl = ss_rep_tpl($db, isset($in['template_id']) ? (int)$in['template_id'] : null);
    $party = ss_rep_party($db, $scope, $sid);
    $title = mb_substr(trim((string)($in['title'] ?? '')), 0, 160) ?: 'Informe de redes · ' . $party['name'];
    $now = ss_now();
    $db->prepare('INSERT INTO g7_ss_reports (title, scope, scope_id, date_from, date_to, compare, template_id, sections, texts, auto, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
       ->execute([$title, $scope, $sid, $from, $to, in_array($in['compare'] ?? '', ['prev', 'year', 'none'], true) ? $in['compare'] : 'prev', (int)$tpl['id'],
                  json_encode(['list' => $tpl['sections'], 'metric' => $tpl['metric']]), json_encode(['summary' => (string)$tpl['intro'], 'conclusions' => '', 'recommendations' => '']), $auto ? 1 : 0, $ctx['id'] ?: null, $now, $now]);
    $id = (int)$db->lastInsertId();
    ss_rep_build($db, $ctx, ss_rep_row_any($db, $id));
    if (!$auto) g7_audit('social.report_create', '#' . $id, ['scope' => "$scope:$sid", 'from' => $from, 'to' => $to]);
    return $id;
}
function ss_rep_row_any(PDO $db, int $id): array { $q = $db->prepare('SELECT * FROM g7_ss_reports WHERE id = ?'); $q->execute([$id]); return $q->fetch(PDO::FETCH_ASSOC); }
function ss_rep_update(PDO $db, array $ctx, int $id, array $in): void {
    $r = ss_rep_row($db, $ctx, $id);
    $set = []; $a = [];
    if (isset($in['title'])) { $t = mb_substr(trim((string)$in['title']), 0, 160); if ($t === '') ss_fail('Ponele un título.'); $set[] = 'title = ?'; $a[] = $t; }
    if (isset($in['sections']) && is_array($in['sections'])) {
        $list = array_values(array_unique(array_intersect(array_map('strval', $in['sections']['list'] ?? []), array_keys(SS_REP_SECTIONS))));
        if (!$list) ss_fail('Elegí al menos una sección.');
        $metric = isset(SS_AN_METRICS[$in['sections']['metric'] ?? '']) ? $in['sections']['metric'] : 'interactions';
        $set[] = 'sections = ?'; $a[] = json_encode(['list' => $list, 'metric' => $metric]);
    }
    if (isset($in['texts']) && is_array($in['texts'])) {
        $t = []; foreach (['summary', 'conclusions', 'recommendations'] as $k) $t[$k] = mb_substr(trim((string)($in['texts'][$k] ?? '')), 0, 6000);
        $set[] = 'texts = ?'; $a[] = json_encode($t, JSON_UNESCAPED_UNICODE);
    }
    if (isset($in['status']) && in_array($in['status'], ['draft', 'final'], true) && $r['status'] !== 'sent') { $set[] = 'status = ?'; $a[] = $in['status']; }
    if (!$set) return;
    $set[] = 'updated_at = ?'; $a[] = ss_now();
    $db->prepare('UPDATE g7_ss_reports SET ' . implode(', ', $set) . ' WHERE id = ?')->execute([...$a, $id]);
}
function ss_rep_refresh(PDO $db, array $ctx, int $id): void { $r = ss_rep_row($db, $ctx, $id); ss_rep_build($db, $ctx, $r); }
function ss_rep_duplicate(PDO $db, array $ctx, int $id, array $in = []): int {
    $r = ss_rep_row($db, $ctx, $id);
    $now = ss_now();
    [$from, $to] = isset($in['from'], $in['to']) ? ss_an_range($in) : [$r['date_from'], $r['date_to']];
    $db->prepare('INSERT INTO g7_ss_reports (title, scope, scope_id, date_from, date_to, compare, template_id, sections, texts, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
       ->execute([mb_substr('Copia de ' . $r['title'], 0, 160), $r['scope'], (int)$r['scope_id'], $from, $to, $r['compare'], $r['template_id'], $r['sections'], $r['texts'], $ctx['id'], $now, $now]);
    $nid = (int)$db->lastInsertId();
    ss_rep_build($db, $ctx, ss_rep_row_any($db, $nid));
    return $nid;
}
function ss_rep_delete(PDO $db, array $ctx, int $id): void {
    $r = ss_rep_row($db, $ctx, $id);
    $db->prepare('DELETE FROM g7_ss_reports WHERE id = ?')->execute([$id]);
    g7_audit('social.report_delete', '#' . $id, ['title' => $r['title']]);
}
function ss_rep_out(array $r, bool $full = false): array {
    $snap = $full ? (json_decode((string)$r['snapshot'], true) ?: []) : [];
    $o = ['id' => (int)$r['id'], 'title' => $r['title'], 'scope' => $r['scope'], 'scope_id' => (int)$r['scope_id'], 'from' => $r['date_from'], 'to' => $r['date_to'], 'compare' => $r['compare'],
          'status' => $r['status'], 'auto' => (bool)$r['auto'], 'sent_to' => $r['sent_to'], 'sent_at' => $r['sent_at'], 'built_at' => $r['built_at'], 'updated_at' => $r['updated_at'],
          'link' => $r['token'] ? g7_site_origin() . '/toolz/social_report.php?t=' . $r['token'] : null];
    if ($full) $o += ['sections' => json_decode((string)$r['sections'], true) ?: [], 'texts' => json_decode((string)$r['texts'], true) ?: [], 'auto_summary' => $snap['auto_summary'] ?? [],
                      'last_sync' => $snap['an']['last_sync'] ?? null, 'party' => $snap['party'] ?? null];
    return $o;
}
function ss_rep_list(PDO $db, array $ctx): array {
    ss_need($ctx, 'reports');
    $w = $ctx['brands'] === null ? '1' : "(scope <> 'brand' OR scope_id = 0" . ($ctx['brands'] ? ' OR scope_id IN (' . implode(',', array_map('intval', $ctx['brands'])) . ')' : '') . ')';
    return array_map('ss_rep_out', $db->query("SELECT * FROM g7_ss_reports WHERE $w ORDER BY created_at DESC, id DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC));
}
/** Public link: creates it (or a new one: the previous one stops working) or revokes it. */
function ss_rep_link(PDO $db, array $ctx, int $id, bool $on): ?string {
    ss_rep_row($db, $ctx, $id);
    $tok = $on ? bin2hex(random_bytes(20)) : null;
    $db->prepare('UPDATE g7_ss_reports SET token = ?, updated_at = ? WHERE id = ?')->execute([$tok, ss_now(), $id]);
    g7_audit('social.report_link', '#' . $id, ['on' => $on]);
    return $tok ? g7_site_origin() . '/toolz/social_report.php?t=' . $tok : null;
}

// ═══════════════════════════════════════════════════════════
// AI (draft only)
// ═══════════════════════════════════════════════════════════
function ss_rep_ai(PDO $db, array $ctx, int $id): array {
    $r = ss_rep_row($db, $ctx, $id);
    if (!ss_plan_allows('social_ai')) ss_plan_fail('las sugerencias con IA');
    require_once dirname(__DIR__) . '/ia_lib.php';
    if (!function_exists('g7_claude_ready') || !g7_claude_ready()) ss_fail('La IA no está configurada en este sitio (Admin › Inteligencia artificial).');
    if (!ss_rate_ok($db, 'repai:' . $ctx['id'], 10, 3600)) ss_fail('Pediste muchas sugerencias seguidas: esperá un rato.', 429);
    $snap = json_decode((string)$r['snapshot'], true) ?: [];
    $an = $snap['an'] ?? [];
    $data = ['periodo' => $r['date_from'] . ' a ' . $r['date_to'], 'comparacion' => $an['compare'] ?? '', 'metricas' => array_map(fn($k) => [$k['label'], $k['value'], $k['prev'], $k['delta']], $an['kpis'] ?? []),
             'engagement' => $an['engagement'] ?? null, 'cuentas' => array_map(fn($a) => [$a['name'], $a['network'], $a['totals']], $an['accounts'] ?? []),
             'destacadas' => array_map(fn($t) => [$t['account'], $t['type'], mb_substr((string)$t['caption'], 0, 120), $t['metrics'], $t['engagement']], array_slice($an['top'] ?? [], 0, 5)), 'objetivos' => $an['goals'] ?? []];
    $sys = 'Sos analista de redes de una agencia. Escribís para el cliente, en español rioplatense, claro y concreto. Usás SOLO los números que te pasan: no inventes datos, ni causas que no se desprendan de ellos. Si un dato falta, no lo menciones.';
    try {
        $o = g7_claude_json($sys, 'Datos del informe (JSON): ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\nEscribí 3 a 5 conclusiones breves y 3 a 5 recomendaciones accionables para el próximo período.",
            ['type' => 'object', 'properties' => ['conclusions' => ['type' => 'string'], 'recommendations' => ['type' => 'string']], 'required' => ['conclusions', 'recommendations'], 'additionalProperties' => false],
            ['maxTokens' => 2000, 'effort' => 'low']);
    } catch (Throwable $e) { error_log('[social rep ia] ' . $e->getMessage()); ss_fail('La IA no respondió. Probá de nuevo en un rato.', 502); }
    return ['conclusions' => mb_substr(trim((string)($o['conclusions'] ?? '')), 0, 6000), 'recommendations' => mb_substr(trim((string)($o['recommendations'] ?? '')), 0, 6000)];
}

// ═══════════════════════════════════════════════════════════
// HTML VIEW (printable; the same for the public link)
// ═══════════════════════════════════════════════════════════
function ss_rep_nf($v): string { return $v === null ? '—' : number_format((float)$v, 0, ',', '.'); }
/** Line chart in SVG (prints sharp and works without JavaScript). */
function ss_svg_line(array $labels, array $cur, ?array $prev, string $color, int $w = 900, int $h = 240): string {
    $vals = array_filter(array_merge($cur, $prev ?? []), fn($v) => $v !== null);
    if (!$vals) return '<p class="muted">Sin datos por día en este período.</p>';
    $max = max(1, max($vals)); $n = max(1, count($labels) - 1); $pl = 46; $pb = 26; $W = $w - $pl - 10; $H = $h - $pb - 12;
    $pt = function (array $s) use ($max, $n, $pl, $W, $H) { $o = []; foreach ($s as $i => $v) if ($v !== null) $o[] = round($pl + $i / $n * $W, 1) . ',' . round(12 + $H - $v / $max * $H, 1); return implode(' ', $o); };
    $g = '';
    for ($i = 0; $i <= 4; $i++) { $y = round(12 + $H - $i / 4 * $H, 1); $g .= "<line x1=\"$pl\" y1=\"$y\" x2=\"" . ($pl + $W) . "\" y2=\"$y\" stroke=\"#e5e7eb\"/><text x=\"" . ($pl - 6) . "\" y=\"" . ($y + 4) . "\" font-size=\"10\" text-anchor=\"end\" fill=\"#6b7280\">" . ss_rep_nf($max * $i / 4) . '</text>'; }
    $step = max(1, (int)ceil(count($labels) / 8));
    foreach ($labels as $i => $l) if ($i % $step === 0) $g .= '<text x="' . round($pl + $i / $n * $W, 1) . '" y="' . ($h - 6) . '" font-size="10" text-anchor="middle" fill="#6b7280">' . date('d/m', strtotime($l)) . '</text>';
    return "<svg viewBox=\"0 0 $w $h\" width=\"100%\" role=\"img\" aria-label=\"Evolución por día\">$g"
        . ($prev ? '<polyline fill="none" stroke="#9ca3af" stroke-width="2" stroke-dasharray="5 4" points="' . $pt($prev) . '"/>' : '')
        . '<polyline fill="none" stroke="' . htmlspecialchars($color) . '" stroke-width="2.5" points="' . $pt($cur) . '"/></svg>';
}
/** Report as a full HTML page. $public = without Cronos buttons (only Print). */
function ss_rep_html(array $r, bool $public = false): string {
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $snap = json_decode((string)$r['snapshot'], true) ?: []; $an = $snap['an'] ?? []; $P = $snap['party'] ?? ['name' => '', 'logo' => '', 'color' => '#ff6600']; $AG = $snap['agency'] ?? ss_rep_agency();
    $sec = json_decode((string)$r['sections'], true) ?: ['list' => array_keys(SS_REP_SECTIONS), 'metric' => 'interactions']; $tx = json_decode((string)$r['texts'], true) ?: [];
    $col = preg_match('/^#[0-9a-f]{6}$/i', (string)$P['color']) ? $P['color'] : '#ff6600';
    $fd = fn($d) => date('d/m/Y', strtotime($d));
    $cmp = ($an['compare'] ?? 'prev') === 'none' ? '' : ' · comparado con ' . ($an['compare'] === 'year' ? 'el mismo período del año anterior' : 'el período anterior') . ' (' . $fd($an['prev_from']) . ' – ' . $fd($an['prev_to']) . ')';
    $delta = fn($d) => $d === null ? '' : '<span class="d ' . ($d > 0 ? 'up' : ($d < 0 ? 'down' : '')) . '">' . ($d > 0 ? '▲ +' : ($d < 0 ? '▼ ' : '')) . str_replace('.', ',', (string)$d) . '%</span>';
    $para = fn($t) => '<p>' . nl2br($h($t)) . '</p>';
    $out = [];
    foreach ($sec['list'] as $s) switch ($s) {
        case 'cover':
            $out[] = '<section class="cover" style="--c:' . $h($col) . '"><div class="brands">' . ($AG['logo'] ? '<img src="' . $h($AG['logo']) . '" alt="' . $h($AG['name']) . '">' : '<b>' . $h($AG['name']) . '</b>')
                . '<span class="x">×</span>' . ($P['logo'] ? '<img src="' . $h($P['logo']) . '" alt="' . $h($P['name']) . '">' : '<b>' . $h($P['name']) . '</b>') . '</div>'
                . '<p class="k">Informe de redes sociales</p><h1>' . $h($r['title']) . '</h1><p class="per">' . $h($fd($r['date_from']) . ' – ' . $fd($r['date_to'])) . $h($cmp) . '</p>'
                . '<p class="by">Preparado por ' . $h($AG['name']) . ' para ' . $h($P['name']) . '</p></section>'; break;
        case 'summary':
            $out[] = '<section><h2>Resumen</h2>' . (trim((string)($tx['summary'] ?? '')) !== '' ? $para($tx['summary']) : '') . '<ul class="auto">' . implode('', array_map(fn($l) => '<li>' . $h($l) . '</li>', $snap['auto_summary'] ?? [])) . '</ul></section>'; break;
        case 'kpis':
            if (empty($an['kpis'])) break;
            $out[] = '<section><h2>Métricas principales</h2><div class="kpis">' . implode('', array_map(fn($k) => '<div class="kpi"><small>' . $h($k['label']) . '</small><b>' . ss_rep_nf($k['value']) . '</b>' . $delta($k['delta'])
                . ($k['prev'] !== null ? '<em>antes: ' . ss_rep_nf($k['prev']) . '</em>' : '') . '</div>', $an['kpis']))
                . (($an['engagement']['value'] ?? null) !== null ? '<div class="kpi"><small>Engagement</small><b>' . str_replace('.', ',', (string)$an['engagement']['value']) . '%</b>' . $delta($an['engagement']['delta']) . '<em>sobre ' . $h($an['engagement']['base']) . '</em></div>' : '')
                . '</div></section>'; break;
        case 'chart':
            $m = $sec['metric'] ?? 'interactions'; $series = $an['series'][$m] ?? null;
            if (!$series) { $k = array_keys($an['series'] ?? []); if ($k) { $m = in_array('interactions', $k, true) ? 'interactions' : $k[0]; $series = $an['series'][$m]; } }
            if (!$series) break;
            $out[] = '<section><h2>' . $h(SS_AN_METRICS[$m][0] ?? $m) . ' por día</h2>' . ss_svg_line($an['labels'], $series['cur'], $series['prev'] ?? null, $col)
                . '<p class="legend"><span style="background:' . $h($col) . '"></span>Este período' . (!empty($series['prev']) ? ' <span class="dash"></span>Período de comparación' : '') . '</p></section>'; break;
        case 'networks':
            if (count($an['networks'] ?? []) < 2) break;
            $nets = array_keys($an['networks']); $nn = fn($n) => SS_NETWORKS[$n][0] ?? $n;
            $ks = array_values(array_filter(['reach', 'views', 'interactions', 'new_followers', 'followers', 'watch_minutes'], fn($k) => (bool)array_filter($nets, fn($n) => isset($an['networks'][$n][$k]))));
            $out[] = '<section><h2>' . $h(implode(' vs. ', array_map($nn, $nets))) . '</h2><table><tr><th>Métrica</th>' . implode('', array_map(fn($n) => '<th class="r">' . $h($nn($n)) . '</th>', $nets)) . '</tr>'
                . implode('', array_map(fn($k) => '<tr><td>' . $h(SS_AN_METRICS[$k][0]) . '</td>' . implode('', array_map(fn($n) => '<td class="r">' . ss_rep_nf($an['networks'][$n][$k] ?? null) . '</td>', $nets)) . '</tr>', $ks)) . '</table></section>'; break;
        case 'accounts':
            if (empty($an['accounts'])) break;
            $cols = array_values(array_filter(['followers', 'reach', 'views', 'interactions'], fn($k) => (bool)array_filter($an['accounts'], fn($a) => isset($a['totals'][$k]))));
            $out[] = '<section><h2>Por cuenta</h2><table><tr><th>Cuenta</th>' . implode('', array_map(fn($k) => '<th class="r">' . $h(SS_AN_METRICS[$k][0]) . '</th>', $cols)) . '<th class="r">Engagement</th></tr>'
                . implode('', array_map(fn($a) => '<tr><td>' . (SS_NETWORKS[$a['network']][0] ?? $a['network']) . ' · ' . $h($a['name']) . '</td>' . implode('', array_map(fn($k) => '<td class="r">' . ss_rep_nf($a['totals'][$k] ?? null) . '</td>', $cols))
                . '<td class="r">' . ($a['engagement'] === null ? '—' : str_replace('.', ',', (string)$a['engagement']) . '%') . '</td></tr>', $an['accounts'])) . '</table></section>'; break;
        case 'top':
            if (empty($an['top'])) break;
            $out[] = '<section><h2>Publicaciones destacadas</h2><div class="posts">' . implode('', array_map(fn($p) => '<div class="post">' . ($p['thumb'] ? '<img src="' . $h($p['thumb']) . '" alt="" referrerpolicy="no-referrer">' : '<div class="ph"></div>')
                . '<div><small>' . $h($p['account']) . ' · ' . $h(date('d/m/Y', strtotime((string)$p['published_at']))) . '</small><p>' . $h(mb_substr((string)$p['caption'], 0, 140)) . '</p><p class="ms">'
                . implode(' · ', array_map(fn($k, $v) => $h(SS_AN_POST_METRICS[$k] ?? $k) . ': ' . ss_rep_nf($v), array_keys($p['metrics']), $p['metrics'])) . ($p['engagement'] !== null ? ' · Engagement: ' . str_replace('.', ',', (string)$p['engagement']) . '%' : '')
                . '</p>' . ($p['permalink'] ? '<a href="' . $h($p['permalink']) . '">Ver la publicación</a>' : '') . '</div></div>', array_slice($an['top'], 0, 6))) . '</div></section>'; break;
        case 'goals':
            if (empty($an['goals'])) break;
            $out[] = '<section><h2>Objetivos del mes</h2>' . implode('', array_map(fn($g) => '<div class="goal"><div><b>' . $h($g['name']) . '</b> · ' . $h($g['label']) . ': ' . ss_rep_nf($g['value']) . ' de ' . ss_rep_nf($g['target']) . ($g['pct'] !== null ? ' (' . $g['pct'] . '%)' : '') . '</div><div class="bar"><i style="width:' . min(100, (int)$g['pct']) . '%;background:' . $h($col) . '"></i></div></div>', $an['goals'])) . '</section>'; break;
        case 'conclusions': if (trim((string)($tx['conclusions'] ?? '')) !== '') $out[] = '<section><h2>Conclusiones</h2>' . $para($tx['conclusions']) . '</section>'; break;
        case 'recommendations': if (trim((string)($tx['recommendations'] ?? '')) !== '') $out[] = '<section><h2>Recomendaciones</h2>' . $para($tx['recommendations']) . '</section>'; break;
    }
    $foot = 'Datos de Meta (API oficial)' . (!empty($an['last_sync']) ? ' actualizados el ' . date('d/m/Y H:i', strtotime($an['last_sync'])) : '') . ' · informe armado el ' . date('d/m/Y H:i', strtotime((string)($snap['built_at'] ?? $r['built_at']))) . '. Meta puede demorar las métricas hasta 48 h.';
    return '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . $h($r['title']) . '</title>'
        . '<link rel="stylesheet" href="/toolz/social/report.css?v=' . (int)@filemtime(__DIR__ . '/report.css') . '"></head><body style="--c:' . $h($col) . '">'
        . '<div class="tools"><button type="button" id="rp-print">Imprimir o guardar como PDF</button>' . ($public ? '' : '<a href="/toolz/social/report.php?id=' . (int)$r['id'] . '&amp;pdf=1">PDF del servidor</a><a href="/toolz/social/#informes/' . (int)$r['id'] . '">Volver a editar</a>') . '</div>'
        . '<main class="rep">' . implode('', $out) . '<footer>' . $h($foot) . '</footer></main><script src="/toolz/social/report-print.js?v=' . (int)@filemtime(__DIR__ . '/report-print.js') . '"></script></body></html>';
}

// ═══════════════════════════════════════════════════════════
// SERVER PDF (EvPdf: for the email and the automatic reports)
// ═══════════════════════════════════════════════════════════
function ss_rep_pdf(array $r): string {
    require_once dirname(__DIR__) . '/eventos/pdf_lib.php';
    $snap = json_decode((string)$r['snapshot'], true) ?: []; $an = $snap['an'] ?? []; $P = $snap['party'] ?? ['name' => '', 'color' => '#ff6600']; $AG = $snap['agency'] ?? ss_rep_agency();
    $sec = json_decode((string)$r['sections'], true) ?: ['list' => array_keys(SS_REP_SECTIONS), 'metric' => 'interactions']; $tx = json_decode((string)$r['texts'], true) ?: [];
    $col = preg_match('/^#[0-9a-f]{6}$/i', (string)$P['color']) ? $P['color'] : '#ff6600';
    $pdf = new EvPdf(); $W = 595.28; $M = 42; $y = 0;
    $newPage = function () use ($pdf, &$y, $M, $W, $r, $col) { $pdf->page(); $pdf->rect(0, 0, $W, 6, $col); $pdf->text($M, 24, $r['title'], 8, '#6b7280'); $y = 44; };
    $need = function (float $hh) use (&$y, $newPage) { if ($y + $hh > 800) $newPage(); };
    $h2 = function (string $t) use ($pdf, &$y, $M, $need, $col) { $need(40); $y += 10; $pdf->text($M, $y + 14, $t, 15, '#111111', true); $pdf->rect($M, $y + 20, 36, 2.5, $col); $y += 32; };
    $para = function (string $t, float $size = 10.5) use ($pdf, &$y, $M, $W, $need) { foreach (preg_split('/\n+/', trim($t)) as $line) { $need(30); $y = $pdf->para($M, $y + 4, $W - 2 * $M, $line, $size, '#374151') + 4; } };
    $fd = fn($d) => date('d/m/Y', strtotime($d));
    foreach ($sec['list'] as $s) switch ($s) {
        case 'cover':
            $pdf->page(); $pdf->rect(0, 0, $W, 841.89, '#111111'); $pdf->rect(0, 0, 10, 841.89, $col);
            $pdf->text(60, 200, mb_strtoupper('Informe de redes sociales'), 10, $col, true);
            $y = $pdf->para(60, 250, $W - 120, $r['title'], 28, '#ffffff', true, 1.2, 4);
            $pdf->text(60, $y + 20, $fd($r['date_from']) . ' – ' . $fd($r['date_to']), 13, '#d1d5db');
            $pdf->text(60, 760, 'Preparado por ' . $AG['name'] . ' para ' . $P['name'], 10, '#9ca3af');
            $y = 900; break;   // the next section starts on a page of its own
        case 'summary':
            if ($y === 0 || $y > 800) $newPage();
            $h2('Resumen'); if (trim((string)($tx['summary'] ?? '')) !== '') $para($tx['summary']);
            foreach ($snap['auto_summary'] ?? [] as $l) $para('• ' . $l); break;
        case 'kpis':
            if (empty($an['kpis'])) break;
            if ($y === 0 || $y > 800) $newPage();
            $h2('Métricas principales'); $cw = ($W - 2 * $M - 20) / 3; $i = 0;
            foreach ($an['kpis'] as $k) {
                if ($i % 3 === 0) $need(70);
                $x = $M + ($i % 3) * ($cw + 10); $pdf->rect($x, $y, $cw, 60, '#f3f4f6', 6);
                $pdf->text($x + 10, $y + 16, $k['label'], 8.5, '#6b7280', true); $pdf->text($x + 10, $y + 38, ss_rep_nf($k['value']), 17, '#111111', true);
                if ($k['delta'] !== null) $pdf->text($x + 10, $y + 52, ($k['delta'] >= 0 ? '+' : '') . str_replace('.', ',', (string)$k['delta']) . '% vs. antes', 8.5, $k['delta'] >= 0 ? '#16a34a' : '#dc2626', true);
                if (++$i % 3 === 0) $y += 70;
            }
            if ($i % 3) $y += 70; break;
        case 'chart':
            $m = $sec['metric'] ?? 'interactions'; $series = $an['series'][$m] ?? null;
            if (!$series) { $k = array_keys($an['series'] ?? []); if ($k) { $m = in_array('interactions', $k, true) ? 'interactions' : $k[0]; $series = $an['series'][$m]; } }
            if (!$series) break;
            if ($y === 0 || $y > 800) $newPage();
            $h2((SS_AN_METRICS[$m][0] ?? $m) . ' por día'); $need(170);
            $vals = array_values(array_filter($series['cur'], fn($v) => $v !== null)); $max = max(1, $vals ? max($vals) : 1);
            $n = count($series['cur']); $bw = ($W - 2 * $M) / max(1, $n);
            foreach ($series['cur'] as $i => $v) if ($v) { $bh = $v / $max * 140; $pdf->rect($M + $i * $bw + 0.5, $y + 150 - $bh, max(1, $bw - 1), $bh, $col); }
            $pdf->line($M, $y + 150, $W - $M, $y + 150, '#9ca3af');
            $pdf->text($M, $y + 164, date('d/m', strtotime($an['labels'][0])), 8, '#6b7280'); $pdf->text($W - $M, $y + 164, date('d/m', strtotime(end($an['labels']))), 8, '#6b7280', false, 'R');
            $pdf->text($M, $y + 8, 'Máx. ' . ss_rep_nf($max), 8, '#6b7280'); $y += 176; break;
        case 'accounts':
        case 'networks':
            if (empty($an['accounts']) || ($s === 'networks' && count($an['networks'] ?? []) < 2)) break;
            if ($y === 0 || $y > 800) $newPage();
            $h2($s === 'accounts' ? 'Por cuenta' : 'Comparación entre redes');
            $rows = $s === 'accounts' ? array_map(fn($a) => [(SS_NETWORKS[$a['network']][0] ?? $a['network']) . ' · ' . $a['name'], $a['totals']], $an['accounts']) : array_map(fn($n) => [SS_NETWORKS[$n][0] ?? $n, $an['networks'][$n]], array_keys($an['networks']));
            $cols = ['followers', 'reach', 'views', 'interactions']; $cw = ($W - 2 * $M - 170) / 4;
            $pdf->text($M, $y + 10, 'Cuenta', 8.5, '#6b7280', true); foreach ($cols as $j => $k) $pdf->text($M + 170 + ($j + 1) * $cw, $y + 10, SS_AN_METRICS[$k][0], 8.5, '#6b7280', true, 'R'); $y += 18;
            foreach ($rows as [$name, $t]) { $need(20); $pdf->text($M, $y + 10, mb_substr($name, 0, 34), 10, '#111111'); foreach ($cols as $j => $k) $pdf->text($M + 170 + ($j + 1) * $cw, $y + 10, ss_rep_nf($t[$k] ?? null), 10, '#111111', false, 'R'); $pdf->line($M, $y + 16, $W - $M, $y + 16, '#e5e7eb'); $y += 20; }
            break;
        case 'top':
            if (empty($an['top'])) break;
            if ($y === 0 || $y > 800) $newPage();
            $h2('Publicaciones destacadas');
            foreach (array_slice($an['top'], 0, 6) as $p) {
                $need(54);
                $pdf->text($M, $y + 10, $p['account'] . ' · ' . date('d/m/Y', strtotime((string)$p['published_at'])), 8.5, '#6b7280');
                $pdf->text($M, $y + 24, mb_substr((string)$p['caption'] ?: 'Sin texto', 0, 90), 10, '#111111', true);
                $pdf->text($M, $y + 38, implode(' · ', array_map(fn($k, $v) => (SS_AN_POST_METRICS[$k] ?? $k) . ': ' . ss_rep_nf($v), array_keys($p['metrics']), $p['metrics'])), 8.5, '#374151');
                $y += 52;
            }
            break;
        case 'goals':
            if (empty($an['goals'])) break;
            if ($y === 0 || $y > 800) $newPage();
            $h2('Objetivos del mes');
            foreach ($an['goals'] as $g) { $need(34); $pdf->text($M, $y + 10, $g['name'] . ' · ' . $g['label'] . ': ' . ss_rep_nf($g['value']) . ' de ' . ss_rep_nf($g['target']) . ($g['pct'] !== null ? ' (' . $g['pct'] . '%)' : ''), 10, '#111111');
                $pdf->rect($M, $y + 16, $W - 2 * $M, 6, '#e5e7eb', 3); $pdf->rect($M, $y + 16, ($W - 2 * $M) * min(100, (int)$g['pct']) / 100, 6, $col, 3); $y += 32; }
            break;
        case 'conclusions': case 'recommendations':
            $t = trim((string)($tx[$s] ?? '')); if ($t === '') break;
            if ($y === 0 || $y > 800) $newPage();
            $h2($s === 'conclusions' ? 'Conclusiones' : 'Recomendaciones'); $para($t); break;
    }
    if ($y === 0) $newPage();
    $need(30);
    $pdf->para($M, max($y + 12, 790), $W - 2 * $M, 'Datos de Meta (API oficial)' . (!empty($an['last_sync']) ? ' actualizados el ' . date('d/m/Y H:i', strtotime($an['last_sync'])) : '') . '. Informe armado el ' . date('d/m/Y H:i', strtotime((string)($snap['built_at'] ?? 'now'))) . '.', 7.5, '#9ca3af');
    return $pdf->output($r['title']);
}

// ═══════════════════════════════════════════════════════════
// SEND BY EMAIL
// ═══════════════════════════════════════════════════════════
/** Email with attachments. In tests (G7_MAIL_FAKE) nothing goes out: it is recorded and handed to $GLOBALS['SS_MAIL_SINK'] if it exists. */
function ss_mail_send(string $to, string $subject, string $html, array $files = []): bool {
    require_once dirname(__DIR__) . '/mail_tpl_lib.php';
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    if (defined('G7_MAIL_FAKE')) { if (isset($GLOBALS['SS_MAIL_SINK']) && is_callable($GLOBALS['SS_MAIL_SINK'])) ($GLOBALS['SS_MAIL_SINK'])(compact('to', 'subject', 'html', 'files')); g7_mail_log_add($to, $subject, 'social.report', true); return true; }
    $fromName = g7_mail_layout()['from_name'] ?: (string)g7_site('name'); $from = 'no-reply@' . g7_site_mail_host();
    $b = 'ssb' . bin2hex(random_bytes(8));
    $hd = ['MIME-Version: 1.0', 'From: =?UTF-8?B?' . base64_encode($fromName) . "?= <$from>", 'X-Mailer: Toolz', "Content-Type: multipart/mixed; boundary=\"$b\""];
    $body = "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html)) . "\r\n";
    foreach ($files as [$name, $data, $type]) $body .= "--$b\r\nContent-Type: $type; name=\"$name\"\r\nContent-Disposition: attachment; filename=\"$name\"\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($data)) . "\r\n";
    $body .= "--$b--";
    $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $hd), '-f' . $from);
    g7_mail_log_add($to, $subject, 'social.report', (bool)$ok);
    return (bool)$ok;
}
function ss_emails(string $list): array {
    $out = [];
    foreach (preg_split('/[\s,;]+/', $list) as $e) { $e = trim($e); if ($e === '') continue; if (!filter_var($e, FILTER_VALIDATE_EMAIL)) ss_fail('Este mail no es válido: ' . mb_substr($e, 0, 80)); $out[strtolower($e)] = $e; }
    if (count($out) > 10) ss_fail('Hasta 10 destinatarios por envío.');
    return array_values($out);
}
/** Sends the report: email with the button to the online link (it is created if it did not exist) and the server PDF attached. */
function ss_rep_send(PDO $db, array $ctx, int $id, string $to, string $message = '', bool $system = false): int {
    $r = $system ? ss_rep_row_any($db, $id) : ss_rep_row($db, $ctx, $id);
    $emails = ss_emails($to);
    if (!$emails) ss_fail('Escribí al menos un mail.');
    if (!$system && !ss_rate_ok($db, 'repsend:' . $ctx['id'], 20, 3600)) ss_fail('Enviaste muchos informes seguidos: esperá un rato.', 429);
    if (!$r['token']) { $tok = bin2hex(random_bytes(20)); $db->prepare('UPDATE g7_ss_reports SET token = ? WHERE id = ?')->execute([$tok, $id]); $r['token'] = $tok; }
    require_once dirname(__DIR__) . '/mail_tpl_lib.php';
    $snap = json_decode((string)$r['snapshot'], true) ?: [];
    $periodo = date('d/m/Y', strtotime($r['date_from'])) . ' al ' . date('d/m/Y', strtotime($r['date_to']));
    [$subject, $html] = g7_mail_build('social.report', ['cliente' => (string)($snap['party']['name'] ?? ''), 'periodo' => $periodo, 'informe' => $r['title']],
        ['url' => g7_site_origin() . '/toolz/social_report.php?t=' . $r['token'], 'info_html' => trim($message) !== '' ? nl2br(htmlspecialchars(mb_substr($message, 0, 2000), ENT_QUOTES, 'UTF-8')) : '']);
    $pdf = ss_rep_pdf($r);
    $file = preg_replace('/[^A-Za-z0-9_\-]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $r['title']) ?: 'informe') . '.pdf';
    $n = 0;
    foreach ($emails as $e) if (ss_mail_send($e, $subject, $html, [[$file, $pdf, 'application/pdf']])) $n++;
    if (!$n) ss_fail('No se pudo enviar el mail.', 502);
    $db->prepare("UPDATE g7_ss_reports SET status = 'sent', sent_to = ?, sent_at = ?, updated_at = ? WHERE id = ?")->execute([mb_substr(implode(', ', $emails), 0, 1000), ss_now(), ss_now(), $id]);
    g7_audit('social.report_send', '#' . $id, ['to' => count($emails), 'auto' => $system]);
    return $n;
}

// ═══════════════════════════════════════════════════════════
// TEMPLATES AND AUTOMATIC MONTHLY REPORTS
// ═══════════════════════════════════════════════════════════
function ss_rep_templates(PDO $db, array $ctx): array {
    ss_need($ctx, 'reports'); ss_rep_seed($db);
    return array_map(fn($t) => ['id' => (int)$t['id'], 'name' => $t['name'], 'sections' => json_decode($t['sections'], true) ?: [], 'metric' => $t['metric'], 'intro' => (string)$t['intro'], 'is_default' => (bool)$t['is_default']],
        $db->query('SELECT * FROM g7_ss_report_tpl ORDER BY is_default DESC, name')->fetchAll(PDO::FETCH_ASSOC));
}
function ss_rep_tpl_save(PDO $db, array $ctx, array $in): void {
    ss_need($ctx, 'reports');
    $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 80); if ($name === '') ss_fail('Ponele un nombre a la plantilla.');
    $list = array_values(array_unique(array_intersect(array_map('strval', (array)($in['sections'] ?? [])), array_keys(SS_REP_SECTIONS)))); if (!$list) ss_fail('Elegí al menos una sección.');
    $metric = isset(SS_AN_METRICS[$in['metric'] ?? '']) ? $in['metric'] : 'interactions'; $intro = mb_substr(trim((string)($in['intro'] ?? '')), 0, 4000);
    $id = (int)($in['id'] ?? 0);
    if ($id) $db->prepare('UPDATE g7_ss_report_tpl SET name = ?, sections = ?, metric = ?, intro = ?, updated_at = ? WHERE id = ?')->execute([$name, json_encode($list), $metric, $intro, ss_now(), $id]);
    else $db->prepare('INSERT INTO g7_ss_report_tpl (name, sections, metric, intro, created_by, updated_at) VALUES (?, ?, ?, ?, ?, ?)')->execute([$name, json_encode($list), $metric, $intro, $ctx['id'], ss_now()]);
}
function ss_rep_tpl_delete(PDO $db, array $ctx, int $id): void {
    ss_need($ctx, 'reports');
    if ((int)$db->query('SELECT COUNT(*) FROM g7_ss_report_tpl')->fetchColumn() <= 1) ss_fail('Tiene que quedar al menos una plantilla.');
    $db->prepare('DELETE FROM g7_ss_report_tpl WHERE id = ?')->execute([$id]);
}
function ss_rep_scheds(PDO $db, array $ctx): array {
    ss_need($ctx, 'reports');
    return array_values(array_filter(array_map(fn($s) => ['id' => (int)$s['id'], 'scope' => $s['scope'], 'scope_id' => (int)$s['scope_id'], 'template_id' => (int)$s['template_id'], 'recipients' => $s['recipients'], 'day' => (int)$s['day'], 'active' => (bool)$s['active'], 'last_month' => $s['last_month']],
        $db->query('SELECT * FROM g7_ss_report_sched ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)), fn($s) => $s['scope'] !== 'brand' || !$s['scope_id'] || $ctx['brands'] === null || in_array($s['scope_id'], $ctx['brands'], true)));
}
function ss_rep_sched_save(PDO $db, array $ctx, array $in): void {
    ss_need($ctx, 'reports');
    if (!ss_plan_allows('social_reports_auto')) ss_plan_fail('los informes automáticos mensuales');
    $scope = in_array($in['scope'] ?? '', ['brand', 'client', 'all'], true) ? $in['scope'] : 'brand'; $sid = $scope === 'all' ? 0 : (int)($in['scope_id'] ?? 0);
    ss_rep_scope_check($db, $ctx, $scope, $sid);
    $to = $in['recipients'] ?? ''; $em = trim((string)$to) === '' ? [] : ss_emails((string)$to);
    $day = max(1, min(28, (int)($in['day'] ?? 2)));
    $id = (int)($in['id'] ?? 0);
    $vals = [$scope, $sid, (int)($in['template_id'] ?? 0) ?: null, implode(', ', $em), $day, !empty($in['active']) ? 1 : 0, ss_now()];
    if ($id) $db->prepare('UPDATE g7_ss_report_sched SET scope = ?, scope_id = ?, template_id = ?, recipients = ?, day = ?, active = ?, updated_at = ? WHERE id = ?')->execute([...$vals, $id]);
    else $db->prepare('INSERT INTO g7_ss_report_sched (scope, scope_id, template_id, recipients, day, active, updated_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([...$vals, $ctx['id']]);
    g7_audit('social.report_sched', "$scope:$sid", ['day' => $day, 'to' => count($em)]);
}
function ss_rep_sched_delete(PDO $db, array $ctx, int $id): void { ss_need($ctx, 'reports'); $db->prepare('DELETE FROM g7_ss_report_sched WHERE id = ?')->execute([$id]); }
/**
 * Automatic monthly reports (social/cron.php): from the chosen day, the report for the previous month is built with the data
 * that is already synced (the date of the data appears in the report) and, if it has recipients, it is sent. Once per month.
 */
function ss_rep_cron(PDO $db, ?int $now = null): int {
    $now = $now ?? time(); $month = date('Y-m', $now); $n = 0;
    if (!ss_plan_allows('social_reports') || !ss_plan_allows('social_reports_auto')) return 0;   // the plan does not include them
    $prevFrom = date('Y-m-01', strtotime(date('Y-m-01', $now) . ' -1 month')); $prevTo = date('Y-m-t', strtotime($prevFrom));
    foreach ($db->query('SELECT * FROM g7_ss_report_sched WHERE active = 1')->fetchAll(PDO::FETCH_ASSOC) as $s) {
        if ((int)date('j', $now) < (int)$s['day'] || $s['last_month'] === $month) continue;
        $db->prepare('UPDATE g7_ss_report_sched SET last_month = ? WHERE id = ?')->execute([$month, (int)$s['id']]);   // once, even if something fails afterwards
        $owner = (int)($s['created_by'] ?: 0);
        $ctx = ['id' => $owner, 'name' => 'Informe automático', 'admin' => true, 'can' => ['reports' => true, 'analytics' => true], 'brands' => null, 'scope' => null];
        try {
            $party = ss_rep_party($db, $s['scope'], (int)$s['scope_id']);
            $id = ss_rep_create($db, $ctx, ['scope' => $s['scope'], 'scope_id' => (int)$s['scope_id'], 'from' => $prevFrom, 'to' => $prevTo, 'compare' => 'prev', 'template_id' => (int)$s['template_id'],
                'title' => 'Informe mensual · ' . $party['name'] . ' · ' . ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'][(int)date('n', strtotime($prevFrom))] . ' ' . date('Y', strtotime($prevFrom))], true);
            $db->prepare("UPDATE g7_ss_reports SET status = 'final' WHERE id = ?")->execute([$id]);
            if (trim($s['recipients']) !== '') ss_rep_send($db, $ctx, $id, $s['recipients'], '', true);
            if ($owner) try { $db->prepare("INSERT INTO g7_notifications (user_id, from_id, type, target_id, profile_id, body) VALUES (?, NULL, 'social_report', ?, NULL, ?)")
                ->execute([$owner, $id, mb_substr('Se armó el informe mensual de ' . $party['name'] . (trim($s['recipients']) !== '' ? ' y se envió' : ''), 0, 280)]); } catch (Throwable $e) {}
            $n++;
        } catch (Throwable $e) { error_log('[social rep cron] ' . $e->getMessage()); }
    }
    return $n;
}
