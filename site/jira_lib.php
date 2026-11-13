<?php
// ══════════════════════════════════════════════════════════════════
//  Jira Cloud en Toolz: cliente de SOLO LECTURA de la API REST.
//  El sitio, el email y el token se cargan en Admin › Jira API y viven en _private/jira.php
//  (carpeta bloqueada para la web). El token nunca se manda al navegador.
//  Solo hay pedidos GET: para escribir en Jira habría que decidirlo y agregarlo a propósito.
//  Doc: toolz/JIRA.md
// ══════════════════════════════════════════════════════════════════

const G7_JIRA_CFG = __DIR__ . '/_private/jira.php';
const G7_JIRA_SITE_DEFAULT = 'https://grupo-latino.atlassian.net';

/** Error de Jira con el texto listo para mostrar. $status = código HTTP (0 = no hubo respuesta). */
class G7JiraError extends RuntimeException {
    public int $status;
    public function __construct(string $msg, int $status = 0) { parent::__construct($msg); $this->status = $status; }
}

function g7_jira_cfg(): array {
    $c = is_file(G7_JIRA_CFG) ? (include G7_JIRA_CFG) : [];
    return array_merge(['site' => G7_JIRA_SITE_DEFAULT, 'email' => '', 'token' => ''], is_array($c) ? $c : []);
}
function g7_jira_save(array $cfg): bool {
    $php = "<?php\n// Conexión con Jira. La escribe Admin › Jira API; la lee jira_lib.php. No subir a ningún lado.\nreturn " . var_export($cfg, true) . ";\n";
    $ok = @file_put_contents(G7_JIRA_CFG, $php, LOCK_EX) !== false;
    if ($ok) { @chmod(G7_JIRA_CFG, 0600); if (function_exists('opcache_invalidate')) @opcache_invalidate(G7_JIRA_CFG, true); }
    return $ok;
}
/**
 * Dirección del sitio, o '' si no es un sitio de Jira Cloud. Solo *.atlassian.net: el token no puede salir hacia otro lado.
 * Acepta lo que se suele pegar: "grupo-latino", el dominio solo o la dirección de cualquier página del sitio.
 */
function g7_jira_site_clean(string $s): string {
    $s = strtolower(trim($s));
    if ($s !== '' && !str_contains($s, '.') && !str_contains($s, '/')) $s .= '.atlassian.net';
    $host = parse_url(str_contains($s, '://') ? $s : "https://$s", PHP_URL_HOST);
    return is_string($host) && preg_match('/^[a-z0-9][a-z0-9-]*\.atlassian\.net$/', $host) ? "https://$host" : '';
}
/** Por qué Jira no se puede usar (o '' si está listo). */
function g7_jira_problem(): string {
    $c = g7_jira_cfg();
    if ($c['email'] === '' || $c['token'] === '') return 'Jira no está configurado: el superadmin tiene que cargar el email y el token en Admin › Jira API.';
    if (!function_exists('curl_init')) return 'Falta la extensión cURL de PHP en el servidor.';
    return '';
}
function g7_jira_ready(): bool { return g7_jira_problem() === ''; }

/** Dirección base de la API. Solo en local (config.local.php) se puede apuntar a dev/mock_jira.php, nunca a otro servidor. */
function g7_jira_base(): string {
    $t = (string)getenv('G7_JIRA_TEST_BASE');
    if (preg_match('#^http://(127\.0\.0\.1|localhost):\d+$#', $t)) return $t;
    return g7_jira_cfg()['site'];
}

