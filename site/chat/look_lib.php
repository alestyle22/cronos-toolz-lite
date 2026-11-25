<?php
/** chat/look_lib.php — Aspecto y sonido del chat de cada persona (look.js). Pref 'chat_look'; imágenes en _private/chat/looks/. ASPECTO_CHAT.md */
const CH_LOOKS = CH_FILES . '/looks';

function ch_look_clean(int $uid, $v): array {
    $v = is_array($v) ? $v : [];
    $hex = fn($x) => is_string($x) && preg_match('/^#[0-9a-fA-F]{6}$/', $x) ? strtolower($x) : '';
    $bg = fn($x) => is_string($x) && (preg_match('/^[a-z]{1,20}$/', $x) || (strncmp($x, 'img:', 4) === 0 && ch_look_img_path($uid, substr($x, 4)))) ? $x : '';
    $conv = [];
    foreach (array_slice(is_array($v['conv'] ?? null) ? $v['conv'] : [], -300, null, true) as $id => $c) {
        $o = array_filter(['bg' => $bg($c['bg'] ?? ''), 'snd' => is_string($c['snd'] ?? null) && preg_match('/^[a-z0-9_]{1,20}$/', $c['snd']) ? $c['snd'] : ''], fn($x) => $x !== '');
        if ((int)$id > 0 && $o) $conv[(int)$id] = $o;
    }
    return ['bg' => $bg($v['bg'] ?? ''), 'mine' => $hex($v['mine'] ?? ''), 'theirs' => $hex($v['theirs'] ?? ''), 'dim' => max(0, min(80, (int)($v['dim'] ?? 25))), 'conv' => (object)$conv];
}
function ch_look_get(PDO $db, int $uid): array {
    $q = $db->prepare("SELECT pref_value FROM g7_user_prefs WHERE user_id = ? AND pref_key = 'chat_look'"); $q->execute([$uid]);
    return ch_look_clean($uid, json_decode((string)$q->fetchColumn(), true));
}
function ch_look_save(PDO $db, int $uid, $v): array {
    $l = ch_look_clean($uid, $v); $j = json_encode($l);
    $db->prepare("INSERT INTO g7_user_prefs (user_id, pref_key, pref_value) VALUES (?, 'chat_look', ?) ON DUPLICATE KEY UPDATE pref_value = VALUES(pref_value)")->execute([$uid, $j]);
    // Las imágenes que ya no usa se borran
    foreach (glob(CH_LOOKS . "/{$uid}_*.jpg") ?: [] as $f) if (strpos($j, basename($f)) === false) @unlink($f);
    return $l;
}
/** Ruta de una imagen propia, o null (de otra persona, con otro nombre o que no está). */
function ch_look_img_path(int $uid, string $f): ?string {
    if (!preg_match('/^(\d+)_[a-f0-9]{16}\.jpg$/', $f, $m) || (int)$m[1] !== $uid) return null;
    return is_file(CH_LOOKS . '/' . $f) ? CH_LOOKS . '/' . $f : null;
}
function ch_look_img_store(int $uid, ?array $up): string {
    if ($why = g7_upload_problem($up)) throw new RuntimeException($why);
    if (!$up || $up['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('No llegó la imagen');
    $i = @getimagesize($up['tmp_name']);
    if (!$i || $i[2] !== IMAGETYPE_JPEG || $i[0] > 4000 || $i[1] > 4000 || $up['size'] > 2 * 1048576) throw new RuntimeException('Tiene que ser una imagen de hasta 2 MB');
    if (!is_dir(CH_LOOKS) && !@mkdir(CH_LOOKS, 0755, true)) throw new RuntimeException('No se pudo guardar la imagen');
    if (count(glob(CH_LOOKS . "/{$uid}_*.jpg") ?: []) >= 40) throw new RuntimeException('Tenés demasiadas imágenes: quitá alguna');
    $n = $uid . '_' . bin2hex(random_bytes(8)) . '.jpg';
    if (!move_uploaded_file($up['tmp_name'], CH_LOOKS . '/' . $n)) throw new RuntimeException('No se pudo guardar la imagen');
    return $n;
}
function ch_look_img_out(int $uid, string $f): void {
    $p = ch_look_img_path($uid, $f);
    if (!$p) { http_response_code(404); exit; }
    header('Content-Type: image/jpeg'); header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, max-age=604800'); header('Content-Length: ' . filesize($p));
    readfile($p); exit;
}
