<?php
/**
 * social/analytics_lib.php — Social Studio, Stage 4: Social Analytics.
 *  - Sync (ss_an_sync_account): daily account metrics, follower snapshot and posts with their metrics,
 *    ONLY the ones the official API gives for each network. They are stored with unified names (g7_ss_metrics: account, day, metric, value).
 *    If Meta does not give a metric for a network, it is not invented: the dashboard does not show it.
 *  - Report (ss_an_report): range of your choice compared with the previous period or the same one the year before, series per day,
 *    comparison between networks and accounts, top posts, engagement and goals per brand or client.
 * Permission: analytics (see); config (goals). Isolation by brand of the account. Doc: toolz/SOCIAL_STUDIO.md (Stage 4)
 */
require_once __DIR__ . '/inbox_lib.php';   // meta_lib + ss_meta_time

/**
 * Unified metrics: key => [label, how they add up (sum = add the days; last = value at the end of the period), format, help].
 * SS_AN_MAP says which official metric of each network feeds each key (see the comments: verified against Meta's doc).
 */
const SS_AN_METRICS = [
    'followers'     => ['Seguidores', 'last', 'n', 'Cantidad de seguidores al final del período.'],
    'new_followers' => ['Seguidores nuevos', 'sum', 'n', 'Personas que empezaron a seguir la cuenta (Instagram lo da solo con 100 seguidores o más).'],
    'reach'         => ['Alcance', 'sum', 'n', 'Cuentas que vieron el contenido, sumando cada día (la misma persona en dos días cuenta dos veces).'],
    'views'         => ['Visualizaciones', 'sum', 'n', 'Veces que se vio el contenido. Reemplaza a las "impresiones", que Meta retiró en 2025.'],
    'interactions'  => ['Interacciones', 'sum', 'n', 'Me gusta, comentarios, compartidos, guardados y respuestas.'],
    'engaged'       => ['Cuentas que interactuaron', 'sum', 'n', 'Solo Instagram.'],
    'likes'         => ['Me gusta', 'sum', 'n', 'Instagram, LinkedIn y YouTube por día (en Facebook, TikTok y X, los me gusta están en cada publicación).'],
    'comments'      => ['Comentarios', 'sum', 'n', 'Instagram, LinkedIn y YouTube por día.'],
    'shares'        => ['Compartidos', 'sum', 'n', 'Instagram, LinkedIn y YouTube por día.'],
    'saves'         => ['Guardados', 'sum', 'n', 'Solo Instagram.'],
    'profile_views' => ['Visitas al perfil', 'sum', 'n', 'Solo Facebook (Instagram dejó de darlas en 2025).'],
    'link_clicks'   => ['Clics en enlaces y botones', 'sum', 'n', 'Instagram: toques en los enlaces del perfil. Facebook: clics en el botón de acción y los datos de contacto. LinkedIn: clics en las publicaciones.'],
    'watch_minutes' => ['Minutos vistos', 'sum', 'n', 'Solo YouTube: minutos de reproducción estimados.'],
];
/** Official metric → unified key. Verified against Meta's docs on 2026-11-17 (Graph API v27.0). */
const SS_AN_IG_DAY = ['reach' => 'reach', 'views' => 'views', 'total_interactions' => 'interactions', 'accounts_engaged' => 'engaged', 'likes' => 'likes', 'comments' => 'comments',
                      'shares' => 'shares', 'saves' => 'saves', 'profile_links_taps' => 'link_clicks'];
const SS_AN_FB_DAY = ['page_follows' => 'followers', 'page_daily_follows_unique' => 'new_followers', 'page_media_view' => 'views', 'page_total_media_view_unique' => 'reach',
                      'page_post_engagements' => 'interactions', 'page_views_total' => 'profile_views', 'page_total_actions' => 'link_clicks'];
const SS_AN_IG_MEDIA = ['reach' => 'reach', 'views' => 'views', 'likes' => 'likes', 'comments' => 'comments', 'shares' => 'shares', 'saved' => 'saves', 'total_interactions' => 'interactions'];
const SS_AN_POST_METRICS = ['reach' => 'Alcance', 'views' => 'Visualizaciones', 'likes' => 'Me gusta', 'comments' => 'Comentarios', 'shares' => 'Compartidos', 'saves' => 'Guardados', 'link_clicks' => 'Clics', 'interactions' => 'Interacciones'];