/** El mensaje que trae Jira en una respuesta de error (tiene varios formatos según el servicio), o ''. */
function g7_jira_api_msg(string $raw): string {
    $d = json_decode($raw, true);
    if (!is_array($d)) return '';
    $parts = array_merge(array_values((array)($d['errorMessages'] ?? [])), array_values((array)($d['errors'] ?? [])));
    foreach (['detail', 'message'] as $k) if (is_string($d[$k] ?? null)) $parts[] = $d[$k];
    $parts = array_filter($parts, 'is_string');
    return mb_substr(implode(' ', $parts), 0, 300);
}
/** Errores de la API en castellano, para mostrarle a quien usa la herramienta. */
function g7_jira_error(int $code, string $raw, array $headers): G7JiraError {
    $api = g7_jira_api_msg($raw);
    $tail = $api !== '' ? " Jira dice: $api" : '';
    if ($code === 401) return new G7JiraError('Jira rechazó el email o el token. Revisa que el email sea el de la cuenta dueña del token y que el token no haya vencido.', 401);
    if ($code === 403 && !empty($headers['x-seraph-loginreason']) && stripos($headers['x-seraph-loginreason'], 'OK') === false)
        return new G7JiraError('Jira bloqueó la cuenta por intentos fallidos. Hay que entrar una vez a Jira desde el navegador con esa cuenta y volver a probar.', 403);
    if ($code === 403) return new G7JiraError('La cuenta no tiene permiso para esto en Jira.' . $tail, 403);
    if ($code === 404) return new G7JiraError('Jira no encontró esto, o la cuenta no tiene permiso para verlo.' . $tail, 404);
    if ($code === 429) {
        $wait = (int)($headers['retry-after'] ?? 0);
        return new G7JiraError('Jira recibió demasiados pedidos seguidos. ' . ($wait > 0 ? "Prueba de nuevo en $wait segundos." : 'Prueba de nuevo en un rato.'), 429);
    }
    if ($code >= 500) return new G7JiraError("Jira está con problemas (código $code). Prueba de nuevo en un rato.", $code);
    return new G7JiraError("Jira respondió con un error (código $code)." . $tail, $code);
}
/** GET a la API de Jira; devuelve el JSON ya decodificado o lanza G7JiraError. $path: /rest/... */
function g7_jira_get(string $path, array $query = []): array {
    if ($p = g7_jira_problem()) throw new G7JiraError($p);
    if (!str_starts_with($path, '/rest/') || str_contains($path, '..')) throw new G7JiraError('Ruta de Jira no válida.');
    $c = g7_jira_cfg();
    $url = g7_jira_base() . $path . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
    for ($try = 0; ; $try++) {
        $headers = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Authorization: Basic ' . base64_encode($c['email'] . ':' . $c['token'])],
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_FOLLOWLOCATION => false,   // una redirección no se sigue: se llevaría el token a otra dirección
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
                $kv = explode(':', $line, 2);
                if (count($kv) === 2) $headers[strtolower(trim($kv[0]))] = trim($kv[1]);
                return strlen($line);
            },
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false) {
            if ($errno === CURLE_OPERATION_TIMEDOUT) throw new G7JiraError('Jira tardó demasiado en responder. Prueba de nuevo en un rato.');
            if ($errno === CURLE_SSL_CACERT || $errno === CURLE_SSL_CONNECT_ERROR) throw new G7JiraError('No se pudo verificar la conexión segura con Jira (error de certificado en este servidor).');
            throw new G7JiraError('No se pudo conectar con Jira. Revisa la dirección del sitio.');
        }
        // Un solo reintento, y solo si Jira pide esperar poco
        $wait = (int)($headers['retry-after'] ?? 0);
        if (($code === 429 || $code === 503) && $try === 0 && $wait >= 1 && $wait <= 5) { sleep($wait); continue; }
        break;
    }
    if ($code < 200 || $code >= 300) throw g7_jira_error($code, (string)$raw, $headers);
    if ($code === 204 || $raw === '') return [];
    $data = json_decode((string)$raw, true);
    // Una página de login con código 200 también cae acá
    if (!is_array($data)) throw new G7JiraError('Jira respondió algo inesperado (no es JSON). Revisa la dirección del sitio.', $code);
    return $data;
}

// ── Lecturas ─────────────────────────────────────────────────────
/** La cuenta dueña del token. Es la prueba real de las credenciales: otros servicios responden 200 vacío a un anónimo. */
function g7_jira_myself(): array { return g7_jira_get('/rest/api/3/myself'); }
/** Una página de proyectos: ['total', 'isLast', 'values' => [['key', 'name', ...]]]. */
function g7_jira_projects(int $max = 50, int $start = 0): array {
    return g7_jira_get('/rest/api/3/project/search', ['maxResults' => $max, 'startAt' => $start, 'orderBy' => 'name']);
}
/** Una página de tableros (Jira Software): ['total', 'isLast', 'values' => [['id', 'name', 'type', ...]]]. */
function g7_jira_boards(int $max = 50, int $start = 0, string $projectKey = ''): array {
    return g7_jira_get('/rest/agile/1.0/board', ['maxResults' => $max, 'startAt' => $start] + ($projectKey !== '' ? ['projectKeyOrId' => $projectKey] : []));
}
/**
 * Una página de incidencias. El JQL tiene que estar acotado (por proyecto o fecha): Jira rechaza las consultas sin límite.
 * Para seguir: pasar el nextPageToken de la respuesta hasta que venga isLast.
 */
function g7_jira_search(string $jql, array $fields = ['summary', 'status', 'assignee', 'updated'], int $max = 50, string $next = '', string $expand = ''): array {
    return g7_jira_get('/rest/api/3/search/jql', ['jql' => $jql, 'fields' => implode(',', $fields), 'maxResults' => $max] + ($next !== '' ? ['nextPageToken' => $next] : []) + ($expand !== '' ? ['expand' => $expand] : []));   // expand=changelog: historial (Estadísticas › Jira)
}
/** JQL de un proyecto, con la clave escapada. */
function g7_jira_jql_project(string $key): string {
    return 'project = "' . addcslashes($key, '"\\') . '" ORDER BY updated DESC';
}
/** Una página de usuarios. Necesita el permiso global "Browse users and groups". Jira puede ocultar el email según la privacidad de cada persona. */
function g7_jira_users(int $max = 50, int $start = 0): array {
    return g7_jira_get('/rest/api/3/users/search', ['maxResults' => $max, 'startAt' => $start]);
}
/** La persona de Jira con ese email (['accountId', 'displayName', ...]) o null. Con el email oculto, solo se acepta si hay un único resultado. */
function g7_jira_user_by_email(string $email): ?array {
    $people = array_values(array_filter(g7_jira_get('/rest/api/3/user/search', ['query' => $email, 'maxResults' => 10]), fn($u) => ($u['accountType'] ?? '') === 'atlassian'));
    foreach ($people as $u) if (strcasecmp((string)($u['emailAddress'] ?? ''), $email) === 0) return $u;
    return count($people) === 1 && empty($people[0]['emailAddress']) ? $people[0] : null;
}

