<?php
/**
 * jira_use_lib.php — Jira dentro de Cronos (2026-11-11). Se activa en Admin › Jira API › "Usar Jira en Cronos".
 *   - promos:  vincular contenidos de Promos con issues de Jira (el vínculo se guarda en Cronos: a Jira no se le escribe nada).
 *   - trabajo: sección "Jira" en Gestión de trabajo con los tickets de los proyectos elegidos.
 *   - chat:    vincular tickets de Jira en los mensajes ([[jira:DIS-12]]), con el selector y con @ (chat/refs_lib.php).
 * Todo es de solo lectura sobre Jira (jira_lib.php solo hace GET). Las consultas siempre van acotadas a los proyectos
 * elegidos (Jira rechaza el JQL sin límite). Config en g7_config 'jira_features'. Doc: toolz/JIRA.md
 */
require_once __DIR__ . '/jira_lib.php';

/** {promos: bool, trabajo: bool, projects: [claves]} */
function g7_jira_features(): array {
    $f = json_decode((string)g7_cfg('jira_features', '{}'), true);
    $f = is_array($f) ? $f : [];
    return ['promos' => !empty($f['promos']), 'trabajo' => !empty($f['trabajo']), 'chat' => !empty($f['chat']), 'stats' => !empty($f['stats']),
        'projects' => array_values(array_filter(array_map('strval', (array)($f['projects'] ?? [])), fn($k) => preg_match('/^[A-Z][A-Z0-9_]{0,19}$/', $k)))];
}
function g7_jira_features_save(array $in): array {
    $keys = array_values(array_unique(array_filter(array_map(fn($k) => strtoupper(trim((string)$k)), (array)($in['projects'] ?? [])), fn($k) => preg_match('/^[A-Z][A-Z0-9_]{0,19}$/', $k))));
    $f = ['promos' => !empty($in['promos']), 'trabajo' => !empty($in['trabajo']), 'chat' => !empty($in['chat']), 'stats' => !empty($in['stats']), 'projects' => array_slice($keys, 0, 30)];
    g7_cfg_set('jira_features', json_encode($f));
    return $f;
}
/** ¿Jira está listo y activado para esta parte (promos | trabajo)? Hace falta al menos un proyecto elegido. */
function g7_jira_on(string $what): bool {
    if (!g7_jira_ready()) return false;
    $f = g7_jira_features();
    return !empty($f[$what]) && $f['projects'];
}
/** Lo que se pide a Jira de cada issue. */
const G7_JIRA_FIELDS = ['summary', 'status', 'assignee', 'priority', 'issuetype', 'updated', 'duedate', 'project'];

/** JQL con los proyectos elegidos (o los pedidos, si son de esa lista). */
function g7_jira_jql_in(array $keys): string {
    return 'project in (' . implode(', ', array_map(fn($k) => '"' . addcslashes($k, '"\\') . '"', $keys)) . ')';
}
/** Issue de Jira en la forma que usa Cronos. */
function g7_jira_issue_out(array $r): array {
    $f = $r['fields'] ?? [];
    $cat = (string)($f['status']['statusCategory']['key'] ?? 'new');   // new | indeterminate | done
    return ['key' => (string)($r['key'] ?? ''), 'summary' => (string)($f['summary'] ?? ''), 'status' => (string)($f['status']['name'] ?? ''),
        'cat' => in_array($cat, ['new', 'indeterminate', 'done'], true) ? $cat : 'new', 'type' => (string)($f['issuetype']['name'] ?? ''),
        'priority' => (string)($f['priority']['name'] ?? ''), 'assignee' => (string)($f['assignee']['displayName'] ?? ''), 'assignee_id' => (string)($f['assignee']['accountId'] ?? ''),
        'updated' => substr((string)($f['updated'] ?? ''), 0, 19), 'due' => (string)($f['duedate'] ?? ''), 'project' => (string)($f['project']['key'] ?? ''),
        'project_name' => (string)($f['project']['name'] ?? ''), 'url' => g7_jira_cfg()['site'] . '/browse/' . rawurlencode((string)($r['key'] ?? ''))];
}
/** Clave de issue válida (ABC-123). */
function g7_jira_key_ok(string $k): bool { return (bool)preg_match('/^[A-Z][A-Z0-9_]{0,19}-\d{1,9}$/', $k); }

/**
 * Busca issues en los proyectos elegidos. $f: q (texto o clave), project, cat (new|indeterminate|done), assignee (accountId), max.
 * Una clave exacta (DIS-7) busca ese issue; el texto busca en resumen y descripción.
 */
