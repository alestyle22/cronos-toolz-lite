<?php
/**
 * integraciones/gcal.php — Google Calendar. La agencia carga su cliente OAuth (Google Cloud) en Admin › Integraciones;
 * cada persona conecta su cuenta desde Mi perfil (google_oauth.php). Un barrido copia sus g7_cal_events a su calendario
 * principal: crea, actualiza (si cambió) y borra. Es de una sola vía: Toolz → Google.
 * Tokens por persona en integrations.json (google_calendar.users[uid]); el mapeo de ids en la tabla g7_int_gcal_map.
 * Doc: toolz/INTEGRACIONES.md
 */
require_once __DIR__ . '/lib.php';

const GC_AUTH = 'https://accounts.google.com/o/oauth2/v2/auth';
const GC_TOKEN = 'https://oauth2.googleapis.com/token';
const GC_API = 'https://www.googleapis.com/calendar/v3';
const GC_SCOPE = 'openid email https://www.googleapis.com/auth/calendar.events';
const GC_MAX_OPS = 40;            // cambios por persona en cada barrido (lo que queda sigue en el próximo)
const GC_PAST_DAYS = 30;          // eventos que terminaron hace más de esto no se suben (salvo los que se repiten)

function gc_install(PDO $db): void {
    if (g7_cfg('gc_schema') === '1') return;
    $db->exec("CREATE TABLE IF NOT EXISTS g7_int_gcal_map (
        id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, event_id INT NOT NULL, google_id VARCHAR(255) NOT NULL,
        hash CHAR(40) NOT NULL DEFAULT '', synced_at DATETIME NOT NULL,
        UNIQUE KEY ue (user_id, event_id), KEY ug (user_id, google_id)
    ) DEFAULT CHARSET=utf8mb4");
    g7_cfg_set('gc_schema', '1');
}
function gc_redirect_uri(): string { return int_site_url() . '/toolz/integraciones/google_oauth.php'; }
function gc_user(int $uid): ?array { $u = int_cfg('google_calendar')['users'][(string)$uid] ?? null; return is_array($u) && !empty($u['refresh_token']) ? $u : null; }
function gc_user_set(int $uid, ?array $u): void {
    int_store_update(function ($d) use ($uid, $u) {
        if ($u === null) unset($d['google_calendar']['users'][(string)$uid]); else $d['google_calendar']['users'][(string)$uid] = $u;
        return $d;
    });
}
/** Lo que ve la persona en su perfil (sin tokens). */
function gc_user_public(int $uid): array {
    $u = gc_user($uid);
    return ['available' => int_ready('google_calendar'), 'connected' => (bool)$u, 'email' => $u['email'] ?? '', 'connected_at' => $u['connected_at'] ?? '',
        'last_sync' => $u['last_sync'] ?? '', 'last_error' => $u['last_error'] ?? '', 'synced' => (int)($u['synced'] ?? 0)];
}

