<?php
/**
 * perfil.php — Perfil de cada persona del equipo, en su propia página (2026-11-14). perfil.php?u=ID (sin u: el propio).
 * Portada, estado, rango y medallas; acciones (mensaje, reconocer, mencionar, copiar enlace, editar); pestañas Resumen,
 * Trabajo (pedidos hechos y terminados, tickets que atiende, solicitudes y tareas, con los permisos de quien mira),
 * Publicaciones, Logros, Reconocimientos y Muro. Datos: comunidad_lib.php. Doc: toolz/COMUNIDAD.md
 */
require_once __DIR__ . '/auth.php';
$currentUser = requireLogin();
require_once __DIR__ . '/csp_lib.php'; g7_csp_enforce();
require_once __DIR__ . '/comunidad_lib.php';
require_once __DIR__ . '/profile_fields_lib.php';
$db = getDB(); cm_install($db);
$me = (int)$currentUser['id'];
$uid = (int)($_GET['u'] ?? 0);
if (!$uid && !empty($_GET['user'])) { $q = $db->prepare('SELECT id FROM g7_users WHERE username = ?'); $q->execute([(string)$_GET['user']]); $uid = (int)$q->fetchColumn(); }
if (!$uid) $uid = $me;
$q = $db->prepare('SELECT * FROM g7_users WHERE id = ?'); $q->execute([$uid]); $p = $q->fetch(PDO::FETCH_ASSOC);
if (!$p) { http_response_code(404); $uid = $me; $q->execute([$me]); $p = $q->fetch(PDO::FETCH_ASSOC); }
try { $db->prepare('UPDATE g7_users SET last_seen = NOW() WHERE id = ?')->execute([$me]); } catch (Throwable $e) {}
$self = $uid === $me;
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$name = (string)($p['display_name'] ?: $p['username']);
$first = explode(' ', trim($name))[0];
$x = cm_profile_extra($db, $uid);
$work = cm_profile_work($db, $me, $uid);
$ago = function ($d) { if (!$d) return ''; $s = time() - strtotime((string)$d); if ($s < 60) return 'ahora'; if ($s < 3600) return floor($s / 60) . ' min'; if ($s < 86400) return floor($s / 3600) . ' h'; if ($s < 86400 * 30) return floor($s / 86400) . ' d'; return date('d/m/Y', strtotime((string)$d)); };
$AV = ['available' => ['Disponible', '#22c55e'], 'busy' => ['En reunión', '#f59e0b'], 'focus' => ['Concentrado', '#60a5fa'], 'away' => ['Fuera', '#71717a']];
[$avLabel, $avColor] = $AV[$x['availability']] ?? $AV['available'];
if (!$x['online']) { $avLabel = $x['last_seen'] ? 'Visto hace ' . $ago($x['last_seen']) : 'Desconectado'; $avColor = '#71717a'; }
$cols = ['#ff6600', '#a78bfa', '#4ade80', '#60a5fa', '#f59e0b', '#ec4899']; $col = $cols[ord(mb_strtoupper(mb_substr($name, 0, 1)) ?: 'A') % 6];
$avatar = function (?string $src, string $nm, int $size, string $cls = '', int $uidF = 0) use ($h, $cols) {   // $uidF: con iniciales, el marco de la foto (avatar_frame_lib.php) lo reconoce por el id
    if ($src && preg_match('#^(/(?!/)|https?://)#', $src)) return '<img class="pf-av ' . $cls . '" src="' . $h($src) . '" alt="" style="width:' . $size . 'px;height:' . $size . 'px">';
    $i = mb_strtoupper(mb_substr($nm ?: '?', 0, 1)); return '<span class="pf-av pf-av-l ' . $cls . '"' . ($uidF ? ' data-g7-uid="' . $uidF . '"' : '') . ' style="width:' . $size . 'px;height:' . $size . 'px;font-size:' . round($size * .42) . 'px;background:' . $cols[ord($i) % 6] . '">' . $h($i) . '</span>';
};
// contenido, publicaciones, muro, equipo
$content = []; try { $c = $db->prepare('SELECT id,name,type,img,created_at FROM g7_content WHERE author = ? ORDER BY created_at DESC LIMIT 8'); $c->execute([$p['username']]); $content = $c->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
$contentN = 0; try { $c = $db->prepare('SELECT COUNT(*) FROM g7_content WHERE author = ?'); $c->execute([$p['username']]); $contentN = (int)$c->fetchColumn(); } catch (Throwable $e) {}
$pq = $db->prepare('SELECT p.*,u.username,u.display_name,u.avatar FROM g7_posts p JOIN g7_users u ON u.id=p.user_id WHERE p.user_id=? ORDER BY p.created_at DESC LIMIT 20'); $pq->execute([$uid]);
$posts = cm_hydrate($db, $pq->fetchAll(PDO::FETCH_ASSOC), $me);
$postsN = 0; try { $c = $db->prepare('SELECT COUNT(*) FROM g7_posts WHERE user_id = ?'); $c->execute([$uid]); $postsN = (int)$c->fetchColumn(); } catch (Throwable $e) {}
$wall = []; try { $c = $db->prepare('SELECT c.*,u.username,u.display_name,u.avatar FROM g7_profile_comments c JOIN g7_users u ON u.id=c.author_id WHERE c.profile_id=? ORDER BY c.created_at DESC LIMIT 60'); $c->execute([$uid]); $wall = $c->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
$team = $db->query("SELECT id,username,display_name,avatar,position,availability,mood,last_seen FROM g7_users WHERE active = 1 ORDER BY (last_seen IS NOT NULL AND last_seen > DATE_SUB(NOW(), INTERVAL 5 MINUTE)) DESC, COALESCE(NULLIF(display_name,''),username)")->fetchAll(PDO::FETCH_ASSOC);
$sj = json_decode((string)($p['skills_json'] ?? '{}'), true) ?: []; $skills = array_keys(array_filter($sj, fn($v) => $v > 0));
pf_install($db); $apts = pf_aptitudes($p['aptitudes'] ?? null);   // aptitudes (profile_fields_lib.php)
$pe = $self ? pf_edit_data($db, $me) : null;   // formulario de edición (assets/profile-edit.js)
// Coincidencias por herramientas y aptitudes: en mi perfil, quién comparte más conmigo; en el de otra persona, qué tenemos en común
$matches = []; $common = null; $meP = $themP = null;
foreach (pf_people($db) as $pp) { if ($pp['id'] === $me) $meP = $pp; if ($pp['id'] === $uid) $themP = $pp; }
if ($self) $matches = pf_matches($db, $me, 8);
elseif ($meP && $themP && ($meP['tools'] || $meP['aptitudes']) && ($themP['tools'] || $themP['aptitudes'])) $common = pf_affinity($meP, $themP);
$mineLow = $meP ? array_map('mb_strtolower', array_merge($meP['tools'], $meP['aptitudes'])) : [];
// cada herramienta o aptitud lleva a "Quién sabe qué" en Comunidad; en el perfil de otra persona se marca lo que también tengo
$chip = function (string $s, bool $apt) use ($h, $self, $mineLow) {
    $c = !$self && in_array(mb_strtolower($s), $mineLow, true);
    return '<a href="/toolz/comunidad.php?habilidad=' . rawurlencode($s) . '" class="' . ($c ? 'common' : '') . '" title="' . ($c ? 'También lo tenés vos · ' : '') . '¿Quién más ' . ($apt ? 'tiene esta aptitud' : 'usa esta herramienta') . '?">' . ($c ? '<i class="fa-solid fa-check"></i> ' : '') . $h($s) . '</a>';
};
$ring = fn(int $pct, int $size = 46) => '<span class="pf-ring" style="--p:' . $pct . ';--s:' . $size . 'px"><b>' . $pct . '%</b></span>';
$loc = function_exists('g7_tz_public') ? g7_tz_public(g7_tz_row($uid)) : null;
$wsum = $work ? ['made' => $work['stats']['made'] ?? 0, 'resolved' => $work['stats']['resolved'] ?? 0, 'on_time' => $work['stats']['on_time'] ?? null] : null;
$TYPE = ['promo' => ['Promo', '#ff6600'], 'launch' => ['Lanzamiento', '#a78bfa'], 'paid' => ['Paid', '#4ade80'], 'social' => ['Redes', '#60a5fa'], 'mailings' => ['Mailing', '#f472b6'], 'tarea' => ['Tarea', '#fbbf24']];
$tickets = function (?array $blk, string $empty, bool $showWho, string $whoLabel) use ($h, $ago) {
    if (!$blk) return '';
    $o = '';
    foreach ($blk['items'] as $t) $o .= '<a class="pf-tk" href="' . $h($t['href']) . '"><span class="pf-tk-code">' . $h($t['code']) . '</span><span class="pf-tk-t"><b>' . $h($t['subject']) . '</b><small>'
        . ($showWho && $t['who'] ? $h($whoLabel . ' ' . $t['who']) . ' · ' : '') . $h($ago($t['when'])) . ($t['late'] ? ' · <em class="pf-late">vencido</em>' : '') . '</small></span><span class="pf-chip" style="--c:' . $h($t['color']) . '">' . $h($t['status']) . '</span></a>';
    if (!$blk['items']) $o = '<div class="pf-empty">' . $h($blk['hidden'] ? 'Hay ' . $blk['hidden'] . ' que no podés ver.' : $empty) . '</div>';
    elseif ($blk['hidden'] > 0) $o .= '<div class="pf-hidden"><i class="fa-solid fa-lock"></i> y ' . (int)$blk['hidden'] . ' más que no podés ver</div>';
    return $o;
};
$cfg = ['me' => $me, 'uid' => $uid, 'self' => $self, 'name' => $name, 'username' => (string)$p['username'], 'kudos' => CM_KUDOS,
    'team' => array_map(fn($t) => ['id' => (int)$t['id'], 'name' => (string)($t['display_name'] ?: $t['username']), 'username' => (string)$t['username'], 'avatar' => (string)$t['avatar']], $team)];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= $h($name) ?> · Perfil · <?= $h(g7_site('short')) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css" integrity="sha384-h/hnnw1Bi4nbpD6kE7nYfCXzovi622sY5WBxww8ARKwpdLj5kUWjRuyiXaD1U2JT" crossorigin="anonymous">
<link rel="stylesheet" href="/toolz/g7-theme.css">
<link rel="stylesheet" href="/toolz/assets/cm-kudos.css?v=<?= (int)@filemtime(__DIR__ . '/assets/cm-kudos.css') ?>">
<link rel="stylesheet" href="/toolz/assets/perfil.css?v=<?= (int)@filemtime(__DIR__ . '/assets/perfil.css') ?>">
</head>
<body>
<?php include __DIR__ . '/topbar.php'; ?>
<main class="pf">
  <!-- Equipo: todos los perfiles -->
  <aside class="pf-people">
    <div class="pf-people-h"><i class="fa-solid fa-people-group"></i> Equipo <span><?= count($team) ?></span></div>
    <label class="pf-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" id="pf-q" placeholder="Buscar persona…" autocomplete="off"></label>
    <nav class="pf-people-list" id="pf-people">
      <?php foreach ($team as $t): $on = $t['last_seen'] && time() - strtotime($t['last_seen']) < 300; $tn = (string)($t['display_name'] ?: $t['username']); ?>
      <a href="/toolz/perfil.php?u=<?= (int)$t['id'] ?>" class="pf-person<?= (int)$t['id'] === $uid ? ' on' : '' ?>" data-q="<?= $h(mb_strtolower($tn . ' ' . $t['username'] . ' ' . $t['position'])) ?>">
        <span class="pf-person-av"><?= $avatar($t['avatar'], $tn, 32, '', (int)$t['id']) ?><i class="pf-dot<?= $on ? ' live' : '' ?>" style="--c:<?= $on ? $h(($AV[$t['availability'] ?? 'available'] ?? $AV['available'])[1]) : '#71717a' ?>"></i></span>
        <span class="pf-person-t"><b><?= $h($tn) ?><?= (int)$t['id'] === $me ? ' <em>Tú</em>' : '' ?></b><small><?= $h($t['position'] ?: '@' . $t['username']) ?></small></span>
        <?php if (!empty($t['mood'])): ?><span class="pf-person-mood"><?= $h($t['mood']) ?></span><?php endif; ?>
      </a>
      <?php endforeach; ?>
    </nav>
  </aside>

  <section class="pf-main">
    <!-- Portada -->
    <header class="pf-hero" style="--pc:<?= $h($col) ?>">
      <div class="pf-cover"<?= !empty($p['cover']) && preg_match('#^/(?!/)#', (string)$p['cover']) ? ' style="background-image:url(\'' . $h($p['cover']) . '\')"' : '' ?>></div>
      <div class="pf-hero-in">
        <div class="pf-hero-av"><?= $avatar($p['avatar'], $name, 112, 'big', (int)$p['id']) ?><i class="pf-dot<?= $x['online'] ? ' live' : '' ?>" style="--c:<?= $h($avColor) ?>"></i></div>
        <div class="pf-hero-t">
          <h1><?= $h($name) ?><?= $x['mood'] ? ' <span class="pf-mood">' . $h($x['mood']) . '</span>' : '' ?><?= $self ? ' <span class="pf-you">Tú</span>' : '' ?></h1>
          <p class="pf-sub"><?= $p['position'] ? '<b>' . $h($p['position']) . '</b> · ' : '' ?>@<?= $h($p['username']) ?><?= $p['role'] === 'admin' ? ' · <span class="pf-role">Admin</span>' : '' ?></p>
          <div class="pf-meta">
            <span class="pf-status" style="--c:<?= $h($avColor) ?>"><i></i><?= $h($avLabel) ?></span>
            <?php if ($loc && !empty($loc['tz'])): ?><span class="pf-loc" data-tz="<?= $h($loc['tz']) ?>"><i class="fa-solid fa-earth-americas"></i> <?= $h(($loc['city'] ?? '') !== '' ? $loc['city'] : ($loc['zone'] ?? $loc['tz'])) ?> · <b class="pf-clock"></b></span>
            <?php elseif (!empty($p['location'])): ?><span class="pf-loc"><i class="fa-solid fa-location-dot"></i> <?= $h($p['location']) ?></span><?php endif; ?>
            <?php $MES = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
            if (!empty($p['birthday'])): $bt = strtotime((string)$p['birthday']); $isB = date('m-d', $bt) === date('m-d'); ?><span class="pf-bday<?= $isB ? ' today' : '' ?>"><?= $isB ? '🎉 ¡Hoy cumple años!' : '🎂 ' . (int)date('j', $bt) . ' de ' . $MES[(int)date('n', $bt)] ?></span><?php endif;
            $jd = $p['joined_at'] ?: substr((string)$p['created_at'], 0, 10); if ($jd): $jt = strtotime($jd); ?><span><i class="fa-solid fa-champagne-glasses"></i> En el equipo desde <?= $MES[(int)date('n', $jt)] . ' ' . date('Y', $jt) ?></span><?php endif; ?>
            <?php if ($x['focus']): ?><span class="pf-focus"><i class="fa-solid fa-crosshairs"></i> <?= $h($x['focus']) ?></span><?php endif; ?>
          </div>
        </div>
        <?php if ($x['level']): ?>
        <a class="pf-rank" href="/toolz/logros.php" title="Rango y XP" style="--lc:<?= $h($x['level']['color'] ?? '#f5c542') ?>">
          <span class="pf-rank-e"><?= $h($x['level']['emoji'] ?? '🏅') ?></span>
          <span class="pf-rank-t"><b><?= $h($x['level']['label'] ?? '') ?></b><small><?= number_format($x['xp'], 0, ',', '.') ?> XP · <?= (int)$x['medals'] ?> medallas</small><i class="pf-rank-bar"><i style="width:<?= (int)$x['pct'] ?>%"></i></i></span>
        </a>
        <?php endif; ?>
      </div>
      <div class="pf-actions">
        <?php if ($self): ?>
          <button type="button" class="pf-btn pri" data-pe-open><i class="fa-solid fa-user-pen"></i> Editar perfil</button>
          <a class="pf-btn" href="/toolz/logros.php"><i class="fa-solid fa-trophy"></i> Mis logros</a>
          <a class="pf-btn" href="/toolz/user_config.php"><i class="fa-solid fa-sliders"></i> Preferencias</a>
        <?php else: ?>
          <a class="pf-btn pri" href="/toolz/chat/?u=<?= $uid ?>"><i class="fa-solid fa-comment-dots"></i> Mensaje</a>
          <button class="pf-btn kudos" type="button" id="pf-kudos"><i class="fa-solid fa-award"></i> Reconocer</button>
          <a class="pf-btn" href="/toolz/comunidad.php?mencionar=<?= $h(rawurlencode((string)$p['username'])) ?>"><i class="fa-solid fa-at"></i> Mencionar</a>
        <?php endif; ?>
        <button class="pf-btn ghost" type="button" id="pf-copy" title="Copiar el enlace a este perfil"><i class="fa-solid fa-link"></i> Copiar enlace</button>
      </div>
    </header>

    <!-- Números -->
    <div class="pf-stats">
      <?php
      $stats = [];
      if ($wsum) { $stats[] = ['fa-paper-plane', '#60a5fa', $wsum['made'], 'Pedidos hechos']; $stats[] = ['fa-circle-check', '#22c55e', $wsum['resolved'], 'Tickets resueltos']; if ($wsum['on_time'] !== null) $stats[] = ['fa-stopwatch', '#f59e0b', $wsum['on_time'] . '%', 'A tiempo']; }
      $stats[] = ['fa-layer-group', '#ff6600', $contentN, 'Contenidos'];
      $stats[] = ['fa-medal', '#f5c542', $x['medals'], 'Medallas'];
      $stats[] = ['fa-award', '#ec4899', $x['kudos_n'], 'Reconocimientos'];
      foreach ($stats as [$ic, $c, $v, $l]): ?>
      <div class="pf-stat" style="--c:<?= $c ?>"><i class="fa-solid <?= $ic ?>"></i><b data-n="<?= $h($v) ?>"><?= $h($v) ?></b><small><?= $h($l) ?></small></div>
      <?php endforeach; ?>
    </div>

    <?php if ($self): ?>
    <!-- Completar el perfil (lo arma profile-edit.js con ProfileEdit.check) -->
    <div id="pf-complete"></div>
    <?php endif; ?>
    <!-- Pestañas -->
    <nav class="pf-tabs" id="pf-tabs" role="tablist">
      <button type="button" class="on" data-t="resumen"><i class="fa-solid fa-id-card"></i> Resumen</button>
      <?php if ($work): ?><button type="button" data-t="trabajo"><i class="fa-solid fa-briefcase"></i> Trabajo</button><?php endif; ?>
      <button type="button" data-t="posts"><i class="fa-solid fa-rss"></i> Publicaciones <small><?= $postsN ?></small></button>
      <button type="button" data-t="logros"><i class="fa-solid fa-trophy"></i> Logros <small><?= (int)$x['medals'] ?></small></button>
      <button type="button" data-t="kudos"><i class="fa-solid fa-award"></i> Reconocimientos <small><?= (int)$x['kudos_n'] ?></small></button>
      <button type="button" data-t="muro"><i class="fa-solid fa-comments"></i> Muro <small id="pf-wall-n"><?= count($wall) ?></small></button>
    </nav>

    <!-- Resumen -->
    <div class="pf-panel on" data-p="resumen">
      <div class="pf-grid">
        <div class="pf-card pf-span">
          <h3><span class="pf-ic" style="--c:#60a5fa"><i class="fa-solid fa-quote-left"></i></span> Sobre <?= $self ? 'vos' : $h($first) ?></h3>
          <p class="pf-bio"><?= $p['bio'] ? nl2br($h($p['bio'])) : '<span class="pf-muted">' . ($self ? 'Todavía no escribiste tu bio. <a href="#" data-pe-open data-pe-focus="bio">Escribila</a>.' : 'Sin bio todavía.') . '</span>' ?></p>
          <?php if ($skills): ?><h4 class="pf-sub"><i class="fa-solid fa-screwdriver-wrench"></i> Herramientas</h4><div class="pf-skills"><?php foreach ($skills as $s) echo $chip($s, false); ?></div><?php endif; ?>
          <?php if ($apts): ?><h4 class="pf-sub"><i class="fa-solid fa-star"></i> Aptitudes</h4><div class="pf-skills pf-apts"><?php foreach ($apts as $s) echo $chip($s, true); ?></div><?php endif; ?>
          <?php if ($self && (!$skills || !$apts)): ?><p class="pf-muted pf-addsk"><a href="#" data-pe-open data-pe-focus="<?= $skills ? 'apt' : 'tools' ?>"><i class="fa-solid fa-plus"></i> Sumá tus <?= !$skills && !$apts ? 'herramientas y aptitudes' : ($skills ? 'aptitudes' : 'herramientas') ?></a></p><?php endif; ?>
        </div>
        <?php if ($self): ?>
        <!-- Coincidencias: quién comparte más herramientas y aptitudes conmigo (pf_matches, profile_fields_lib.php) -->
        <div class="pf-card pf-span pf-match" id="coincidencias">
          <h3><span class="pf-ic" style="--c:#a78bfa"><i class="fa-solid fa-people-arrows"></i></span> Coincidencias <small class="pf-h-sub">quién comparte más herramientas y aptitudes con vos</small>
            <a class="pf-h-link" href="/toolz/comunidad.php?quien=1"><i class="fa-solid fa-user-gear"></i> Quién sabe qué</a></h3>
          <?php if (!$meP || (!$meP['tools'] && !$meP['aptitudes'])): ?>
            <div class="pf-empty">Cargá tus herramientas y aptitudes para ver con quién coincidís. <a href="#" data-pe-open data-pe-focus="tools">Cargarlas</a></div>
          <?php elseif (!$matches): ?>
            <div class="pf-empty">Todavía nadie del equipo comparte tus herramientas o aptitudes. Cuando más personas carguen las suyas, van a aparecer acá.</div>
          <?php else: ?>
          <div class="pf-mlist">
            <?php foreach ($matches as $i => $m): $sh = array_merge(array_map(fn($t) => [$t, 't'], $m['tools']), array_map(fn($t) => [$t, 'a'], $m['aptitudes'])); $more = count($sh) - 5; ?>
            <div class="pf-mrow<?= $i === 0 ? ' top' : '' ?>">
              <?= $ring($m['pct']) ?>
              <a class="pf-mav" href="/toolz/perfil.php?u=<?= (int)$m['id'] ?>" data-g7-user="<?= (int)$m['id'] ?>"><?= $avatar($m['avatar'], $m['name'], 38, '', (int)$m['id']) ?><?= $m['online'] ? '<i class="pf-mon"></i>' : '' ?></a>
              <div class="pf-mt"><a href="/toolz/perfil.php?u=<?= (int)$m['id'] ?>" data-g7-user="<?= (int)$m['id'] ?>"><b><?= $h($m['name']) ?></b></a><?= $i === 0 ? ' <span class="pf-mbest">Mayor afinidad</span>' : '' ?><small><?= $h($m['position']) ?><?= $m['position'] ? ' · ' : '' ?><?= (int)$m['shared'] ?> en común</small>
                <div class="pf-mchips"><?php foreach (array_slice($sh, 0, 5) as [$t, $k]): ?><span class="<?= $k ?>"><?= $h($t) ?></span><?php endforeach; ?><?= $more > 0 ? '<span class="more">+' . $more . '</span>' : '' ?></div></div>
              <div class="pf-macts"><a href="/toolz/chat/?u=<?= (int)$m['id'] ?>" title="Mandar mensaje" aria-label="Mandar mensaje a <?= $h($m['name']) ?>"><i class="fa-solid fa-paper-plane"></i></a><a href="/toolz/perfil.php?u=<?= (int)$m['id'] ?>" title="Ver perfil" aria-label="Ver perfil de <?= $h($m['name']) ?>"><i class="fa-solid fa-id-card"></i></a></div>
            </div>
            <?php endforeach; ?>
          </div>
          <p class="pf-mnote">La afinidad cuenta lo que tienen en común sobre todo lo que cargaron los dos: marcar muchas no suma por sí solo.</p>
          <?php endif; ?>
        </div>
        <?php elseif ($common !== null): ?>
        <!-- En común conmigo (pf_affinity) -->
        <div class="pf-card pf-span pf-match pf-common">
          <h3><span class="pf-ic" style="--c:#a78bfa"><i class="fa-solid fa-people-arrows"></i></span> En común con vos</h3>
          <div class="pf-crow"><?= $ring($common['pct'], 64) ?>
            <div><b><?= $common['shared'] ? 'Comparten ' . (int)$common['shared'] . ' ' . ($common['shared'] === 1 ? 'herramienta o aptitud' : 'herramientas y aptitudes') : 'Todavía no tienen herramientas ni aptitudes en común' ?></b>
              <?php if ($common['shared']): ?><div class="pf-mchips"><?php foreach ($common['tools'] as $t): ?><span class="t"><?= $h($t) ?></span><?php endforeach; ?><?php foreach ($common['aptitudes'] as $t): ?><span class="a"><?= $h($t) ?></span><?php endforeach; ?></div><?php endif; ?></div></div>
        </div>
        <?php endif; ?>
        <?php if ($work && ($work['tasks']['now'] ?? null) && empty($work['tasks']['now']['hidden'])): $n = $work['tasks']['now']; ?>
        <div class="pf-card pf-now">
          <h3><span class="pf-ic" style="--c:#22c55e"><i class="fa-solid fa-play"></i></span> Ahora está con</h3>
          <a class="pf-tk" href="<?= $h($n['href']) ?>"><span class="pf-tk-code"><?= $h($n['code']) ?></span><span class="pf-tk-t"><b><?= $h($n['title']) ?></b><small>desde hace <?= $h($ago($n['since'])) ?></small></span><span class="pf-live"></span></a>
        </div>
        <?php endif; ?>
        <div class="pf-card">
          <h3><span class="pf-ic" style="--c:#a78bfa"><i class="fa-solid fa-address-card"></i></span> Contacto</h3>
          <ul class="pf-contact">
            <?php if ($p['email']): ?><li><i class="fa-solid fa-envelope"></i><a href="mailto:<?= $h($p['email']) ?>"><?= $h($p['email']) ?></a></li><?php endif; ?>
            <?php if ($p['phone']): ?><li><i class="fa-solid fa-phone"></i><?= $h($p['phone']) ?></li><?php endif; ?>
            <?php if ($p['location']): ?><li><i class="fa-solid fa-location-dot"></i><?= $h($p['location']) ?></li><?php endif; ?>
            <?php if (!empty($p['instagram'])): ?><li><i class="fa-brands fa-instagram"></i><a href="https://instagram.com/<?= $h(rawurlencode(ltrim((string)$p['instagram'], '@'))) ?>" target="_blank" rel="noopener"><?= $h($p['instagram']) ?></a></li><?php endif; ?>
            <?php if (!empty($p['behance'])): ?><li><i class="fa-brands fa-behance"></i><a href="https://behance.net/<?= $h(rawurlencode((string)$p['behance'])) ?>" target="_blank" rel="noopener"><?= $h($p['behance']) ?></a></li><?php endif; ?>
            <?php if (!empty($p['linkedin'])): ?><li><i class="fa-brands fa-linkedin"></i><a href="https://linkedin.com/<?= $h(preg_replace('#^https?://(www\.)?linkedin\.com/#i', '', (string)$p['linkedin'])) ?>" target="_blank" rel="noopener">LinkedIn</a></li><?php endif; ?>
            <?php if (!empty($p['portfolio']) && preg_match('#^https?://#i', (string)$p['portfolio'])): ?><li><i class="fa-solid fa-globe"></i><a href="<?= $h($p['portfolio']) ?>" target="_blank" rel="noopener">Portfolio</a></li><?php endif; ?>
            <?php if (!$p['email'] && !$p['phone'] && !$p['location']): ?><li class="pf-muted">Sin datos de contacto.</li><?php endif; ?>
          </ul>
        </div>
        <?php if ($x['kudos']): ?>
        <div class="pf-card">
          <h3><span class="pf-ic" style="--c:#ec4899"><i class="fa-solid fa-award"></i></span> Lo que más le reconocen</h3>
          <div class="pf-kchips"><?php foreach (array_slice($x['kudos'], 0, 4) as $k): ?><span><?= $h($k['emoji']) ?> <?= $h($k['name']) ?> <b>×<?= (int)$k['n'] ?></b></span><?php endforeach; ?></div>
        </div>
        <?php endif; ?>
        <?php if ($x['ach']): ?>
        <div class="pf-card">
          <h3><span class="pf-ic" style="--c:#f5c542"><i class="fa-solid fa-medal"></i></span> Medallas destacadas</h3>
          <div class="pf-medals mini"><?php foreach (array_slice($x['ach'], 0, 6) as $a): ?><span class="pf-medal" style="--tc:<?= $h($a['color']) ?>" title="<?= $h($a['name'] . ' · ' . $a['tname']) ?>"><i class="fa-solid <?= $h($a['icon']) ?>"></i></span><?php endforeach; ?></div>
        </div>
        <?php endif; ?>
        <?php if ($content): ?>
        <div class="pf-card pf-span">
          <h3><span class="pf-ic" style="--c:#ff6600"><i class="fa-solid fa-layer-group"></i></span> Últimos contenidos <a class="pf-more" href="/toolz/promos/?search=<?= $h(rawurlencode((string)$p['username'])) ?>">Ver todo</a></h3>
          <div class="pf-content">
            <?php foreach ($content as $c): [$tl, $tc] = $TYPE[$c['type']] ?? [ucfirst((string)$c['type']), '#71717a']; $img = (string)$c['img']; if ($img && !preg_match('#^(/|https?://)#', $img)) $img = '/toolz/promos/' . $img; ?>
            <a class="pf-ct" href="/toolz/promos/?search=<?= $h(rawurlencode((string)$c['name'])) ?>" style="--c:<?= $tc ?>"><span class="pf-ct-img"><?= $img ? '<img src="' . $h($img) . '" alt="" loading="lazy">' : '<i class="fa-regular fa-image"></i>' ?></span><b><?= $h($c['name']) ?></b><small><?= $h($tl) ?> · <?= $h($ago($c['created_at'])) ?></small></a>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($work): ?>
    <!-- Trabajo -->
    <div class="pf-panel" data-p="trabajo">
      <div class="pf-grid">
        <div class="pf-card"><h3><span class="pf-ic" style="--c:#60a5fa"><i class="fa-solid fa-paper-plane"></i></span> Pedidos en curso <span class="pf-n"><?= (int)($work['made_open']['total'] ?? 0) ?></span></h3><?= $tickets($work['made_open'], 'No tiene pedidos abiertos.', true, 'Lo atiende') ?></div>
        <div class="pf-card"><h3><span class="pf-ic" style="--c:#22c55e"><i class="fa-solid fa-circle-check"></i></span> Pedidos terminados <span class="pf-n"><?= (int)($work['made_done']['total'] ?? 0) ?></span></h3><?= $tickets($work['made_done'], 'Todavía no tiene pedidos terminados.', true, 'Lo resolvió') ?></div>
        <div class="pf-card"><h3><span class="pf-ic" style="--c:#f59e0b"><i class="fa-solid fa-headset"></i></span> Tickets que atiende <span class="pf-n"><?= (int)($work['serving']['total'] ?? 0) ?></span></h3><?= $tickets($work['serving'], 'No tiene tickets asignados abiertos.', true, 'Pidió') ?></div>
        <div class="pf-card"><h3><span class="pf-ic" style="--c:#a78bfa"><i class="fa-solid fa-inbox"></i></span> Solicitudes <span class="pf-n"><?= (int)($work['requests']['total'] ?? 0) ?></span></h3>
          <?php $rq = $work['requests']; if ($rq && $rq['items']): foreach ($rq['items'] as $r): ?>
            <a class="pf-tk" href="<?= $h($r['href']) ?>"><span class="pf-tk-code">S-<?= (int)$r['id'] ?></span><span class="pf-tk-t"><b><?= $h($r['title']) ?></b><small><?= $h(($r['form'] ? $r['form'] . ' · ' : '') . $ago($r['created'])) ?></small></span><span class="pf-chip" style="--c:<?= $h($r['color']) ?>"><?= $h($r['status']) ?></span></a>
          <?php endforeach; if ($rq['hidden'] > 0): ?><div class="pf-hidden"><i class="fa-solid fa-lock"></i> y <?= (int)$rq['hidden'] ?> más que no podés ver</div><?php endif; else: ?><div class="pf-empty"><?= $rq && $rq['hidden'] ? 'Hay ' . (int)$rq['hidden'] . ' que no podés ver.' : 'No hizo solicitudes.' ?></div><?php endif; ?>
        </div>
        <div class="pf-card pf-span"><h3><span class="pf-ic" style="--c:#14b8a6"><i class="fa-solid fa-list-check"></i></span> Tareas en curso <span class="pf-n"><?= (int)($work['tasks']['total'] ?? 0) ?></span></h3>
          <?php $tk = $work['tasks']; if ($tk && $tk['tasks']): foreach ($tk['tasks'] as $t): ?>
            <a class="pf-tk" href="<?= $h($t['href']) ?>"><span class="pf-tk-code"><?= $h($t['code']) ?></span><span class="pf-tk-t"><b><?= $h($t['title']) ?></b><small><?= $t['due'] ? 'Vence ' . $h(date('d/m', strtotime((string)$t['due']))) : 'Sin fecha' ?></small></span><span class="pf-chip" style="--c:<?= $h($t['color']) ?>"><?= $h($t['status']) ?></span></a>
          <?php endforeach; if (($tk['hidden'] ?? 0) > 0): ?><div class="pf-hidden"><i class="fa-solid fa-lock"></i> y <?= (int)$tk['hidden'] ?> más que no podés ver</div><?php endif; else: ?><div class="pf-empty">No tiene tareas en curso.</div><?php endif; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Publicaciones -->
    <div class="pf-panel" data-p="posts">
      <?php if (!$posts): ?><div class="pf-card pf-empty-big"><i class="fa-solid fa-pen-nib"></i><?= $self ? 'Todavía no publicaste nada. <a href="/toolz/comunidad.php">Ir a Comunidad</a>' : 'Todavía no publicó nada.' ?></div><?php endif; ?>
      <div class="pf-posts">
        <?php foreach ($posts as $po): $rx = 0; foreach ($po['reactions'] as $r) $rx += (int)$r['cnt']; $isK = ($po['kind'] ?? '') === 'kudos'; $kb = $isK ? (CM_KUDOS[$po['badge']] ?? ['🏆', 'Reconocimiento']) : null; ?>
        <article class="pf-post<?= $isK ? ' k' : '' ?>">
          <?php if ($isK && $po['target']): ?><div class="pf-post-k"><?= $h($kb[0]) ?> Reconoció a <a href="/toolz/perfil.php?u=<?= (int)$po['target']['id'] ?>"><?= $h($po['target']['display_name'] ?: $po['target']['username']) ?></a> · <b><?= $h($kb[1]) ?></b></div><?php endif; ?>
          <p><?= nl2br($h($po['body'])) ?></p>
          <?php if (!empty($po['img'])): ?><img src="<?= $h($po['img']) ?>" alt="" loading="lazy"><?php endif; ?>
          <footer><span><?= $h($ago($po['created_at'])) ?><?= !empty($po['edited_at']) ? ' · editado' : '' ?></span><span><i class="fa-regular fa-heart"></i> <?= $rx ?></span><span><i class="fa-regular fa-comment"></i> <?= count($po['replies']) ?></span><a href="/toolz/comunidad.php?post=<?= (int)$po['id'] ?>">Ver en Comunidad <i class="fa-solid fa-arrow-right"></i></a></footer>
        </article>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Logros -->
    <div class="pf-panel" data-p="logros">
      <?php if (!$x['ach']): ?><div class="pf-card pf-empty-big"><i class="fa-solid fa-trophy"></i>Todavía no ganó medallas.</div><?php else: ?>
      <div class="pf-medals">
        <?php foreach ($x['ach'] as $a): ?><div class="pf-mcard" style="--tc:<?= $h($a['color']) ?>"><span class="pf-medal"><i class="fa-solid <?= $h($a['icon']) ?>"></i></span><b><?= $h($a['name']) ?></b><small><?= $h($a['tname']) ?></small></div><?php endforeach; ?>
      </div>
      <p class="pf-center"><?= $self ? '<a class="pf-btn" href="/toolz/logros.php"><i class="fa-solid fa-trophy"></i> Ver todos mis logros</a>' : 'Las ' . count($x['ach']) . ' medallas más altas de ' . $h($first) . '.' ?></p>
      <?php endif; ?>
    </div>

    <!-- Reconocimientos -->
    <div class="pf-panel" data-p="kudos">
      <?php if ($x['kudos']): ?><div class="pf-kchips big"><?php foreach ($x['kudos'] as $k): ?><span><?= $h($k['emoji']) ?> <?= $h($k['name']) ?> <b>×<?= (int)$k['n'] ?></b></span><?php endforeach; ?></div><?php endif; ?>
      <div class="pf-klist">
        <?php foreach ($x['kudos_list'] as $k): $kb = CM_KUDOS[$k['badge']] ?? ['🏆', 'Reconocimiento']; $gn = (string)($k['display_name'] ?: $k['username']); ?>
        <div class="pf-kcard"><span class="pf-kcard-e"><?= $h($kb[0]) ?></span><div><b><?= $h($kb[1]) ?></b><p><?= nl2br($h($k['body'])) ?></p><small><a href="/toolz/perfil.php?u=<?= (int)$k['uid'] ?>"><?= $avatar($k['avatar'], $gn, 18) ?> <?= $h($gn) ?></a> · <?= $h($ago($k['created_at'])) ?></small></div></div>
        <?php endforeach; ?>
        <?php if (!$x['kudos_list']): ?><div class="pf-card pf-empty-big"><i class="fa-solid fa-award"></i><?= $self ? 'Todavía no te reconocieron. ¡Reconocé a alguien del equipo y arrancá la ola!' : 'Nadie le dio un reconocimiento todavía. <button type="button" class="pf-link" id="pf-kudos2">Sé la primera persona</button>' ?></div><?php endif; ?>
      </div>
    </div>

    <!-- Muro -->
    <div class="pf-panel" data-p="muro">
      <div class="pf-card pf-wall-form">
        <textarea id="pf-wall-in" rows="2" maxlength="500" placeholder="<?= $self ? 'Escribí algo en tu muro…' : 'Dejale un mensaje a ' . $h($first) . '…' ?>"></textarea>
        <button class="pf-btn pri" type="button" id="pf-wall-send"><i class="fa-solid fa-paper-plane"></i> Publicar</button>
      </div>
      <div class="pf-wall" id="pf-wall">
        <?php foreach ($wall as $c): $cn = (string)($c['display_name'] ?: $c['username']); $canDel = (int)$c['author_id'] === $me || $self || $currentUser['role'] === 'admin'; ?>
        <div class="pf-wc" data-id="<?= (int)$c['id'] ?>"><a href="/toolz/perfil.php?u=<?= (int)$c['author_id'] ?>"><?= $avatar($c['avatar'], $cn, 34, '', (int)$c['author_id']) ?></a><div><b><a href="/toolz/perfil.php?u=<?= (int)$c['author_id'] ?>"><?= $h($cn) ?></a> <small><?= $h($ago($c['created_at'])) ?></small></b><p><?= nl2br($h($c['body'])) ?></p></div><?php if ($canDel): ?><button type="button" class="pf-wc-del" title="Borrar"><i class="fa-solid fa-trash-can"></i></button><?php endif; ?></div>
        <?php endforeach; ?>
        <?php if (!$wall): ?><div class="pf-empty" id="pf-wall-empty">El muro está vacío. ¡Escribí el primer mensaje!</div><?php endif; ?>
      </div>
    </div>
  </section>
</main>
<script type="application/json" id="pf-data"><?= json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="/toolz/assets/cm-kudos.js?v=<?= (int)@filemtime(__DIR__ . '/assets/cm-kudos.js') ?>"></script>
<script src="/toolz/assets/perfil.js?v=<?= (int)@filemtime(__DIR__ . '/assets/perfil.js') ?>"></script>
<?php if ($pe): ?>
<script type="application/json" id="pe-data"><?= json_encode($pe + ['save_url' => '/toolz/comunidad.php'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<link rel="stylesheet" href="/toolz/assets/profile-edit.css?v=<?= (int)@filemtime(__DIR__ . '/assets/profile-edit.css') ?>">
<script src="/toolz/assets/profile-picker.js?v=<?= (int)@filemtime(__DIR__ . '/assets/profile-picker.js') ?>"></script>
<script src="/toolz/assets/profile-edit.js?v=<?= (int)@filemtime(__DIR__ . '/assets/profile-edit.js') ?>"></script>
<?php endif; ?>
</body>
</html>
