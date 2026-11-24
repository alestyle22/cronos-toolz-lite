<?php
/**
 * teams_lib.php — Subequipos y subdepartamentos (1.0.84).
 * Un equipo (g7_teams, que en Trabajo es un departamento) puede estar dentro de otro: g7_teams.parent_id arma el árbol.
 * Un subequipo es un equipo completo: sus miembros, su jefe (admin del equipo), sus permisos y, en el portal, su configuración
 * y sus formularios. El jefe de un equipo también administra todo lo que está debajo del suyo.
 */

const G7_TEAM_MAX_DEPTH = 4;   // niveles: departamento › sub › sub › sub

/** Columna parent_id (una sola vez por sitio). */
function g7_team_tree_install(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try { $v = $db->query("SELECT cfg_value FROM g7_config WHERE cfg_key = 'teams_schema'")->fetchColumn(); if ((int)$v >= 1) return; } catch (Throwable $e) {}
    try {
        $db->exec('ALTER TABLE g7_teams ADD COLUMN IF NOT EXISTS parent_id INT NULL');
        $db->exec('ALTER TABLE g7_teams ADD INDEX IF NOT EXISTS parent_id (parent_id)');
        $db->exec("INSERT INTO g7_config (cfg_key, cfg_value) VALUES ('teams_schema', '1') ON DUPLICATE KEY UPDATE cfg_value = '1'");
    } catch (Throwable $e) {}
}

/** [id => padre|null] de todos los equipos. $fresh vuelve a leer (después de crear o mover). */
function g7_team_parents(PDO $db, bool $fresh = false): array {
    static $map = null;
    if ($map !== null && !$fresh) return $map;
    g7_team_tree_install($db);
    $map = [];
    try { foreach ($db->query('SELECT id, parent_id FROM g7_teams')->fetchAll(PDO::FETCH_ASSOC) as $r) $map[(int)$r['id']] = $r['parent_id'] ? (int)$r['parent_id'] : null; } catch (Throwable $e) {}
    return $map;
}

/** Padre, abuelo… hasta la raíz (sin el propio). */
function g7_team_ancestors(PDO $db, int $id): array {
    $p = g7_team_parents($db); $out = []; $cur = $p[$id] ?? null;
    while ($cur && !in_array($cur, $out, true) && $cur !== $id && count($out) < 20) { $out[] = $cur; $cur = $p[$cur] ?? null; }
    return $out;
}

/** Los equipos pedidos más todos los que tienen debajo. */
function g7_team_descendants(PDO $db, array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];
    $kids = [];
    foreach (g7_team_parents($db) as $c => $par) if ($par) $kids[$par][] = $c;
    $out = $ids; $seen = array_flip($ids);
    for ($i = 0; $i < count($out); $i++) foreach ($kids[$out[$i]] ?? [] as $c) if (!isset($seen[$c])) { $seen[$c] = 1; $out[] = $c; }
    return $out;
}

/** Hijos directos. */
function g7_team_children(PDO $db, int $id): array {
    $o = []; foreach (g7_team_parents($db) as $c => $par) if ($par === $id) $o[] = $c;
    return $o;
}

/** Nivel del equipo: 1 = de primer nivel. */
function g7_team_depth(PDO $db, int $id): int { return 1 + count(g7_team_ancestors($db, $id)); }

/** Cuántos niveles tiene la rama que empieza en este equipo (1 = sin subequipos). */
function g7_team_height(PDO $db, int $id): int {
    $h = 1; foreach (g7_team_children($db, $id) as $c) $h = max($h, 1 + g7_team_height($db, $c));
    return $h;
}

/** ¿Se puede poner $id dentro de $parent? Devuelve el motivo si no (null = se puede). $id 0 = equipo nuevo. */
function g7_team_parent_problem(PDO $db, int $id, ?int $parent): ?string {
    if (!$parent) return null;
    $p = g7_team_parents($db);
    if (!array_key_exists($parent, $p)) return 'Ese equipo padre no existe';
    if ($id && ($parent === $id || in_array($id, g7_team_ancestors($db, $parent), true))) return 'Un equipo no puede quedar dentro de sí mismo ni de uno de sus subequipos';
    if (g7_team_depth($db, $parent) + ($id ? g7_team_height($db, $id) : 1) > G7_TEAM_MAX_DEPTH) return 'Llegaste al máximo de ' . G7_TEAM_MAX_DEPTH . ' niveles de subequipos';
    return null;
}