// ── OAuth ───────────────────────────────────────────────────
/** Link para ir a Google. El state va firmado y lleva la persona y el vencimiento (la cookie de sesión es Strict y no vuelve en el regreso). */
function gc_auth_url(int $uid, ?string &$nonceOut = null): string {
    $c = int_cfg('google_calendar');
    $nonce = bin2hex(random_bytes(8)); $nonceOut = $nonce; $exp = time() + 900; $data = "$uid.$exp.$nonce";
    $state = $data . '.' . int_sign('gcal|' . $data);
    return GC_AUTH . '?' . http_build_query(['client_id' => $c['client_id'], 'redirect_uri' => gc_redirect_uri(), 'response_type' => 'code', 'scope' => GC_SCOPE,
        'access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true', 'state' => $state]);
}
/** Valida el state que vuelve de Google. Devuelve la persona o 0. */
function gc_state_uid(string $state): int {
    $p = explode('.', $state); if (count($p) !== 4) return 0;
    [$uid, $exp, $nonce, $sig] = $p;
    if (!ctype_digit($uid) || !ctype_digit($exp) || (int)$exp < time() || !hash_equals(int_sign("gcal|$uid.$exp.$nonce"), $sig)) return 0;
    $used = (array)(int_cfg('google_calendar')['used_states'] ?? []);
    if (isset($used[$nonce])) return 0;   // un link sirve una sola vez
    int_store_update(function ($d) use ($nonce) {
        $u = array_filter((array)($d['google_calendar']['used_states'] ?? []), fn($t) => $t > time() - 3600);
        $u[$nonce] = time(); $d['google_calendar']['used_states'] = $u; return $d;
    });
    return (int)$uid;
}
/** Cambia el código por los tokens y guarda la conexión. */
function gc_exchange(int $uid, string $code): array {
    $c = int_cfg('google_calendar');
    $r = int_http('POST', GC_TOKEN, ['Content-Type: application/x-www-form-urlencoded'], http_build_query(['code' => $code, 'client_id' => $c['client_id'], 'client_secret' => $c['client_secret'],
        'redirect_uri' => gc_redirect_uri(), 'grant_type' => 'authorization_code']));
    $j = $r['json'] ?? [];
    if ($r['status'] !== 200 || empty($j['access_token'])) { $m = gc_err($r); int_log('google_calendar', 'connect', false, "Persona $uid: $m"); throw new RuntimeException('Google no aceptó la conexión: ' . $m); }
    if (empty($j['refresh_token'])) { int_log('google_calendar', 'connect', false, "Persona $uid: Google no mandó el refresh token"); throw new RuntimeException('Google no dio permiso permanente. Quita el acceso de Toolz en tu cuenta de Google y vuelve a conectar.'); }
    $email = '';
    if (!empty($j['id_token']) && count($parts = explode('.', $j['id_token'])) === 3) { $pl = json_decode((string)base64_decode(strtr($parts[1], '-_', '+/')), true); $email = (string)($pl['email'] ?? ''); }
    $u = ['refresh_token' => $j['refresh_token'], 'access_token' => $j['access_token'], 'expires_at' => time() + (int)($j['expires_in'] ?? 3600) - 60,
        'email' => $email, 'calendar_id' => 'primary', 'connected_at' => date('Y-m-d H:i:s'), 'last_sync' => '', 'last_error' => '', 'synced' => 0];
    gc_user_set($uid, $u);
    int_log('google_calendar', 'connect', true, "Persona $uid conectó " . ($email ?: 'su cuenta'));
    return $u;
}
/** Un access token vigente (lo renueva con el refresh token si venció). */
function gc_token(int $uid): string {
    $u = gc_user($uid); if (!$u) throw new RuntimeException('No conectaste tu Google Calendar.');
    if (!empty($u['access_token']) && (int)($u['expires_at'] ?? 0) > time()) return $u['access_token'];
    $c = int_cfg('google_calendar');
    $r = int_http('POST', GC_TOKEN, ['Content-Type: application/x-www-form-urlencoded'], http_build_query(['client_id' => $c['client_id'], 'client_secret' => $c['client_secret'],
        'refresh_token' => $u['refresh_token'], 'grant_type' => 'refresh_token']));
    $j = $r['json'] ?? [];
    if ($r['status'] !== 200 || empty($j['access_token'])) {
        $m = gc_err($r);
        if (($j['error'] ?? '') === 'invalid_grant') {   // la persona quitó el acceso o el token caducó: hay que volver a conectar
            $u['refresh_token'] = ''; gc_user_set($uid, null);
            int_log('google_calendar', 'token', false, "Persona $uid: Google revocó el acceso; tiene que volver a conectar");
            throw new RuntimeException('Google quitó el acceso. Vuelve a conectar tu Google Calendar desde tu perfil.');
        }
        throw new RuntimeException('No se pudo renovar el acceso a Google: ' . $m);
    }
    $u['access_token'] = $j['access_token']; $u['expires_at'] = time() + (int)($j['expires_in'] ?? 3600) - 60;
    if (!empty($j['refresh_token'])) $u['refresh_token'] = $j['refresh_token'];
    gc_user_set($uid, $u);
    return $u['access_token'];
}
function gc_disconnect(PDO $db, int $uid): void {
    $u = gc_user($uid);
    if ($u) int_http('POST', 'https://oauth2.googleapis.com/revoke', ['Content-Type: application/x-www-form-urlencoded'], http_build_query(['token' => $u['refresh_token']]));
    gc_user_set($uid, null);
    try { gc_install($db); $db->prepare('DELETE FROM g7_int_gcal_map WHERE user_id = ?')->execute([$uid]); } catch (Throwable $e) {}
    int_log('google_calendar', 'disconnect', true, "Persona $uid desconectó su Google Calendar");
}
function gc_err(array $r): string {
    if ($r['error']) return $r['error'];
    $j = $r['json'] ?? [];
    $m = is_array($j['error'] ?? null) ? (string)($j['error']['message'] ?? '') : trim(($j['error'] ?? '') . ' ' . ($j['error_description'] ?? ''));
    return $m !== '' ? $m : 'respuesta ' . $r['status'];
}