// ═══════════════════════════════════════════════════════════
// REPORT
// ═══════════════════════════════════════════════════════════
/** Accounts that enter the report (the person's scope + filters). */
function ss_an_accounts(PDO $db, array $ctx, array $f): array {
    ss_need($ctx, 'analytics');
    $w = [ss_acc_scope_sql($ctx)]; $a = [];
    if (!empty($f['brand'])) { $w[] = 'a.brand_id = ?'; $a[] = (int)$f['brand']; }
    if (!empty($f['client'])) { $w[] = 'a.client_id = ?'; $a[] = (int)$f['client']; }
    if (in_array($f['network'] ?? '', ss_connectable_networks(), true)) $w[] = ss_network_kind_sql($f['network']);
    if (!empty($f['accounts'])) { $ids = array_values(array_filter(array_map('intval', (array)$f['accounts']))); if ($ids) $w[] = 'a.id IN (' . implode(',', $ids) . ')'; }
    $q = $db->prepare('SELECT a.* FROM g7_ss_accounts a WHERE ' . implode(' AND ', $w) . ' ORDER BY a.kind, a.name'); $q->execute($a);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}
/** Valid range: [from, to] (max. 2 years; to never after today). */
function ss_an_range(array $f): array {
    $d = fn($x) => is_string($x) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $x) ? $x : null;
    $to = $d($f['to'] ?? null) ?: date('Y-m-d'); if ($to > date('Y-m-d')) $to = date('Y-m-d');
    $from = $d($f['from'] ?? null) ?: date('Y-m-d', strtotime($to . ' -29 days'));
    if ($from > $to) [$from, $to] = [$to, $from];
    if ((strtotime($to) - strtotime($from)) / 86400 > 730) $from = date('Y-m-d', strtotime($to . ' -730 days'));
    return [$from, $to];
}
/** Totals per metric for some accounts in a range: [metric => value|null]. "last" metrics take the last day with data. */
function ss_an_totals(PDO $db, array $accIds, string $from, string $to): array {
    $out = [];
    if (!$accIds) return $out;
    $in = implode(',', array_map('intval', $accIds));
    $q = $db->prepare("SELECT metric, SUM(value) v, COUNT(*) n FROM g7_ss_metrics WHERE account_id IN ($in) AND day BETWEEN ? AND ? GROUP BY metric"); $q->execute([$from, $to]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) if (isset(SS_AN_METRICS[$r['metric']]) && SS_AN_METRICS[$r['metric']][1] === 'sum') $out[$r['metric']] = (int)$r['v'];
    // "last": per account, the value of the last day with data in the range, added across accounts
    foreach (SS_AN_METRICS as $k => $m) {
        if ($m[1] !== 'last') continue;
        $q = $db->prepare("SELECT SUM(x.value) FROM g7_ss_metrics x JOIN (SELECT account_id, MAX(day) d FROM g7_ss_metrics WHERE account_id IN ($in) AND metric = ? AND day BETWEEN ? AND ? GROUP BY account_id) l
                           ON l.account_id = x.account_id AND l.d = x.day WHERE x.metric = ?");
        $q->execute([$k, $from, $to, $k]);
        $v = $q->fetchColumn();
        if ($v !== null && $v !== false) $out[$k] = (int)$v;
    }
    return $out;
}
function ss_an_delta($cur, $prev): ?float {
    if ($cur === null || $prev === null || !$prev) return null;
    return round(($cur - $prev) / abs($prev) * 100, 1);
}
/**
 * Full report. $f: from, to, compare (prev|year|none), brand, client, network, accounts[].
 * Only the metrics that have data in the period (or the comparison) come out: nothing invented.
 */
function ss_an_report(PDO $db, array $ctx, array $f): array {
    [$from, $to] = ss_an_range($f);
    $accs = ss_an_accounts($db, $ctx, $f);
    $ids = array_map(fn($a) => (int)$a['id'], $accs);
    $days = (int)round((strtotime($to) - strtotime($from)) / 86400) + 1;
    $cmp = in_array($f['compare'] ?? 'prev', ['prev', 'year', 'none'], true) ? ($f['compare'] ?? 'prev') : 'prev';
    if ($cmp === 'year') { $pf = date('Y-m-d', strtotime($from . ' -1 year')); $pt = date('Y-m-d', strtotime($to . ' -1 year')); }
    else { $pt = date('Y-m-d', strtotime($from . ' -1 day')); $pf = date('Y-m-d', strtotime($pt . ' -' . ($days - 1) . ' days')); }
    $cur = ss_an_totals($db, $ids, $from, $to);
    $prev = $cmp === 'none' ? [] : ss_an_totals($db, $ids, $pf, $pt);
    // Growth of followers in the period (last - first) when there are snapshots
    if ($ids) {
        $in = implode(',', $ids);
        $first = $db->prepare("SELECT SUM(x.value) FROM g7_ss_metrics x JOIN (SELECT account_id, MIN(day) d FROM g7_ss_metrics WHERE account_id IN ($in) AND metric = 'followers' AND day BETWEEN ? AND ? GROUP BY account_id) l ON l.account_id = x.account_id AND l.d = x.day WHERE x.metric = 'followers'");
        $first->execute([$from, $to]); $fv = $first->fetchColumn();
        if ($fv !== null && $fv !== false && isset($cur['followers'])) $cur['growth'] = (int)$cur['followers'] - (int)$fv;
    }
    $kpis = [];
    foreach (SS_AN_METRICS as $k => $m) {
        if (!array_key_exists($k, $cur) && !array_key_exists($k, $prev)) continue;
        $kpis[] = ['key' => $k, 'label' => $m[0], 'help' => $m[3], 'value' => $cur[$k] ?? null, 'prev' => $prev[$k] ?? null, 'delta' => ss_an_delta($cur[$k] ?? null, $prev[$k] ?? null)];
    }
    // Engagement: interactions over reach (if there is reach) or over followers
    $base = $cur['reach'] ?? null; $baseP = $prev['reach'] ?? null; $baseLbl = 'el alcance';
    if (!$base && isset($cur['followers'])) { $base = $cur['followers']; $baseP = $prev['followers'] ?? null; $baseLbl = 'los seguidores'; }
    $eng = isset($cur['interactions']) && $base ? round($cur['interactions'] / $base * 100, 2) : null;
    $engP = isset($prev['interactions']) && $baseP ? round($prev['interactions'] / $baseP * 100, 2) : null;
    // Series per day (period and comparison aligned by day number)
    $series = [];
    if ($ids) {
        $in = implode(',', $ids);
        $q = $db->prepare("SELECT day, metric, SUM(value) v FROM g7_ss_metrics WHERE account_id IN ($in) AND day BETWEEN ? AND ? GROUP BY day, metric");
        foreach ([[$from, $to, 'cur'], [$pf, $pt, 'prev']] as [$a, $b, $tag]) {
            if ($tag === 'prev' && $cmp === 'none') continue;
            $q->execute([$a, $b]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $i = (int)round((strtotime($r['day']) - strtotime($a)) / 86400);
                $series[$r['metric']][$tag][$i] = (int)$r['v'];
            }
        }
    }
    $labels = []; for ($i = 0; $i < $days; $i++) $labels[] = date('Y-m-d', strtotime($from . " +$i days"));
    $ser = [];
    foreach ($series as $k => $s) if (isset(SS_AN_METRICS[$k])) $ser[$k] = ['cur' => array_map(fn($i) => $s['cur'][$i] ?? null, array_keys($labels)), 'prev' => isset($s['prev']) ? array_map(fn($i) => $s['prev'][$i] ?? null, array_keys($labels)) : null];
    // Per network and per account
    $byAcc = [];
    foreach ($accs as $a) {
        $t = ss_an_totals($db, [(int)$a['id']], $from, $to); $tp = $cmp === 'none' ? [] : ss_an_totals($db, [(int)$a['id']], $pf, $pt);
        $byAcc[] = ['id' => (int)$a['id'], 'name' => $a['username'] ? '@' . $a['username'] : $a['name'], 'network' => ss_kind_network($a['kind']), 'picture' => $a['picture'],
                    'brand_id' => $a['brand_id'] ? (int)$a['brand_id'] : null, 'status' => $a['status'], 'last_sync' => $a['last_sync_at'], 'totals' => $t, 'prev' => $tp,
                    'engagement' => isset($t['interactions']) && (($t['reach'] ?? 0) ?: ($t['followers'] ?? 0)) ? round($t['interactions'] / (($t['reach'] ?? 0) ?: $t['followers']) * 100, 2) : null];
    }
    $byNet = [];
    foreach (ss_connectable_networks() as $n) {
        $ia = array_map(fn($x) => $x['id'], array_filter($byAcc, fn($x) => $x['network'] === $n));
        if ($ia) $byNet[$n] = ss_an_totals($db, $ia, $from, $to);
    }
    // Top posts of the period
    $top = [];
    if ($ids) {
        $q = $db->prepare('SELECT m.*, a.kind, a.username, a.name AS acc_name FROM g7_ss_media m JOIN g7_ss_accounts a ON a.id = m.account_id WHERE m.account_id IN (' . implode(',', $ids) . ') AND m.published_at BETWEEN ? AND ?');
        $q->execute([$from . ' 00:00:00', $to . ' 23:59:59']);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $mt = json_decode((string)$m['metrics'], true) ?: [];
            $inter = $mt['interactions'] ?? (($mt['likes'] ?? 0) + ($mt['comments'] ?? 0) + ($mt['shares'] ?? 0) + ($mt['saves'] ?? 0));
            $top[] = ['id' => (int)$m['id'], 'account' => $m['username'] ? '@' . $m['username'] : $m['acc_name'], 'network' => ss_kind_network($m['kind']),
                      'type' => $m['media_type'], 'caption' => $m['caption'], 'permalink' => $m['permalink'], 'thumb' => $m['thumb'], 'published_at' => $m['published_at'], 'post_id' => $m['post_id'] ? (int)$m['post_id'] : null,
                      'metrics' => array_intersect_key($mt, SS_AN_POST_METRICS), 'interactions' => (int)$inter,
                      'engagement' => !empty($mt['reach']) ? round($inter / $mt['reach'] * 100, 2) : null];
        }
        usort($top, fn($a, $b) => [$b['interactions'], $b['published_at']] <=> [$a['interactions'], $a['published_at']]);
    }
    $lastSync = $accs ? max(array_map(fn($a) => (string)$a['last_sync_at'], $accs)) : '';
    return ['from' => $from, 'to' => $to, 'days' => $days, 'compare' => $cmp, 'prev_from' => $cmp === 'none' ? null : $pf, 'prev_to' => $cmp === 'none' ? null : $pt,
            'kpis' => $kpis, 'growth' => $cur['growth'] ?? null, 'engagement' => ['value' => $eng, 'prev' => $engP, 'delta' => ss_an_delta($eng, $engP), 'base' => $baseLbl],
            'labels' => $labels, 'series' => $ser, 'accounts' => $byAcc, 'networks' => $byNet, 'top' => array_slice($top, 0, 12), 'posts_count' => count($top),
            'goals' => ss_an_goals_progress($db, $ctx, $f), 'last_sync' => $lastSync ?: null, 'has_accounts' => (bool)$accs,
            'catalog' => array_map(fn($m) => ['label' => $m[0], 'agg' => $m[1], 'help' => $m[3]], SS_AN_METRICS)];
}