function g7_jira_find(array $f = []): array {
    $all = g7_jira_features()['projects']; if (!$all) return [];
    $keys = !empty($f['project']) && in_array($f['project'], $all, true) ? [$f['project']] : $all;
    $w = [g7_jira_jql_in($keys)];
    $q = trim((string)($f['q'] ?? ''));
    if ($q !== '') {
        if (g7_jira_key_ok(strtoupper($q))) $w[] = 'key = "' . strtoupper($q) . '"';
        else $w[] = 'text ~ "' . addcslashes(mb_substr(preg_replace('/[\x00-\x1f"\\\\]+/u', ' ', $q), 0, 80), '"') . '"';
    }
    if (in_array($f['cat'] ?? '', ['new', 'indeterminate', 'done'], true)) $w[] = 'statusCategory = "' . ['new' => 'To Do', 'indeterminate' => 'In Progress', 'done' => 'Done'][$f['cat']] . '"';
    if (!empty($f['assignee']) && preg_match('/^[\w:-]{1,128}$/', $f['assignee'])) $w[] = 'assignee = "' . $f['assignee'] . '"';
    $r = g7_jira_search(implode(' AND ', $w) . ' ORDER BY updated DESC', G7_JIRA_FIELDS, max(1, min(100, (int)($f['max'] ?? 50))));
    return array_map('g7_jira_issue_out', (array)($r['issues'] ?? []));
}
/** Varios issues por clave (para refrescar el estado de los vinculados). Solo de los proyectos elegidos. */
function g7_jira_by_keys(array $keys): array {
    $all = g7_jira_features()['projects'];
    $keys = array_values(array_filter(array_unique($keys), fn($k) => g7_jira_key_ok($k) && in_array(explode('-', $k)[0], $all, true)));
    if (!$keys) return [];
    $r = g7_jira_search(g7_jira_jql_in(array_values(array_unique(array_map(fn($k) => explode('-', $k)[0], $keys)))) . ' AND key in (' . implode(', ', $keys) . ')', G7_JIRA_FIELDS, min(100, count($keys)));
    $out = []; foreach ((array)($r['issues'] ?? []) as $i) { $x = g7_jira_issue_out($i); $out[$x['key']] = $x; }
    return $out;
}
/** La persona de Jira de un usuario de Cronos (por su email), guardada un día para no preguntar en cada pedido. */
function g7_jira_account_of(PDO $db, int $uid): ?string {
    $k = 'jira_acc_' . $uid; $c = json_decode((string)g7_cfg($k, ''), true);
    // Encontrada: se recuerda un día; no encontrada, una hora (por si corrige su email en Jira)
    if (is_array($c) && ($c['t'] ?? 0) > time() - ($c['id'] ? 86400 : 3600)) return $c['id'] ?: null;
    $q = $db->prepare('SELECT email FROM g7_users WHERE id = ?'); $q->execute([$uid]); $email = (string)$q->fetchColumn();
    $id = '';
    if ($email !== '') { try { $id = (string)(g7_jira_user_by_email($email)['accountId'] ?? ''); } catch (G7JiraError $e) { return null; } }
    g7_cfg_set($k, json_encode(['id' => $id, 't' => time()]));
    return $id ?: null;
}