// ── Prueba de conexión (Admin › Jira API) ────────────────────────────
/** Recorre todo lo que la integración va a necesitar. Devuelve ['ok' => bool, 'steps' => [['name', 'state' => ok|warn|fail, 'msg']]]. */
function g7_jira_test(): array {
    $steps = [];
    $add = function (string $name, string $state, string $msg) use (&$steps) { $steps[] = compact('name', 'state', 'msg'); };
    if ($p = g7_jira_problem()) { $add('Configuración', 'fail', $p); return ['ok' => false, 'steps' => $steps]; }
    // Lo que no es imprescindible (tableros, usuarios) avisa, pero no da la conexión por caída
    $try = function (string $name, string $failState, callable $fn) use ($add) {
        try { [$state, $msg] = $fn(); $add($name, $state, $msg); return true; }
        catch (G7JiraError $e) { $add($name, in_array($e->status, [403, 404], true) ? $failState : 'fail', $e->getMessage()); return false; }
    };
    $me = [];
    $ok = $try('Sitio', 'fail', function () {
        $i = g7_jira_get('/rest/api/3/serverInfo');
        if (($i['deploymentType'] ?? '') !== 'Cloud') return ['warn', 'Responde, pero no figura como Jira Cloud: ' . ($i['deploymentType'] ?? 'sin datos') . '.'];
        return ['ok', 'Jira Cloud en ' . ($i['baseUrl'] ?? g7_jira_base()) . '.'];
    }) && $try('Cuenta', 'fail', function () use (&$me) {
        $me = g7_jira_myself();
        if (empty($me['accountId'])) throw new G7JiraError('Jira no identificó la cuenta: revisa el email y el token.', 401);
        return ['ok', 'Conectado como ' . ($me['displayName'] ?? $me['accountId']) . (empty($me['active']) ? ' (la cuenta figura inactiva).' : '.')];
    });
    if (!$ok) return ['ok' => false, 'steps' => $steps];   // sin credenciales válidas, lo demás respondería vacío y confundiría

    $firstKey = '';
    $try('Proyectos', 'warn', function () use (&$firstKey) {
        $r = g7_jira_projects(5);
        if (!$r['values']) return ['warn', 'La cuenta no ve ningún proyecto: hay que darle permiso en Jira.'];
        $firstKey = (string)$r['values'][0]['key'];
        return ['ok', ($r['total'] ?? count($r['values'])) . ' a la vista: ' . implode(', ', array_map(fn($p) => $p['name'] . ' (' . $p['key'] . ')', $r['values'])) . '.'];
    });
    if ($firstKey !== '') $try('Incidencias', 'warn', function () use ($firstKey) {
        $r = g7_jira_search(g7_jira_jql_project($firstKey), ['summary', 'updated'], 1);
        if (empty($r['issues'])) return ['ok', "Consulta correcta; el proyecto $firstKey no tiene incidencias."];
        return ['ok', 'Última actualizada en ' . $firstKey . ': ' . $r['issues'][0]['key'] . ' · ' . ($r['issues'][0]['fields']['summary'] ?? '') . '.'];
    });
    $try('Tableros', 'warn', function () {
        $r = g7_jira_boards(5);
        if (!$r['values']) return ['warn', 'La cuenta no ve ningún tablero.'];
        return ['ok', ($r['total'] ?? count($r['values'])) . ' a la vista: ' . implode(', ', array_column($r['values'], 'name')) . '.'];
    });
    $try('Usuarios', 'warn', function () use ($me) {
        $c = count(g7_jira_users(1));
        $u = g7_jira_user_by_email(g7_jira_cfg()['email']);
        if (!$c) return ['warn', 'Jira no devolvió ninguna persona: la cuenta necesita el permiso global "Browse users and groups".'];
        if (!$u || ($u['accountId'] ?? '') !== ($me['accountId'] ?? '')) return ['warn', 'Se pueden listar personas, pero buscar por email no encontró a esta misma cuenta: para asociar usuarios puede hacer falta otro criterio.'];
        return ['ok', 'Se pueden listar personas y buscar por email (probado con esta cuenta).'];
    });
    return ['ok' => !in_array('fail', array_column($steps, 'state'), true), 'steps' => $steps];
}