// ═══════════════════════════════════════════════════════════
// GOALS per brand or client (monthly)
// ═══════════════════════════════════════════════════════════
function ss_an_goals(PDO $db, array $ctx): array {
    ss_need($ctx, 'analytics');
    $rows = $db->query('SELECT * FROM g7_ss_goals ORDER BY scope, scope_id, metric')->fetchAll(PDO::FETCH_ASSOC);
    return array_values(array_filter(array_map(fn($g) => ['id' => (int)$g['id'], 'scope' => $g['scope'], 'scope_id' => (int)$g['scope_id'], 'metric' => $g['metric'], 'target' => (int)$g['target']], $rows),
        fn($g) => $g['scope'] !== 'brand' || !$g['scope_id'] || $ctx['brands'] === null || in_array($g['scope_id'], $ctx['brands'], true)));
}
function ss_an_goal_save(PDO $db, array $ctx, array $in): void {
    ss_need($ctx, 'config');
    $scope = ($in['scope'] ?? '') === 'client' ? 'client' : 'brand'; $sid = (int)($in['scope_id'] ?? 0);
    $metric = (string)($in['metric'] ?? ''); $target = (int)($in['target'] ?? 0);
    if (!isset(SS_AN_METRICS[$metric])) ss_fail('Elegí una métrica de la lista.');
    if ($target <= 0) ss_fail('El objetivo tiene que ser un número mayor que cero.');
    if ($scope === 'brand' && $sid && !isset(g7_brands(true)[$sid])) ss_fail('Esa marca no existe.');
    $db->prepare('INSERT INTO g7_ss_goals (scope, scope_id, metric, target, created_by, updated_at) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE target = VALUES(target), updated_at = VALUES(updated_at)')
       ->execute([$scope, $sid, $metric, $target, $ctx['id'], ss_now()]);
    g7_audit('social.goal', "$scope:$sid", ['metric' => $metric, 'target' => $target]);
}
function ss_an_goal_delete(PDO $db, array $ctx, int $id): void { ss_need($ctx, 'config'); $db->prepare('DELETE FROM g7_ss_goals WHERE id = ?')->execute([$id]); }
/** Progress of the goals of this month (for the brand/client filtered, or all the ones the person sees). */
function ss_an_goals_progress(PDO $db, array $ctx, array $f): array {
    $out = []; $m0 = date('Y-m-01'); $today = date('Y-m-d');
    $elapsed = (int)date('j') / (int)date('t');
    foreach (ss_an_goals($db, $ctx) as $g) {
        if (!empty($f['brand']) && !($g['scope'] === 'brand' && $g['scope_id'] === (int)$f['brand'])) continue;
        if (!empty($f['client']) && !($g['scope'] === 'client' && $g['scope_id'] === (int)$f['client'])) continue;
        $af = $g['scope_id'] ? [$g['scope'] => $g['scope_id']] : [];
        $ids = array_map(fn($a) => (int)$a['id'], ss_an_accounts($db, $ctx, $af));
        $v = ss_an_totals($db, $ids, $m0, $today)[$g['metric']] ?? null;
        $name = $g['scope_id'] ? ($g['scope'] === 'brand' ? (g7_brands(true)[$g['scope_id']]['name'] ?? '#' . $g['scope_id']) : (string)$db->query('SELECT name FROM g7_clients WHERE id = ' . $g['scope_id'])->fetchColumn()) : 'Todas las cuentas';
        $pct = $v === null ? null : (int)round($v / $g['target'] * 100);
        $out[] = $g + ['name' => $name, 'label' => SS_AN_METRICS[$g['metric']][0], 'value' => $v, 'pct' => $pct,
                       'on_track' => $pct === null ? null : (SS_AN_METRICS[$g['metric']][1] === 'last' ? $pct >= 100 : $pct >= (int)round($elapsed * 100) - 5)];
    }
    return $out;
}