// ── Vínculos (en Cronos) ─────────────────────────────────────
function g7_jira_links_install(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS g7_jira_links (
        id INT AUTO_INCREMENT PRIMARY KEY, target VARCHAR(40) NOT NULL, issue_key VARCHAR(32) NOT NULL,
        summary VARCHAR(255) NOT NULL DEFAULT '', status VARCHAR(80) NOT NULL DEFAULT '', cat VARCHAR(16) NOT NULL DEFAULT 'new',
        linked_by INT NULL, linked_at DATETIME NOT NULL, checked_at DATETIME NULL,
        UNIQUE KEY tk (target, issue_key), KEY issue (issue_key)
    ) DEFAULT CHARSET=utf8mb4");
}
/** Vínculos de un elemento (content:ID…). */
function g7_jira_links_for(PDO $db, string $target): array {
    g7_jira_links_install($db);
    $q = $db->prepare('SELECT issue_key AS `key`, summary, status, cat, linked_at FROM g7_jira_links WHERE target = ? ORDER BY id'); $q->execute([$target]);
    $site = g7_jira_cfg()['site'];
    return array_map(fn($r) => $r + ['url' => $site . '/browse/' . rawurlencode($r['key'])], $q->fetchAll(PDO::FETCH_ASSOC));
}
/** Vincula: el issue tiene que existir y ser de los proyectos elegidos (se consulta a Jira). */
function g7_jira_link(PDO $db, int $uid, string $target, string $key): array {
    if (!preg_match('/^content:\d+$/', $target)) throw new RuntimeException('No se puede vincular eso.');
    $key = strtoupper(trim($key)); if (!g7_jira_key_ok($key)) throw new RuntimeException('Esa no es una clave de Jira (ej.: DIS-12).');
    $i = g7_jira_by_keys([$key])[$key] ?? null;
    if (!$i) throw new RuntimeException("$key no existe o no es de los proyectos de Jira elegidos para Cronos.");
    g7_jira_links_install($db);
    $now = date('Y-m-d H:i:s');
    $db->prepare('INSERT INTO g7_jira_links (target, issue_key, summary, status, cat, linked_by, linked_at, checked_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE summary = VALUES(summary), status = VALUES(status), cat = VALUES(cat), checked_at = VALUES(checked_at)')
       ->execute([$target, $key, mb_substr($i['summary'], 0, 255), mb_substr($i['status'], 0, 80), $i['cat'], $uid, $now, $now]);
    return g7_jira_links_for($db, $target);
}
function g7_jira_unlink(PDO $db, string $target, string $key): array {
    g7_jira_links_install($db);
    $db->prepare('DELETE FROM g7_jira_links WHERE target = ? AND issue_key = ?')->execute([$target, strtoupper($key)]);
    return g7_jira_links_for($db, $target);
}
/** Actualiza resumen y estado de los vinculados de un elemento (si se pudo hablar con Jira). */
function g7_jira_links_refresh(PDO $db, string $target): array {
    $L = g7_jira_links_for($db, $target); if (!$L) return [];
    try { $now = g7_jira_by_keys(array_column($L, 'key')); } catch (G7JiraError $e) { return $L; }
    $up = $db->prepare('UPDATE g7_jira_links SET summary = ?, status = ?, cat = ?, checked_at = ? WHERE target = ? AND issue_key = ?');
    foreach ($now as $k => $i) $up->execute([mb_substr($i['summary'], 0, 255), mb_substr($i['status'], 0, 80), $i['cat'], date('Y-m-d H:i:s'), $target, $k]);
    return g7_jira_links_for($db, $target);
}
/** Contenidos de Promos vinculados a cada issue: [clave => [[id, name]]] (para mostrarlos en Trabajo). */
function g7_jira_contents_of(PDO $db, int $uid, array $keys): array {
    $keys = array_values(array_filter($keys, 'g7_jira_key_ok')); if (!$keys) return [];
    // Solo quien ve Promos, y solo contenidos de sus marcas (como en Promos)
    $u = $db->prepare('SELECT role FROM g7_users WHERE id = ?'); $u->execute([$uid]); $role = (string)$u->fetchColumn();
    if ($role !== 'admin' && !(function_exists('hasPermission') && hasPermission($uid, 'promos', 'view', $role))) return [];
    $brand = function_exists('g7_brand_sql') ? g7_brand_sql("CONCAT_WS(' ', c.project, c.projects)", g7_brand_scope($uid)) : '1';
    g7_jira_links_install($db);
    $q = $db->query("SELECT l.issue_key, c.id, c.name FROM g7_jira_links l JOIN g7_content c ON c.id = CAST(SUBSTRING(l.target, 9) AS UNSIGNED)
        WHERE l.target LIKE 'content:%' AND ($brand) AND l.issue_key IN (" . implode(',', array_map([$db, 'quote'], $keys)) . ')');
    $out = []; foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['issue_key']][] = ['id' => (int)$r['id'], 'name' => $r['name']];
    return $out;
}

// ── Caché corta de issues (el chat redibuja los mensajes seguido: no preguntarle a Jira cada vez) ──
function g7_jira_cache_install(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS g7_jira_cache (issue_key VARCHAR(32) PRIMARY KEY, data TEXT NOT NULL, fetched_at INT NOT NULL) DEFAULT CHARSET=utf8mb4");
}
/**
 * Issues por clave, desde la caché si tienen menos de $ttl segundos; los que faltan se piden a Jira de una vez.
 * Una clave que Jira no devuelve (no existe o no es de los proyectos elegidos) se recuerda como "no existe" el mismo rato.
 * Si Jira no responde, se usa lo que haya en la caché aunque sea viejo. Devuelve [clave => issue | null].
 */
function g7_jira_cached(PDO $db, array $keys, int $ttl = 120): array {
    $keys = array_values(array_unique(array_filter(array_map('strtoupper', $keys), 'g7_jira_key_ok'))); if (!$keys) return [];
    g7_jira_cache_install($db);
    $in = implode(',', array_map([$db, 'quote'], $keys)); $out = []; $stale = [];
    foreach ($db->query("SELECT issue_key, data, fetched_at FROM g7_jira_cache WHERE issue_key IN ($in)")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $d = json_decode($r['data'], true);
        if ((int)$r['fetched_at'] > time() - $ttl) $out[$r['issue_key']] = $d ?: null; else $stale[$r['issue_key']] = $d ?: null;
    }
    $need = array_values(array_diff($keys, array_keys($out)));
    if ($need) {
        try { $got = g7_jira_by_keys($need); }
        catch (G7JiraError $e) { foreach ($need as $k) $out[$k] = $stale[$k] ?? null; return $out; }
        $up = $db->prepare('REPLACE INTO g7_jira_cache (issue_key, data, fetched_at) VALUES (?, ?, ?)');
        foreach ($need as $k) { $out[$k] = $got[$k] ?? null; $up->execute([$k, json_encode($out[$k], JSON_UNESCAPED_UNICODE), time()]); }
    }
    return $out;
}