/** Probar la conexión desde el Admin: el cliente OAuth es válido si Google responde invalid_grant (y no invalid_client) a un código falso. */
function gc_test(?int $uid = null): array {
    $c = int_cfg('google_calendar');
    $r = int_http('POST', GC_TOKEN, ['Content-Type: application/x-www-form-urlencoded'], http_build_query(['code' => 'prueba-de-conexion', 'client_id' => $c['client_id'],
        'client_secret' => $c['client_secret'], 'redirect_uri' => gc_redirect_uri(), 'grant_type' => 'authorization_code']));
    $e = (string)($r['json']['error'] ?? '');
    if ($r['error']) return [false, $r['error']];
    if ($e === 'invalid_client' || $e === 'unauthorized_client') return [false, 'Google no reconoce el ID o el secreto del cliente. Revísalos en Google Cloud › Credenciales.'];
    if ($e === 'redirect_uri_mismatch') return [false, 'Falta registrar la URI de redireccionamiento: ' . gc_redirect_uri()];
    if ($e !== 'invalid_grant') return [false, 'Respuesta inesperada de Google: ' . gc_err($r)];
    $msg = 'El cliente OAuth es válido.';
    if ($uid && gc_user($uid)) {
        try { $t = gc_token($uid); $x = int_http('GET', GC_API . '/calendars/primary', ['Authorization: Bearer ' . $t]);
            $msg .= $x['status'] === 200 ? ' Tu calendario: ' . ($x['json']['summary'] ?? 'principal') . '.' : ' Tu conexión falló: ' . gc_err($x); }
        catch (RuntimeException $e) { $msg .= ' ' . $e->getMessage(); }
    } else $msg .= ' Ahora cada persona lo conecta desde Mi perfil.';
    return [true, $msg];
}