// ═══════════════════════════════════════════════════════════
// SYNC (Graph API → g7_ss_metrics / g7_ss_media)
// ═══════════════════════════════════════════════════════════
function ss_an_put(PDO $db, int $acc, string $day, string $metric, $value): void {
    if ($value === null || !is_numeric($value)) return;
    $db->prepare('INSERT INTO g7_ss_metrics (account_id, day, metric, value) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)')->execute([$acc, $day, $metric, (int)$value]);
}
/**
 * Synchronizes an account: days (the first time, the last 30; afterwards the last 3, because Meta takes up to 48 h), today's followers
 * and recent posts with their metrics. Returns ['days' => n, 'media' => n, 'skipped' => [metrics that Meta did not give]].
 */
function ss_an_sync_account(PDO $db, array $a, ?int $days = null): array {
    $tok = ss_account_token($a);
    if ($tok === '') return ['days' => 0, 'media' => 0, 'skipped' => []];
    $id = (int)$a['id'];
    $has = (int)$db->query("SELECT COUNT(*) FROM g7_ss_metrics WHERE account_id = $id")->fetchColumn();
    $days = $days ?? ($has ? 3 : 30);
    if ($a['provider'] !== 'meta') {   // Stage 6: each network brings what its API gives (net_*.php)
        $r = ss_net_fn($a['provider'], 'an_sync', $db, $a, $days);
        $db->prepare('UPDATE g7_ss_accounts SET last_sync_at = ? WHERE id = ?')->execute([ss_now(), $id]);
        return $r;
    }
    $skipped = []; $n = 0;
    // Today's followers (snapshot: it is the only figure that does not depend on the 100-follower minimum)
    try {
        $r = ss_meta_call($db, 'GET', $a['ext_id'], ['fields' => 'followers_count', 'access_token' => $tok], $id, 'an_followers');
        if (isset($r['followers_count'])) ss_an_put($db, $id, date('Y-m-d'), 'followers', $r['followers_count']);
    } catch (SsError $e) { $skipped[] = 'followers'; }
    if ($a['kind'] === 'ig_business') {
        // Instagram: reach comes as a daily series; the rest only as a total of the range → one query per day
        for ($i = $days; $i >= 1; $i--) {
            $d = date('Y-m-d', strtotime("-$i days")); $since = strtotime($d . ' 00:00:00'); $until = $since + 86400;
            try {
                $r = ss_meta_call($db, 'GET', $a['ext_id'] . '/insights', ['metric' => implode(',', array_keys(SS_AN_IG_DAY)), 'period' => 'day', 'metric_type' => 'total_value',
                    'since' => $since, 'until' => $until, 'access_token' => $tok], $id, 'an_ig_day');
                foreach ((array)($r['data'] ?? []) as $m) if (isset(SS_AN_IG_DAY[$m['name'] ?? ''])) ss_an_put($db, $id, $d, SS_AN_IG_DAY[$m['name']], $m['total_value']['value'] ?? null);
                $n++;
            } catch (SsMetaError $e) { if ($e->metaCode === 190) throw $e; $skipped[] = 'ig_day:' . $d; }
            // New followers (Meta only gives it with 100 followers or more)
            try {
                $r = ss_meta_call($db, 'GET', $a['ext_id'] . '/insights', ['metric' => 'follows_and_unfollows', 'period' => 'day', 'metric_type' => 'total_value', 'breakdown' => 'follow_type',
                    'since' => $since, 'until' => $until, 'access_token' => $tok], $id, 'an_ig_follows');
                foreach ((array)($r['data'][0]['total_value']['breakdowns'][0]['results'] ?? []) as $x) if (in_array('FOLLOWER', (array)($x['dimension_values'] ?? []), true)) ss_an_put($db, $id, $d, 'new_followers', $x['value'] ?? null);
            } catch (SsMetaError $e) { if ($e->metaCode === 190) throw $e; }
        }
    } else {
        // Facebook: daily series of the range in a single query (up to 90 days)
        $since = strtotime(date('Y-m-d', strtotime("-$days days"))); $until = strtotime(date('Y-m-d'));
        foreach (array_chunk(array_keys(SS_AN_FB_DAY), 4) as $chunk) {   // few metrics per query: Meta cuts the slow ones
            try {
                $r = ss_meta_call($db, 'GET', $a['ext_id'] . '/insights', ['metric' => implode(',', $chunk), 'period' => 'day', 'since' => $since, 'until' => $until, 'access_token' => $tok], $id, 'an_fb_day');
                foreach ((array)($r['data'] ?? []) as $m) {
                    if (!isset(SS_AN_FB_DAY[$m['name'] ?? ''])) continue;
                    foreach ((array)($m['values'] ?? []) as $v) {
                        if (!isset($v['end_time'])) continue;
                        $day = date('Y-m-d', strtotime($v['end_time']) - 86400);   // end_time = end of the day being measured
                        ss_an_put($db, $id, $day, SS_AN_FB_DAY[$m['name']], is_array($v['value'] ?? null) ? array_sum($v['value']) : ($v['value'] ?? null));
                    }
                }
                $n = $days;
            } catch (SsMetaError $e) { if ($e->metaCode === 190) throw $e; $skipped = array_merge($skipped, $chunk); }
        }
    }
    $media = ss_an_sync_media($db, $a, $tok);
    $db->prepare('UPDATE g7_ss_accounts SET last_sync_at = ? WHERE id = ?')->execute([ss_now(), $id]);
    return ['days' => $n, 'media' => $media, 'skipped' => $skipped];
}
/** Recent posts (last 50) with their metrics. Those published from Social Studio are linked to their post. */
function ss_an_sync_media(PDO $db, array $a, string $tok): int {
    $id = (int)$a['id']; $n = 0;
    $up = $db->prepare('INSERT INTO g7_ss_media (account_id, ext_id, media_type, caption, permalink, thumb, published_at, post_id, metrics, synced_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE media_type = VALUES(media_type), caption = VALUES(caption), permalink = VALUES(permalink), thumb = VALUES(thumb), post_id = COALESCE(VALUES(post_id), post_id), metrics = VALUES(metrics), synced_at = VALUES(synced_at)');
    $link = $db->prepare('SELECT post_id FROM g7_ss_targets WHERE account_id = ? AND ext_id = ? LIMIT 1');
    try {
        if ($a['kind'] === 'ig_business') {
            $r = ss_meta_call($db, 'GET', $a['ext_id'] . '/media', ['fields' => 'id,caption,media_type,media_product_type,permalink,thumbnail_url,media_url,timestamp,like_count,comments_count', 'limit' => 50, 'access_token' => $tok], $id, 'an_ig_media');
            foreach ((array)($r['data'] ?? []) as $m) {
                $mt = ['likes' => (int)($m['like_count'] ?? 0), 'comments' => (int)($m['comments_count'] ?? 0)];
                if (strtotime((string)($m['timestamp'] ?? '')) > time() - 90 * 86400) {
                    try {
                        $ins = ss_meta_call($db, 'GET', $m['id'] . '/insights', ['metric' => implode(',', array_keys(SS_AN_IG_MEDIA)), 'access_token' => $tok], $id, 'an_ig_media_ins');
                        foreach ((array)($ins['data'] ?? []) as $x) if (isset(SS_AN_IG_MEDIA[$x['name'] ?? ''])) $mt[SS_AN_IG_MEDIA[$x['name']]] = (int)($x['values'][0]['value'] ?? $x['total_value']['value'] ?? 0);
                    } catch (SsMetaError $e) { if ($e->metaCode === 190) throw $e; }   // e.g. values under 5: we keep the basic counts
                }
                $link->execute([$id, (string)$m['id']]);
                $up->execute([$id, (string)$m['id'], (string)($m['media_product_type'] ?? $m['media_type'] ?? ''), mb_substr((string)($m['caption'] ?? ''), 0, 500), (string)($m['permalink'] ?? ''),
                    (string)($m['thumbnail_url'] ?? $m['media_url'] ?? ''), ss_meta_time($m['timestamp'] ?? null), ($p = $link->fetchColumn()) ? (int)$p : null, json_encode($mt), ss_now()]);
                $n++;
            }
        } else {
            $r = ss_meta_call($db, 'GET', $a['ext_id'] . '/posts', ['fields' => 'id,message,created_time,permalink_url,full_picture,shares,comments.summary(true).limit(0),reactions.summary(true).limit(0)', 'limit' => 50, 'access_token' => $tok], $id, 'an_fb_posts');
            foreach ((array)($r['data'] ?? []) as $m) {
                $mt = ['likes' => (int)($m['reactions']['summary']['total_count'] ?? 0), 'comments' => (int)($m['comments']['summary']['total_count'] ?? 0), 'shares' => (int)($m['shares']['count'] ?? 0)];
                if (strtotime((string)($m['created_time'] ?? '')) > time() - 90 * 86400) {
                    try {
                        $ins = ss_meta_call($db, 'GET', $m['id'] . '/insights', ['metric' => 'post_media_view,post_total_media_view_unique', 'access_token' => $tok], $id, 'an_fb_post_ins');
                        foreach ((array)($ins['data'] ?? []) as $x) {
                            if (($x['name'] ?? '') === 'post_media_view') $mt['views'] = (int)($x['values'][0]['value'] ?? 0);
                            if (($x['name'] ?? '') === 'post_total_media_view_unique') $mt['reach'] = (int)($x['values'][0]['value'] ?? 0);
                        }
                    } catch (SsMetaError $e) { if ($e->metaCode === 190) throw $e; }
                }
                $mt['interactions'] = $mt['likes'] + $mt['comments'] + $mt['shares'];
                $link->execute([$id, (string)$m['id']]);
                $up->execute([$id, (string)$m['id'], 'POST', mb_substr((string)($m['message'] ?? ''), 0, 500), (string)($m['permalink_url'] ?? ''), (string)($m['full_picture'] ?? ''),
                    ss_meta_time($m['created_time'] ?? null), ($p = $link->fetchColumn()) ? (int)$p : null, json_encode($mt), ss_now()]);
                $n++;
            }
        }
    } catch (SsMetaError $e) { if ($e->metaCode === 190) throw $e; }
    return $n;
}
/** Prepared upsert for g7_ss_media (shared by every network). */
function ss_an_media_upsert_stmt(PDO $db): PDOStatement {
    return $db->prepare('INSERT INTO g7_ss_media (account_id, ext_id, media_type, caption, permalink, thumb, published_at, post_id, metrics, synced_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE media_type = VALUES(media_type), caption = VALUES(caption), permalink = VALUES(permalink), thumb = VALUES(thumb), post_id = COALESCE(VALUES(post_id), post_id), metrics = VALUES(metrics), synced_at = VALUES(synced_at)');
}
/** Stores a post of a network with its metrics; if it was published from Social Studio it is linked to its post. */
function ss_an_media_put(PDO $db, PDOStatement $up, int $acc, string $ext, string $type, string $caption, string $link, string $thumb, string $at, array $mt): void {
    $q = $db->prepare('SELECT post_id FROM g7_ss_targets WHERE account_id = ? AND ext_id = ? LIMIT 1'); $q->execute([$acc, $ext]);
    $pid = $q->fetchColumn();
    $up->execute([$acc, mb_substr($ext, 0, 160), mb_substr($type, 0, 30), mb_substr($caption, 0, 500), mb_substr($link, 0, 1000), mb_substr($thumb, 0, 1000), $at, $pid ? (int)$pid : null, json_encode($mt), ss_now()]);
}
/** Synchronizes the accounts the person sees (button) or all of them (cron, once a day). */
function ss_an_sync_all(PDO $db, ?array $ctx = null): array {
    if (!ss_plan_allows('social_analytics')) return ['accounts' => 0, 'errors' => []];
    $w = "a.token_enc IS NOT NULL AND a.token_enc <> '' AND a.status = 'ok'" . ($ctx ? ' AND ' . ss_acc_scope_sql($ctx) : '');
    $out = ['accounts' => 0, 'errors' => []];
    foreach ($db->query("SELECT a.* FROM g7_ss_accounts a WHERE $w")->fetchAll(PDO::FETCH_ASSOC) as $a) {
        if (!ss_plan_net_ok(ss_kind_network($a['kind']))) continue;
        try { ss_an_sync_account($db, $a); $out['accounts']++; }
        catch (SsError $e) {
            $out['errors'][] = ($a['username'] ? '@' . $a['username'] : $a['name']) . ': ' . $e->getMessage();
            if ($e instanceof SsMetaError && $e->metaCode === 190) $db->prepare("UPDATE g7_ss_accounts SET status = 'expired', status_msg = ? WHERE id = ?")->execute([mb_substr($e->getMessage(), 0, 300), (int)$a['id']]);
        }
    }
    return $out;
}
