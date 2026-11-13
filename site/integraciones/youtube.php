<?php
/**
 * integraciones/youtube.php — Métricas de Talentos con la YouTube Data API v3 (videos.list, part=statistics) y una API key.
 * Los entregables con link de YouTube reciben vistas, me gusta y comentarios en g7_tal_deliverables.metrics (se suman
 * a lo que ya estaba cargado a mano: solo se pisan esas tres). Instagram y TikTok quedan pendientes (revisión de la app).
 * Doc: toolz/INTEGRACIONES.md
 */
require_once __DIR__ . '/lib.php';

const YT_API = 'https://www.googleapis.com/youtube/v3/videos';

/** El id del video (11 caracteres) de un link de YouTube, o ''. */
function yt_video_id(string $url): string {
    $url = trim($url); if ($url === '') return '';
    if (preg_match('/^[\w-]{11}$/', $url)) return $url;
    $p = parse_url(preg_match('#^https?://#i', $url) ? $url : 'https://' . $url); $host = strtolower(preg_replace('/^(www\.|m\.|music\.)/', '', (string)($p['host'] ?? '')));
    if ($host === 'youtu.be') $id = explode('/', trim((string)($p['path'] ?? ''), '/'))[0] ?? '';
    elseif ($host === 'youtube.com' || $host === 'youtube-nocookie.com') {
        parse_str((string)($p['query'] ?? ''), $q); $id = (string)($q['v'] ?? '');
        if ($id === '' && preg_match('#^/(shorts|live|embed|v)/([\w-]{11})#', (string)($p['path'] ?? ''), $m)) $id = $m[2];
    } else return '';
    return preg_match('/^[\w-]{11}$/', $id) ? $id : '';
}
/** Estadísticas de hasta 50 videos: [id => ['views', 'likes', 'comments']]. */
function yt_stats(array $ids): array {
    $ids = array_values(array_unique(array_filter($ids))); if (!$ids) return [];
    $out = [];
    foreach (array_chunk($ids, 50) as $chunk) {
        $r = int_http('GET', YT_API . '?' . http_build_query(['part' => 'statistics', 'id' => implode(',', $chunk), 'key' => int_cfg('youtube')['api_key']]));
        if ($r['status'] !== 200) throw new RuntimeException('YouTube: ' . yt_err($r));
        foreach ((array)($r['json']['items'] ?? []) as $it) {
            $s = (array)($it['statistics'] ?? []); $m = [];
            if (isset($s['viewCount'])) $m['views'] = (int)$s['viewCount'];
            if (isset($s['likeCount'])) $m['likes'] = (int)$s['likeCount'];       // falta si el canal oculta los me gusta
            if (isset($s['commentCount'])) $m['comments'] = (int)$s['commentCount']; // falta si los comentarios están cerrados
            $out[(string)$it['id']] = $m;
        }
    }
    return $out;
}
function yt_err(array $r): string {
    if ($r['error']) return $r['error'];
    $e = $r['json']['error'] ?? null; $reason = (string)($e['errors'][0]['reason'] ?? '');
    $hint = ['keyInvalid' => 'la clave no es válida', 'quotaExceeded' => 'se terminó la cuota del día', 'accessNotConfigured' => 'falta habilitar YouTube Data API v3 en el proyecto de Google Cloud',
        'ipRefererBlocked' => 'la clave tiene restricciones que no dejan pasar al servidor', 'forbidden' => 'la clave no tiene permiso'][$reason] ?? '';
    return trim((is_array($e) ? (string)($e['message'] ?? 'error') : 'respuesta ' . $r['status']) . ($hint ? " · $hint" : ''));
}
/** Actualiza las métricas de los entregables con link de YouTube. $ids limita a esos entregables. Devuelve cuántos cambió. */
function yt_refresh(PDO $db, array $ids = []): array {
    if (!int_ready('youtube')) throw new RuntimeException('YouTube no está configurado. Cárgalo en Admin › Integraciones.');
    $w = "(url LIKE '%youtu%')"; $a = [];
    if ($ids) { $ids = array_map('intval', $ids); $w .= ' AND id IN (' . implode(',', $ids) . ')'; }
    else $w .= " AND status <> 'cancelado'";
    $rows = $db->query("SELECT id, url, metrics FROM g7_tal_deliverables WHERE $w ORDER BY id DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
    $vid = []; foreach ($rows as $r) if (($v = yt_video_id((string)$r['url'])) !== '') $vid[(int)$r['id']] = $v;
    if (!$vid) return ['updated' => 0, 'missing' => 0, 'videos' => 0];
    try { $stats = yt_stats(array_values($vid)); }
    catch (RuntimeException $e) { int_log('youtube', 'metrics', false, $e->getMessage()); throw $e; }
    $up = $db->prepare('UPDATE g7_tal_deliverables SET metrics = ?, updated_at = ? WHERE id = ?'); $n = 0; $miss = 0;
    foreach ($rows as $r) {
        $id = (int)$r['id']; if (!isset($vid[$id])) continue;
        $s = $stats[$vid[$id]] ?? null; if ($s === null) { $miss++; continue; }   // video privado o borrado
        $m = json_decode((string)$r['metrics'], true) ?: [];
        $new = array_merge($m, $s); if ($new == $m) continue;
        $up->execute([json_encode($new), date('Y-m-d H:i:s'), $id]); $n++;
    }
    int_log('youtube', 'metrics', true, count($vid) . " videos consultados, $n entregables actualizados" . ($miss ? ", $miss sin datos (privados o borrados)" : ''));
    return ['updated' => $n, 'missing' => $miss, 'videos' => count($vid)];
}
function yt_test(): array {
    $r = int_http('GET', YT_API . '?' . http_build_query(['part' => 'id', 'id' => 'jNQXAC9IVRw', 'key' => int_cfg('youtube')['api_key']]));
    return $r['status'] === 200 ? [true, 'La clave funciona. Los entregables con link de YouTube ya pueden traer sus métricas.'] : [false, yt_err($r)];
}
/** Una vez por día, si la agencia lo dejó automático. */
function yt_maybe_refresh(PDO $db): void {
    if (!int_ready('youtube') || empty(int_cfg('youtube')['auto']) || !int_every('yt_refresh_at', 86400)) return;
    try { yt_refresh($db); } catch (Throwable $e) {}
}