/** Mueve un equipo dentro de otro (o a primer nivel con null). Lanza RuntimeException con el motivo si no se puede. */
function g7_team_set_parent(PDO $db, int $id, ?int $parent): void {
    $parent = $parent ?: null;
    if ($why = g7_team_parent_problem($db, $id, $parent)) throw new RuntimeException($why);
    g7_team_tree_install($db);
    $db->prepare('UPDATE g7_teams SET parent_id = ? WHERE id = ?')->execute([$parent, $id]);
    g7_team_parents($db, true);
}

/** Antes de borrar un equipo: sus subequipos suben un nivel (quedan en el padre del que se borra). */
function g7_team_before_delete(PDO $db, int $id): void {
    g7_team_tree_install($db);
    $par = g7_team_parents($db)[$id] ?? null;
    $db->prepare('UPDATE g7_teams SET parent_id = ? WHERE parent_id = ?')->execute([$par, $id]);
    g7_team_parents($db, true);
}

/** "Marketing › Diseño" con los nombres de [id => nombre]. */
function g7_team_path(PDO $db, int $id, array $names, string $sep = ' › '): string {
    $parts = [];
    foreach (array_reverse(g7_team_ancestors($db, $id)) as $a) if (isset($names[$a])) $parts[] = $names[$a];
    $parts[] = $names[$id] ?? '';
    return implode($sep, $parts);
}

/**
 * Ordena filas de equipos (con 'id' y 'name') como árbol: cada padre seguido de sus subequipos.
 * Suma 'parent_id', 'depth' (0 = primer nivel entre las filas dadas) y 'path'. Un equipo cuyo padre no está entre las filas queda arriba.
 * $key ordena a los hermanos (por defecto el nombre).
 */
function g7_team_tree_sort(PDO $db, array $rows, ?callable $key = null): array {
    $p = g7_team_parents($db);
    $byId = []; foreach ($rows as $r) $byId[(int)$r['id']] = $r;
    $names = array_map(fn($r) => (string)$r['name'], $byId);
    $kids = []; $roots = [];
    foreach ($byId as $id => $r) {
        $par = $p[$id] ?? null;
        // el padre visible más cercano (si el directo no está en la lista, sube)
        while ($par && !isset($byId[$par])) $par = $p[$par] ?? null;
        if ($par && $par !== $id) $kids[$par][] = $id; else $roots[] = $id;
    }
    $key = $key ?: fn($r) => mb_strtolower((string)$r['name']);
    $cmp = fn($a, $b) => $key($byId[$a]) <=> $key($byId[$b]);
    $out = [];
    $walk = function (array $ids, int $depth) use (&$walk, &$out, $byId, $kids, $cmp, $p, $db, $names) {
        usort($ids, $cmp);
        foreach ($ids as $id) {
            if (isset($out[$id])) continue;
            $out[$id] = $byId[$id] + ['parent_id' => $p[$id] ?? null, 'depth' => $depth, 'path' => g7_team_path($db, $id, $names)];
            $out[$id]['parent_id'] = $p[$id] ?? null; $out[$id]['depth'] = $depth;
            if ($depth < 20) $walk($kids[$id] ?? [], $depth + 1);
        }
    };
    $walk($roots, 0);
    return array_values($out);
}

/** ¿Es jefe (admin del equipo) de este equipo o de alguno de los de arriba? */
function g7_team_led_by(PDO $db, int $uid, int $teamId): bool {
    $ids = array_merge([$teamId], g7_team_ancestors($db, $teamId));
    $q = $db->prepare("SELECT 1 FROM g7_team_members WHERE user_id = ? AND role = 'admin' AND team_id IN (" . implode(',', array_map('intval', $ids)) . ') LIMIT 1');
    $q->execute([$uid]);
    return (bool)$q->fetchColumn();
}

/** ¿Es jefe de algún equipo de ARRIBA (no del propio)? Es quien nombra o saca al jefe de un subequipo. */
function g7_team_led_above(PDO $db, int $uid, int $teamId): bool {
    $ids = g7_team_ancestors($db, $teamId);
    if (!$ids) return false;
    $q = $db->prepare("SELECT 1 FROM g7_team_members WHERE user_id = ? AND role = 'admin' AND team_id IN (" . implode(',', array_map('intval', $ids)) . ') LIMIT 1');
    $q->execute([$uid]);
    return (bool)$q->fetchColumn();
}

/** Copia los permisos de módulos de un equipo a otro (al crear un subequipo arranca con los de su padre). */
function g7_team_copy_permissions(PDO $db, int $from, int $to): void {
    $db->prepare('INSERT IGNORE INTO g7_team_permissions (team_id, module, action) SELECT ?, module, action FROM g7_team_permissions WHERE team_id = ?')->execute([$to, $from]);
}
