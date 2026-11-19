<?php
/**
 * logros.php — Mis logros: la vitrina de cada persona. Nivel y XP (gamification.php), logros por niveles
 * (achievements_lib.php, los dibuja assets/logros.js) y el ranking del equipo. Doc: toolz/LOGROS.md
 */
require_once __DIR__ . '/auth.php';
$currentUser = requireLogin();
require_once __DIR__ . '/csp_lib.php'; g7_csp_enforce();
require_once __DIR__ . '/achievements_lib.php';
if (is_file(__DIR__ . '/gamification.php')) require_once __DIR__ . '/gamification.php';
$db = getDB(); $uid = (int)$currentUser['id'];
g7_ach_install($db);
try { g7_ach_evaluate($db, $uid); } catch (Throwable $e) {}
$isAdmin = ($currentUser['role'] === 'admin');
$me = $db->prepare('SELECT display_name, username, avatar FROM g7_users WHERE id = ?'); $me->execute([$uid]); $me = $me->fetch() ?: [];
$gam = function_exists('g7GetUserGamif') ? g7GetUserGamif($db, $uid) : ['xp' => 0, 'level_data' => ['label' => 'Rookie', 'emoji' => '🌱', 'color' => '#71717a', 'min' => 0], 'next_level' => null, 'progress_pct' => 0];
// ranking: logros desbloqueados (cada nivel cuenta) del equipo activo
$rank = [];
try {
    $rank = $db->query("SELECT u.id, COALESCE(NULLIF(u.display_name,''), u.username) AS name, u.avatar, COUNT(a.tier) AS n, SUM(a.tier >= 3) AS gold
        FROM g7_users u LEFT JOIN g7_achievements a ON a.user_id = u.id WHERE u.active = 1 GROUP BY u.id ORDER BY n DESC, gold DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
$data = ['me' => $uid, 'level' => $gam['level_data'], 'next' => $gam['next_level'], 'xp' => (int)$gam['xp'], 'pct' => (int)$gam['progress_pct'], 'rank' => $rank];
$name = (string)($me['display_name'] ?: ($me['username'] ?? ''));
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Mis logros · <?= htmlspecialchars((string)g7_site('short')) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css" integrity="sha384-h/hnnw1Bi4nbpD6kE7nYfCXzovi622sY5WBxww8ARKwpdLj5kUWjRuyiXaD1U2JT" crossorigin="anonymous">
<link rel="stylesheet" href="/toolz/g7-theme.css">
<link rel="stylesheet" href="/toolz/assets/logros.css?v=<?= (int)@filemtime(__DIR__ . '/assets/logros.css') ?>">
</head>
<body>
<?php include __DIR__ . '/topbar.php'; ?>
<main class="lg">
  <section class="lg-hero">
    <div class="lg-ring" style="--p:<?= (int)$gam['progress_pct'] ?>;--lc:<?= htmlspecialchars((string)$gam['level_data']['color']) ?>">
      <svg viewBox="0 0 120 120" aria-hidden="true"><circle cx="60" cy="60" r="52" class="bg"/><circle cx="60" cy="60" r="52" class="fg"/></svg>
      <div class="lg-ring-in">
        <?php if (!empty($me['avatar'])): ?><img src="<?= htmlspecialchars((string)$me['avatar']) ?>" alt=""><?php else: ?><span><?= htmlspecialchars(mb_strtoupper(mb_substr($name, 0, 1))) ?></span><?php endif; ?>
        <b class="lg-emoji"><?= htmlspecialchars((string)$gam['level_data']['emoji']) ?></b>
      </div>
    </div>
    <div class="lg-hero-txt">
      <small>Tus logros</small>
      <h1><?= htmlspecialchars(explode(' ', trim($name))[0]) ?>, sos <span style="color:<?= htmlspecialchars((string)$gam['level_data']['color']) ?>"><?= htmlspecialchars((string)$gam['level_data']['label']) ?></span></h1>
      <div class="lg-xp"><span class="lg-xpbar"><i style="width:<?= (int)$gam['progress_pct'] ?>%"></i></span><b><?= number_format((int)$gam['xp'], 0, ',', '.') ?> XP</b></div>
      <p><?= $gam['next_level'] ? 'Te faltan <b>' . number_format(max(0, (int)$gam['next_level']['min'] - (int)$gam['xp']), 0, ',', '.') . ' XP</b> para ser ' . htmlspecialchars((string)$gam['next_level']['label']) . ' ' . htmlspecialchars((string)$gam['next_level']['emoji']) : '¡Llegaste al nivel máximo!' ?></p>
    </div>
    <div class="lg-tally" id="lg-tally"></div>
  </section>

  <div class="lg-tabs" id="lg-tabs" role="tablist"></div>
  <div class="lg-tools"><label class="lg-search"><i class="fa-solid fa-magnifying-glass"></i><input id="lg-q" type="search" placeholder="Buscar logro…" autocomplete="off"></label><div class="lg-seg" id="lg-seg"><button type="button" class="on" data-f="all">Todos</button><button type="button" data-f="got">Ganados</button><button type="button" data-f="todo">Por ganar</button></div><span class="lg-total" id="lg-total"></span></div>
  <div class="lg-wrap">
    <section class="lg-grid" id="lg-grid" aria-live="polite"><div class="lg-loading"><i class="fa-solid fa-spinner fa-spin"></i> Cargando tus logros…</div></section>
    <aside class="lg-side">
      <div class="lg-card">
        <h3><i class="fa-solid fa-ranking-star"></i> Ranking del equipo</h3>
        <ol class="lg-rank" id="lg-rank"></ol>
      </div>
      <div class="lg-card lg-how">
        <h3><i class="fa-solid fa-circle-info"></i> Cómo funciona</h3>
        <p>Cada logro tiene hasta cuatro niveles: <b style="color:#d08a4d">Bronce</b>, <b style="color:#b8c4d4">Plata</b>, <b style="color:#f5c542">Oro</b> y <b style="color:#7dd3fc">Diamante</b>. Se desbloquean solos con lo que hacés en Cronos, y cada nivel suma XP para subir de rango.</p>
      </div>
    </aside>
  </div>
</main>
<script type="application/json" id="lg-data"><?= json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="/toolz/assets/logros.js?v=<?= (int)@filemtime(__DIR__ . '/assets/logros.js') ?>"></script>
</body>
</html>