// ── Sincronización ─────────────────────────────────────────
/** El evento de Google para un evento de Toolz. */
function gc_body(array $e, string $tz): array {
    $b = ['summary' => (string)$e['title'], 'description' => trim((string)$e['notes']), 'location' => (string)$e['location'],
        'extendedProperties' => ['private' => ['toolzId' => (string)$e['id']]]];
    if ((int)$e['all_day']) {
        $b['start'] = ['date' => substr($e['start_at'], 0, 10)];
        $b['end'] = ['date' => date('Y-m-d', strtotime(substr($e['end_at'], 0, 10) . ' +1 day'))];   // en Google el fin de un día completo es exclusivo
    } else {
        $etz = function_exists('g7_tz_valid') && g7_tz_valid($e['tz'] ?? '') ? $e['tz'] : $tz;   // zona del evento (ZONAS_HORARIAS.md)
        $b['start'] = ['dateTime' => str_replace(' ', 'T', $e['start_at']), 'timeZone' => $etz];
        $b['end'] = ['dateTime' => str_replace(' ', 'T', max($e['end_at'], $e['start_at'])), 'timeZone' => $etz];
    }
    $freq = ['daily' => 'DAILY', 'weekly' => 'WEEKLY', 'monthly' => 'MONTHLY', 'yearly' => 'YEARLY'][$e['repeat_rule']] ?? '';
    if ($freq) $b['recurrence'] = ['RRULE:FREQ=' . $freq . ($e['repeat_until'] ? ';UNTIL=' . str_replace('-', '', $e['repeat_until']) . 'T235959Z' : '')];
    $b['reminders'] = $e['remind_min'] !== null ? ['useDefault' => false, 'overrides' => [['method' => 'popup', 'minutes' => max(0, min(40320, (int)$e['remind_min']))]]] : ['useDefault' => true];
    return $b;
}
/** Sincroniza los eventos de una persona. Devuelve ['created', 'updated', 'deleted', 'errors']. */
function gc_sync_user(PDO $db, int $uid): array {
    gc_install($db); require_once dirname(__DIR__) . '/calendario/lib.php'; cal_install($db);
    $n = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'errors' => 0];
    $u = gc_user($uid); if (!$u) return $n;
    $cal = rawurlencode($u['calendar_id'] ?: 'primary'); $tz = date_default_timezone_get();
    try { $tok = gc_token($uid); } catch (RuntimeException $e) { gc_user_note($uid, $e->getMessage()); $n['errors']++; return $n; }
    $H = ['Authorization: Bearer ' . $tok, 'Content-Type: application/json'];
    $since = date('Y-m-d H:i:s', strtotime('-' . GC_PAST_DAYS . ' days'));
    $st = $db->prepare("SELECT * FROM g7_cal_events WHERE user_id = ? AND (end_at >= ? OR repeat_rule <> '') ORDER BY start_at"); $st->execute([$uid, $since]);
    $events = []; foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) $events[(int)$e['id']] = $e;
    $map = []; $m = $db->prepare('SELECT * FROM g7_int_gcal_map WHERE user_id = ?'); $m->execute([$uid]); foreach ($m->fetchAll(PDO::FETCH_ASSOC) as $r) $map[(int)$r['event_id']] = $r;
    $ops = 0; $lastErr = '';
    $ins = $db->prepare('INSERT INTO g7_int_gcal_map (user_id, event_id, google_id, hash, synced_at) VALUES (?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE google_id = VALUES(google_id), hash = VALUES(hash), synced_at = NOW()');
    // Borrados: el evento ya no existe en Toolz (los que solo quedaron viejos siguen en Google)
    $gone = []; if ($map) { $ids = implode(',', array_map('intval', array_keys($map))); $gone = array_diff(array_keys($map), array_map('intval', $db->query("SELECT id FROM g7_cal_events WHERE id IN ($ids)")->fetchAll(PDO::FETCH_COLUMN))); }
    foreach ($gone as $eid) {
        if ($ops++ >= GC_MAX_OPS) break;
        $r = int_http('DELETE', GC_API . "/calendars/$cal/events/" . rawurlencode($map[$eid]['google_id']), $H);
        if (in_array($r['status'], [200, 204, 404, 410], true)) { $db->prepare('DELETE FROM g7_int_gcal_map WHERE id = ?')->execute([(int)$map[$eid]['id']]); $n['deleted']++; }
        else { $n['errors']++; $lastErr = gc_err($r); }
    }
    foreach ($events as $eid => $e) {
        if ($ops >= GC_MAX_OPS) break;
        $body = gc_body($e, $tz); $hash = sha1(json_encode($body, JSON_UNESCAPED_UNICODE));
        $mp = $map[$eid] ?? null;
        if ($mp && $mp['hash'] === $hash) continue;
        $ops++;
        if ($mp) {
            $r = int_http('PUT', GC_API . "/calendars/$cal/events/" . rawurlencode($mp['google_id']), $H, json_encode($body, JSON_UNESCAPED_UNICODE));
            if (in_array($r['status'], [404, 410], true)) $mp = null;   // lo borraron en Google: se vuelve a crear
            elseif ($r['status'] === 200) { $ins->execute([$uid, $eid, $mp['google_id'], $hash]); $n['updated']++; continue; }
            else { $n['errors']++; $lastErr = gc_err($r); continue; }
        }
        $r = int_http('POST', GC_API . "/calendars/$cal/events", $H, json_encode($body, JSON_UNESCAPED_UNICODE));
        if (in_array($r['status'], [200, 201], true) && !empty($r['json']['id'])) { $ins->execute([$uid, $eid, $r['json']['id'], $hash]); $n['created']++; }
        else { $n['errors']++; $lastErr = gc_err($r); }
    }
    $u = gc_user($uid);
    if ($u) { $u['last_sync'] = date('Y-m-d H:i:s'); $u['last_error'] = $lastErr; $u['synced'] = (int)$db->query('SELECT COUNT(*) FROM g7_int_gcal_map WHERE user_id = ' . $uid)->fetchColumn(); gc_user_set($uid, $u); }
    if ($n['created'] + $n['updated'] + $n['deleted'] + $n['errors'] > 0)
        int_log('google_calendar', 'sync', $n['errors'] === 0, "Persona $uid: {$n['created']} creados, {$n['updated']} actualizados, {$n['deleted']} borrados" . ($n['errors'] ? ", {$n['errors']} con error ($lastErr)" : ''));
    return $n;
}
function gc_user_note(int $uid, string $err): void { $u = gc_user($uid); if ($u) { $u['last_error'] = $err; $u['last_sync'] = date('Y-m-d H:i:s'); gc_user_set($uid, $u); } }
/** Barrido de todas las personas conectadas. */
function gc_sync_all(PDO $db): array {
    $tot = ['users' => 0, 'created' => 0, 'updated' => 0, 'deleted' => 0, 'errors' => 0];
    if (!int_ready('google_calendar')) return $tot;
    foreach (array_keys((array)(int_cfg('google_calendar')['users'] ?? [])) as $uid) {
        if (!gc_user((int)$uid)) continue;
        $r = gc_sync_user($db, (int)$uid); $tot['users']++;
        foreach ($r as $k => $v) $tot[$k] += $v;
    }
    return $tot;
}
function gc_maybe_sync(PDO $db): void { if (int_ready('google_calendar') && int_every('gc_sync_at', 600)) gc_sync_all($db); }
