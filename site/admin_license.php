<?php if (!isset($currentUser)) { http_response_code(404); exit; } /* fragmento: solo se incluye desde su página */ ?>
<?php
/* admin_license.php — Sección "Licencia" del Admin: plan, vencimiento, uso y módulos incluidos. Solo aparece si el sitio tiene licencia (license_lib.php). */
if (!lic_active()) return;
$L = lic_get() ?? ['plan' => '—']; $st = lic_state(); $days = lic_days_left(lic_get() ?? []); $U = lic_usage(getDB());
$lim = ['users' => lic_limit('users'), 'clients' => lic_limit('clients'), 'storage' => lic_limit('storage')];
$stLabel = ['ok' => ['Activa', 'ok'], 'aviso' => ['Por vencer', 'warn'], 'gracia' => ['Vencida, en gracia', 'warn'], 'solo_lectura' => ['Solo lectura', 'bad'], 'suspendida' => ['Suspendida', 'bad'], 'alterada' => ['No se pudo validar', 'bad']][$st] ?? ['Activa', 'ok'];
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$bar = function (string $label, float $used, ?float $max, string $fmtUsed, string $fmtMax) use ($h) {
    $pct = $max ? min(100, round($used / $max * 100)) : 0;
    $cls = !$max ? '' : ($pct >= 100 ? 'bad' : ($pct >= 85 ? 'warn' : ''));
    return '<div class="lic-use"><div class="lic-use-top"><span>' . $h($label) . '</span><b>' . $h($fmtUsed) . ($max ? ' <small>de ' . $h($fmtMax) . '</small>' : ' <small>sin tope</small>') . '</b></div>'
        . '<div class="lic-track"><i class="' . $cls . '" style="width:' . ($max ? max(2, $pct) : 0) . '%"></i></div></div>';
};
$exp = !empty($L['expires']) ? date('d/m/Y', strtotime($L['expires'])) : null;
$mods = g7_modules(); $off = lic_modules_off();
?>
<style>
.lic-head{display:flex;flex-wrap:wrap;gap:14px;align-items:center;justify-content:space-between;padding:20px;border-bottom:1px solid var(--g7-border-3)}
.lic-plan{font-size:22px;font-weight:800}
.lic-plan small{display:block;font-size:12px;font-weight:500;color:var(--g7-text-4);margin-top:2px}
.lic-tag{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:700;padding:4px 12px;border-radius:20px}
.lic-tag.ok{background:rgba(34,197,94,.12);color:#22c55e}.lic-tag.warn{background:rgba(251,191,36,.13);color:#fbbf24}.lic-tag.bad{background:rgba(239,68,68,.12);color:#ef4444}
.lic-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px;padding:20px}
.lic-use-top{display:flex;justify-content:space-between;align-items:baseline;font-size:12.5px;margin-bottom:8px;color:var(--g7-text-3)}
.lic-use-top b{color:var(--g7-text);font-size:15px}.lic-use-top small{color:var(--g7-text-4);font-weight:500;font-size:12px}
.lic-track{height:8px;border-radius:8px;background:var(--g7-bg-5);overflow:hidden}
.lic-track i{display:block;height:100%;border-radius:8px;background:var(--g7-accent)}.lic-track i.warn{background:#fbbf24}.lic-track i.bad{background:#ef4444}
.lic-msg{margin:0 20px 18px;padding:12px 14px;border-radius:10px;font-size:13px;line-height:1.5}
.lic-msg.warn{background:rgba(251,191,36,.1);border:1px solid rgba(251,191,36,.3)}.lic-msg.bad{background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.3)}
.lic-mods{display:flex;flex-wrap:wrap;gap:6px;padding:16px 20px}
.lic-mod{font-size:12px;padding:5px 11px;border-radius:20px;background:var(--g7-bg-5);color:var(--g7-text-2)}
.lic-mod.off{opacity:.45;text-decoration:line-through}
.lic-foot{padding:14px 20px;border-top:1px solid var(--g7-border-3);font-size:12.5px;color:var(--g7-text-3)}
@media (max-width:760px){.lic-grid{grid-template-columns:1fr}}
</style>
<section class="adm-section" id="sec-license">
  <div class="adm-title">Licencia</div>
  <div class="adm-sub">Tu plan, cuándo vence y cuánto estás usando. Los topes se aplican solos.</div>
  <div class="adm-card">
    <div class="lic-head">
      <div class="lic-plan">Plan <?= $h($L['plan']) ?><small><?= $exp ? ($days !== null && $days >= 0 ? "Vence el $exp · faltan $days " . ($days === 1 ? 'día' : 'días') : "Venció el $exp") : 'Sin vencimiento' ?></small></div>
      <span class="lic-tag <?= $stLabel[1] ?>"><?= $h($stLabel[0]) ?></span>
    </div>
    <?php if ($b = lic_banner()): ?><div class="lic-msg <?= $b[0] ?>"><?= $h($b[1]) ?></div><?php endif; ?>
    <div class="lic-grid">
      <?= $bar('Usuarios activos', (float)$U['users'], $lim['users'], (string)$U['users'], (string)(int)$lim['users']) ?>
      <?= $bar('Clientes', (float)$U['clients'], $lim['clients'], (string)$U['clients'], (string)(int)$lim['clients']) ?>
      <?= $bar('Espacio', $U['bytes'] / 1073741824, $lim['storage'], lic_gb((int)$U['bytes']), rtrim(rtrim(number_format((float)$lim['storage'], 1, ',', ''), '0'), ',') . ' GB') ?>
    </div>
    <div class="lic-foot" style="border-top:1px solid var(--g7-border-3)"><b style="color:var(--g7-text)">Herramientas</b> · las tachadas no están en tu plan</div>
    <div class="lic-mods">
      <?php foreach ($mods as $id => $m) { if (in_array($id, ['dashboard', 'perfil'], true) || (!empty($m['site_off']) && !in_array($id, $off, true))) continue; ?>
        <span class="lic-mod<?= in_array($id, $off, true) ? ' off' : '' ?>"><?= $h($m['label'] ?? $id) ?></span>
      <?php } ?>
    </div>
    <?php // Limits inside the modules (LIC_LIMITS): only the ones the plan sets
    $LL = (array)($L['limits'] ?? []);
    if ($LL) {
        $used = []; try { $used['social_accounts'] = (int)getDB()->query("SELECT COUNT(*) FROM g7_ss_accounts WHERE token_enc IS NOT NULL AND token_enc <> ''")->fetchColumn(); } catch (Throwable $e) {}
        $byMod = [];
        foreach (LIC_LIMITS as $k => [$mod, $label, $type, $opts]) {
            if (!array_key_exists($k, $LL)) continue;
            $v = $LL[$k];
            $txt = $type === 'num' ? (isset($used[$k]) ? $used[$k] . ' de ' . (int)$v : 'hasta ' . (int)$v) : ($type === 'bool' ? ($v ? 'Incluido' : 'No incluido') : ($v ? implode(', ', array_map(fn($x) => $opts[$x] ?? $x, $v)) : 'Ninguna'));
            $byMod[$mod][] = [$label, $txt, $type === 'bool' && !$v];
        } ?>
    <div class="lic-foot"><b style="color:var(--g7-text)">Límites de tu plan</b> · lo que no está incluido se ve, pero no se puede usar</div>
    <div class="lic-grid" style="grid-template-columns:repeat(2,minmax(0,1fr))">
      <?php foreach ($byMod as $mod => $rows) { ?>
        <div><div class="lic-use-top" style="margin-bottom:6px"><b style="font-size:13px"><?= $h($mods[$mod]['label'] ?? $mod) ?></b></div>
          <?php foreach ($rows as [$label, $txt, $no]) { ?><div class="lic-use-top" style="margin:0 0 4px"><span><?= $h($label) ?></span><span class="lic-mod<?= $no ? ' off' : '' ?>" style="padding:2px 9px"><?= $h($txt) ?></span></div><?php } ?>
        </div>
      <?php } ?>
    </div>
    <?php } ?>
    <div class="lic-foot"><?= !empty($L['renew']) ? $h($L['renew']) : 'Para renovar o cambiar de plan, escríbenos a info@cronostoolz.com.' ?>
      <span style="float:right;color:var(--g7-text-4)">Espacio medido <?= $U['at'] ? date('d/m H:i', (int)$U['at']) : '—' ?></span></div>
  </div>
</section>
