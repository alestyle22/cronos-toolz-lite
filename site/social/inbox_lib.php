<?php
/**
 * social/inbox_lib.php — Social Studio, Stage 3: Community Inbox. Real messages and comments from the connected accounts
 * (Messenger, Instagram Direct, comments on Facebook and Instagram), nothing simulated in production.
 *  - What comes in: the signed Meta webhooks (g7_ss_webhook → ss_inbox_process) and, so nothing is lost, a sync of the DM
 *    threads from the Graph API (ss_inbox_sync). Every message is stored once (Meta's id, unique per conversation).
 *  - Replying: DM inside Meta's 24-hour window (outside it, only with the "human agent" tag if Meta approved it: up to 7 days);
 *    comments without a window. Image attachments in DMs. Notes are internal: they never go out to the network.
 *  - Team: owner, department, priority, own statuses, tags, history and filters. Quick replies with variables.
 *    AI suggestion that only fills the box (it never sends on its own). "Create ticket" from the conversation.
 * Permissions: inbox (see), reply (respond), assign (assign to others). Isolation: by brand of the account, like the rest of Social Studio.
 * Doc: toolz/SOCIAL_STUDIO.md (Stage 3)
 */
require_once __DIR__ . '/meta_lib.php';

const SS_INBOX_STATUS_DEFAULT = [['open', 'Abierta', '#3b82f6', 'open'], ['waiting', 'Esperando al cliente', '#f59e0b', 'open'], ['resolved', 'Resuelta', '#22c55e', 'closed']];
const SS_PRIORITIES = ['low' => ['Baja', '#94a3b8'], 'normal' => ['Normal', '#3b82f6'], 'high' => ['Alta', '#f59e0b'], 'urgent' => ['Urgente', '#ef4444']];
const SS_DM_WINDOW = 86400;          // standard Meta messaging window: 24 h since the last message from the person
const SS_HUMAN_AGENT_WINDOW = 604800; // with the human agent tag (needs Meta approval): 7 days

// ═══════════════════════════════════════════════════════════
// STATUSES (configurable) AND ACCESS
// ═══════════════════════════════════════════════════════════
/** Inbox statuses: [{id, name, color, type open|closed}]. The agency can rename them and add its own ones. */
function ss_inbox_statuses(): array {
    $c = g7_cfg_json('ss_inbox_status', []);
    $out = [];
    foreach ((is_array($c) && $c ? $c : array_map(fn($x) => ['id' => $x[0], 'name' => $x[1], 'color' => $x[2], 'type' => $x[3]], SS_INBOX_STATUS_DEFAULT)) as $s)
        if (!empty($s['id'])) $out[(string)$s['id']] = ['id' => (string)$s['id'], 'name' => (string)$s['name'], 'color' => (string)($s['color'] ?? '#94a3b8'), 'type' => ($s['type'] ?? 'open') === 'closed' ? 'closed' : 'open'];
    return $out;
}
function ss_inbox_statuses_save(PDO $db, array $ctx, array $list): array {
    ss_need($ctx, 'config');
    $out = []; $n = 0;
    foreach (array_slice($list, 0, 12) as $s) {
        if (!is_array($s)) continue;
        $name = mb_substr(trim((string)($s['name'] ?? '')), 0, 30); if ($name === '') ss_fail('Todos los estados necesitan un nombre.');
        $id = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($s['id'] ?? ''))) ?: 's' . (++$n) . substr(md5($name), 0, 4);
        $out[$id] = ['id' => $id, 'name' => $name, 'color' => preg_match('/^#[0-9a-f]{6}$/i', (string)($s['color'] ?? '')) ? strtolower($s['color']) : '#94a3b8', 'type' => ($s['type'] ?? '') === 'closed' ? 'closed' : 'open'];
    }
    if (!array_filter($out, fn($x) => $x['type'] === 'open') || !array_filter($out, fn($x) => $x['type'] === 'closed')) ss_fail('Tiene que haber al menos un estado abierto y uno de cierre (como "Resuelta").');
    $used = $db->query('SELECT DISTINCT status FROM g7_ss_conv')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($used as $u) if (!isset($out[$u])) ss_fail('El estado "' . (ss_inbox_statuses()[$u]['name'] ?? $u) . '" tiene conversaciones: cambiales el estado antes de sacarlo.');
    g7_cfg_set('ss_inbox_status', json_encode(array_values($out), JSON_UNESCAPED_UNICODE));
    g7_audit('social.inbox_statuses', '', ['n' => count($out)]);
    return ss_inbox_statuses();
}
function ss_inbox_first_open(): string { foreach (ss_inbox_statuses() as $s) if ($s['type'] === 'open') return $s['id']; return 'open'; }

/** Visible conversations (alias c, joined with the account a): brand of the account in the person's scope. */
function ss_conv_scope_sql(array $ctx): string { return ss_acc_scope_sql($ctx); }
function ss_conv_row(PDO $db, array $ctx, int $id): array {
    ss_need($ctx, 'inbox');
    $q = $db->prepare('SELECT c.*, a.name AS acc_name, a.username AS acc_username, a.kind AS acc_kind, a.brand_id, a.client_id, a.page_id, a.ext_id AS acc_ext
                       FROM g7_ss_conv c JOIN g7_ss_accounts a ON a.id = c.account_id WHERE c.id = ? AND ' . ss_conv_scope_sql($ctx));
    $q->execute([$id]);
    $c = $q->fetch(PDO::FETCH_ASSOC);
    if (!$c) ss_fail('Esa conversación no existe o es de una marca que no ves.', 404);
    return $c;
}

// ═══════════════════════════════════════════════════════════
// INCOMING: webhooks and sync
// ═══════════════════════════════════════════════════════════
/** Finds or creates the conversation. $c: account row. Returns the id. */
function ss_conv_upsert(PDO $db, array $acc, string $kind, string $thread, array $data): int {
    $q = $db->prepare('SELECT id FROM g7_ss_conv WHERE account_id = ? AND kind = ? AND thread = ?'); $q->execute([(int)$acc['id'], $kind, $thread]);
    if ($id = (int)$q->fetchColumn()) {
        $set = []; $a = [];
        foreach (['contact_name', 'contact_username', 'contact_pic', 'post_link', 'post_text'] as $k) if (!empty($data[$k])) { $set[] = "$k = IF($k = '', ?, $k)"; $a[] = mb_substr((string)$data[$k], 0, $k === 'post_text' ? 300 : 1000); }
        if ($set) $db->prepare('UPDATE g7_ss_conv SET ' . implode(', ', $set) . ' WHERE id = ?')->execute([...$a, $id]);
        return $id;
    }
    $now = ss_now();
    $db->prepare('INSERT INTO g7_ss_conv (account_id, kind, thread, contact_id, contact_name, contact_username, contact_pic, post_id, post_link, post_text, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
       ->execute([(int)$acc['id'], $kind, mb_substr($thread, 0, 120), mb_substr((string)($data['contact_id'] ?? ''), 0, 64), mb_substr((string)($data['contact_name'] ?? ''), 0, 190),
                  mb_substr((string)($data['contact_username'] ?? ''), 0, 120), mb_substr((string)($data['contact_pic'] ?? ''), 0, 1000), mb_substr((string)($data['post_id'] ?? ''), 0, 80),
                  mb_substr((string)($data['post_link'] ?? ''), 0, 1000), mb_substr((string)($data['post_text'] ?? ''), 0, 300), ss_inbox_first_open(), $now, $now]);
    return (int)$db->lastInsertId();
}
/**
 * Stores a message (if it was already there, it does nothing: Meta resends and the sync brings repeats). $dir in|out.
 * Incoming: the conversation goes back to "open" if it was closed, counts as unread and renews the window. Returns whether it was new.
 */
function ss_msg_store(PDO $db, int $convId, string $dir, ?string $extId, string $body, array $att, string $author, string $at, ?int $userId = null): bool {
    if ($extId) {
        $q = $db->prepare('SELECT 1 FROM g7_ss_msgs WHERE conv_id = ? AND ext_id = ?'); $q->execute([$convId, $extId]);
        if ($q->fetchColumn()) return false;
    }
    $db->prepare('INSERT INTO g7_ss_msgs (conv_id, dir, ext_id, body, attachments, author, user_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
       ->execute([$convId, $dir, $extId ? mb_substr($extId, 0, 190) : null, mb_substr($body, 0, 20000), $att ? json_encode($att, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null, mb_substr($author, 0, 190), $userId, $at]);
    $snip = mb_substr(trim($body) !== '' ? $body : ($att ? '[' . ($att[0]['type'] ?? 'adjunto') . ']' : ''), 0, 300);
    if ($dir === 'in') {
        $st = ss_inbox_statuses(); $c = $db->query('SELECT status, assignee_id, team_id FROM g7_ss_conv WHERE id = ' . $convId)->fetch(PDO::FETCH_ASSOC);
        $reopen = isset($st[$c['status']]) && $st[$c['status']]['type'] === 'closed';
        $db->prepare('UPDATE g7_ss_conv SET unread = unread + 1, last_in_at = GREATEST(COALESCE(last_in_at, ?), ?), last_msg_at = GREATEST(COALESCE(last_msg_at, ?), ?), last_text = ?, status = ?, updated_at = ? WHERE id = ?')
           ->execute([$at, $at, $at, $at, $snip, $reopen ? ss_inbox_first_open() : $c['status'], ss_now(), $convId]);
        if ($reopen) ss_conv_event($db, $convId, null, 'Se reabrió: llegó un mensaje nuevo');
        if ($c['assignee_id']) ss_notify_inbox($db, [(int)$c['assignee_id']], $convId, null, 'Mensaje nuevo: ' . mb_substr($snip, 0, 120));
    } else {
        $db->prepare('UPDATE g7_ss_conv SET last_msg_at = GREATEST(COALESCE(last_msg_at, ?), ?), last_text = ?, updated_at = ? WHERE id = ?')->execute([$at, $at, $snip, ss_now(), $convId]);
    }
    return true;
}
function ss_conv_event(PDO $db, int $convId, ?int $uid, string $text): void {
    $db->prepare("INSERT INTO g7_ss_msgs (conv_id, dir, body, user_id, created_at) VALUES (?, 'event', ?, ?, ?)")->execute([$convId, mb_substr($text, 0, 500), $uid, ss_now()]);
}
/** Inbox notifications (type social_inbox; target = conversation). */
function ss_notify_inbox(PDO $db, array $users, int $convId, ?int $from, string $body): void {
    $users = array_values(array_unique(array_filter(array_map('intval', $users), fn($u) => $u > 0 && $u !== (int)$from)));
    $ins = $db->prepare("INSERT INTO g7_notifications (user_id, from_id, type, target_id, profile_id, body) VALUES (?, ?, 'social_inbox', ?, NULL, ?)");
    foreach ($users as $u) try { $ins->execute([$u, $from ?: null, $convId, mb_substr($body, 0, 280)]); } catch (Throwable $e) {}
}
function ss_meta_time($t): string { return is_numeric($t) ? date('Y-m-d H:i:s', (int)((int)$t > 20000000000 ? (int)$t / 1000 : (int)$t)) : (($x = strtotime((string)$t)) ? date('Y-m-d H:i:s', $x) : ss_now()); }
function ss_meta_atts($list): array {
    $out = [];
    foreach ((array)$list as $a) {
        if (!is_array($a)) continue;
        $url = (string)($a['payload']['url'] ?? $a['image_data']['url'] ?? $a['video_data']['url'] ?? $a['file_url'] ?? '');
        $out[] = ['type' => (string)($a['type'] ?? ($a['mime_type'] ?? 'file')), 'url' => preg_match('#^https://#', $url) ? $url : ''];
    }
    return $out;
}
function ss_account_by_ext(PDO $db, string $kind, string $ext): ?array {
    $q = $db->prepare("SELECT * FROM g7_ss_accounts WHERE provider = 'meta' AND kind = ? AND ext_id = ? AND token_enc IS NOT NULL AND token_enc <> ''"); $q->execute([$kind, $ext]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}
/** Contact's name and photo (once per conversation; if Meta does not give it, it stays with the id). */
function ss_contact_fill(PDO $db, array $acc, int $convId, string $who): void {
    $c = $db->query('SELECT contact_name FROM g7_ss_conv WHERE id = ' . $convId)->fetch(PDO::FETCH_ASSOC);
    if (!$c || $c['contact_name'] !== '') return;
    try {
        $f = $acc['kind'] === 'ig_business' ? 'name,username,profile_pic' : 'name,profile_pic';
        $r = ss_meta_call($db, 'GET', $who, ['fields' => $f, 'access_token' => ss_account_token($acc)], (int)$acc['id'], 'inbox_profile');
        $db->prepare('UPDATE g7_ss_conv SET contact_name = ?, contact_username = ?, contact_pic = ? WHERE id = ?')
           ->execute([mb_substr((string)($r['name'] ?? $r['username'] ?? ''), 0, 190), mb_substr((string)($r['username'] ?? ''), 0, 120), mb_substr((string)($r['profile_pic'] ?? ''), 0, 1000), $convId]);
    } catch (SsError $e) {}
}

/** Processes the webhooks that arrived and were not processed yet. Returns how many messages were new. */
function ss_inbox_process(PDO $db, int $limit = 200): int {
    if (!ss_plan_allows('social_inbox')) { $db->exec("UPDATE g7_ss_webhook SET processed_at = NOW(), note = 'plan sin Inbox' WHERE processed_at IS NULL"); return 0; }
    $rows = $db->query('SELECT id, payload FROM g7_ss_webhook WHERE processed_at IS NULL ORDER BY id LIMIT ' . (int)$limit)->fetchAll(PDO::FETCH_ASSOC);
    $n = 0;
    foreach ($rows as $w) {
        $note = '';
        try { $n += ss_inbox_handle($db, json_decode($w['payload'], true) ?: [], $note); }
        catch (Throwable $e) { $note = 'error: ' . mb_substr($e->getMessage(), 0, 150); error_log('[social inbox] ' . $e->getMessage()); }
        $db->prepare('UPDATE g7_ss_webhook SET processed_at = ?, note = ? WHERE id = ?')->execute([ss_now(), mb_substr($note, 0, 200), (int)$w['id']]);
    }
    return $n;
}
function ss_inbox_handle(PDO $db, array $p, string &$note): int {
    $obj = (string)($p['object'] ?? ''); $n = 0;
    if ($obj !== 'page' && $obj !== 'instagram') { $note = 'objeto ignorado'; return 0; }
    foreach ((array)($p['entry'] ?? []) as $e) {
        $acc = ss_account_by_ext($db, $obj === 'page' ? 'fb_page' : 'ig_business', (string)($e['id'] ?? ''));
        if (!$acc) { $note = 'cuenta no conectada'; continue; }
        $me = $acc['ext_id'];
        // Direct messages (Messenger / Instagram Direct)
        foreach ((array)($e['messaging'] ?? []) as $m) {
            if (empty($m['message']) || !empty($m['message']['is_deleted'])) continue;
            $echo = !empty($m['message']['is_echo']) || (string)($m['sender']['id'] ?? '') === $me;
            $other = $echo ? (string)($m['recipient']['id'] ?? '') : (string)($m['sender']['id'] ?? '');
            if ($other === '') continue;
            $cid = ss_conv_upsert($db, $acc, 'dm', $other, ['contact_id' => $other]);
            $new = ss_msg_store($db, $cid, $echo ? 'out' : 'in', (string)($m['message']['mid'] ?? ''), (string)($m['message']['text'] ?? ''), ss_meta_atts($m['message']['attachments'] ?? []),
                                $echo ? ($acc['username'] ?: $acc['name']) : '', ss_meta_time($m['timestamp'] ?? null));
            if ($new) { $n++; if (!$echo) ss_contact_fill($db, $acc, $cid, $other); }
        }
        // Comments
        foreach ((array)($e['changes'] ?? []) as $ch) {
            $v = (array)($ch['value'] ?? []); $field = (string)($ch['field'] ?? '');
            if ($obj === 'page' && $field === 'feed' && ($v['item'] ?? '') === 'comment' && in_array($v['verb'] ?? 'add', ['add', 'edited'], true)) {
                $cmt = (string)($v['comment_id'] ?? ''); $post = (string)($v['post_id'] ?? ''); $parent = (string)($v['parent_id'] ?? $post);
                $from = (array)($v['from'] ?? []); $mine = (string)($from['id'] ?? '') === $me;
                $thread = $parent === $post || $parent === '' ? $cmt : $parent;
                $cid = ss_conv_upsert($db, $acc, 'comment', $thread, ['contact_id' => $mine ? '' : (string)($from['id'] ?? ''), 'contact_name' => $mine ? '' : (string)($from['name'] ?? ''), 'post_id' => $post,
                                       'post_link' => (string)($v['post']['permalink_url'] ?? ''), 'post_text' => (string)($v['post']['message'] ?? '')]);
                if (ss_msg_store($db, $cid, $mine ? 'out' : 'in', $cmt, (string)($v['message'] ?? ''), !empty($v['photo']) ? [['type' => 'image', 'url' => (string)$v['photo']]] : [],
                                 (string)($from['name'] ?? ''), ss_meta_time($v['created_time'] ?? null))) $n++;
            } elseif ($obj === 'instagram' && in_array($field, ['comments', 'live_comments'], true)) {
                $cmt = (string)($v['id'] ?? ''); $parent = (string)($v['parent_id'] ?? ''); $from = (array)($v['from'] ?? []);
                $mine = (string)($from['id'] ?? '') === $me || (string)($from['username'] ?? '') === $acc['username'];
                $cid = ss_conv_upsert($db, $acc, 'comment', $parent !== '' ? $parent : $cmt, ['contact_id' => $mine ? '' : (string)($from['id'] ?? ''), 'contact_username' => $mine ? '' : (string)($from['username'] ?? ''),
                                       'contact_name' => $mine ? '' : '@' . (string)($from['username'] ?? ''), 'post_id' => (string)($v['media']['id'] ?? '')]);
                if (ss_msg_store($db, $cid, $mine ? 'out' : 'in', $cmt, (string)($v['text'] ?? ''), [], $mine ? '' : '@' . (string)($from['username'] ?? ''), ss_now())) $n++;
            }
        }
    }
    $note = $note ?: "$n nuevos";
    return $n;
}

/**
 * Sync of DM threads from the Graph API (what was lost while there were no webhooks, or before connecting).
 * Messenger and Instagram, the last 25 threads and 20 messages of each one, per account. Returns how many were new.
 */
function ss_inbox_sync(PDO $db, array $acc): int {
    $tok = ss_account_token($acc);
    if ($tok === '') return 0;
    if ($acc['provider'] !== 'meta') {   // Stage 6: comments (LinkedIn, YouTube), mentions (X); TikTok has no Inbox API
        if (!ss_net_cfg($acc['provider'])['inbox'] || !ss_net_has($acc['provider'], 'inbox_sync')) return 0;
        $n = ss_net_fn($acc['provider'], 'inbox_sync', $db, $acc);
        $db->prepare('UPDATE g7_ss_accounts SET last_sync_at = ? WHERE id = ?')->execute([ss_now(), (int)$acc['id']]);
        return $n;
    }
    $platform = $acc['kind'] === 'ig_business' ? 'instagram' : 'messenger';
    $me = $acc['kind'] === 'ig_business' ? $acc['ext_id'] : $acc['page_id'];
    $r = ss_meta_call($db, 'GET', $acc['page_id'] . '/conversations', ['platform' => $platform, 'limit' => 25,
        'fields' => 'id,updated_time,participants,messages.limit(20){id,message,from,to,created_time,attachments}', 'access_token' => $tok], (int)$acc['id'], 'inbox_sync');
    $n = 0;
    foreach ((array)($r['data'] ?? []) as $th) {
        $other = null;
        foreach ((array)($th['participants']['data'] ?? []) as $pp) if ((string)($pp['id'] ?? '') !== $me && (string)($pp['id'] ?? '') !== $acc['page_id']) { $other = $pp; break; }
        if (!$other) continue;
        $cid = ss_conv_upsert($db, $acc, 'dm', (string)$other['id'], ['contact_id' => (string)$other['id'], 'contact_name' => (string)($other['name'] ?? $other['username'] ?? ''), 'contact_username' => (string)($other['username'] ?? '')]);
        $msgs = array_reverse((array)($th['messages']['data'] ?? []));   // Meta sends newest first
        foreach ($msgs as $m) {
            $out = (string)($m['from']['id'] ?? '') === $me || (string)($m['from']['id'] ?? '') === $acc['page_id'];
            if (ss_msg_store($db, $cid, $out ? 'out' : 'in', (string)($m['id'] ?? ''), (string)($m['message'] ?? ''), ss_meta_atts($m['attachments']['data'] ?? []),
                             (string)($m['from']['name'] ?? $m['from']['username'] ?? ''), ss_meta_time($m['created_time'] ?? null))) $n++;
        }
    }
    $db->prepare('UPDATE g7_ss_accounts SET last_sync_at = ? WHERE id = ?')->execute([ss_now(), (int)$acc['id']]);
    return $n;
}
/** Synchronizes the accounts the person sees (button "Sync") or all of them (cron). */
function ss_inbox_sync_all(PDO $db, ?array $ctx = null): array {
    if (!ss_plan_allows('social_inbox')) return ['new' => 0, 'errors' => []];   // the plan does not include the Inbox
    $w = "a.token_enc IS NOT NULL AND a.token_enc <> '' AND a.status = 'ok'" . ($ctx ? ' AND ' . ss_acc_scope_sql($ctx) : '');
    $n = 0; $errs = [];
    foreach ($db->query("SELECT a.* FROM g7_ss_accounts a WHERE $w")->fetchAll(PDO::FETCH_ASSOC) as $a) {
        if (!ss_plan_net_ok(ss_kind_network($a['kind']))) continue;
        try { $n += ss_inbox_sync($db, $a); } catch (SsError $e) { $errs[] = ($a['username'] ? '@' . $a['username'] : $a['name']) . ': ' . $e->getMessage(); }
    }
    return ['new' => $n, 'errors' => $errs];
}

// ═══════════════════════════════════════════════════════════
// LIST AND DETAIL
// ═══════════════════════════════════════════════════════════
function ss_conv_out(array $c): array {
    $win = ss_conv_window($c);
    return ['id' => (int)$c['id'], 'account_id' => (int)$c['account_id'], 'account' => $c['acc_username'] ? '@' . $c['acc_username'] : (string)$c['acc_name'], 'network' => ss_kind_network((string)$c['acc_kind']),
            'kind' => $c['kind'], 'contact' => $c['contact_name'] ?: ($c['contact_username'] ? '@' . $c['contact_username'] : 'Persona ' . substr((string)$c['contact_id'], -4)), 'contact_username' => $c['contact_username'],
            'contact_pic' => $c['contact_pic'], 'post_link' => $c['post_link'], 'post_text' => $c['post_text'], 'status' => $c['status'], 'priority' => $c['priority'],
            'assignee_id' => $c['assignee_id'] ? (int)$c['assignee_id'] : null, 'team_id' => $c['team_id'] ? (int)$c['team_id'] : null, 'tags' => ss_split((string)$c['tags']),
            'unread' => (int)$c['unread'], 'last_msg_at' => $c['last_msg_at'], 'last_in_at' => $c['last_in_at'], 'last_text' => $c['last_text'], 'brand_id' => $c['brand_id'] ? (int)$c['brand_id'] : null,
            'window' => $win];
}
/** Reply window: {ok, mode standard|human_agent|none|comment, until}. */
function ss_conv_window(array $c): array {
    if ($c['kind'] === 'comment') return ['ok' => true, 'mode' => 'comment', 'until' => null];
    if ((ss_kinds()[$c['acc_kind'] ?? ''][0] ?? 'meta') !== 'meta') return ['ok' => true, 'mode' => 'standard', 'until' => null];   // X: no reply window
    $last = $c['last_in_at'] ? strtotime($c['last_in_at']) : 0;
    if ($last && time() - $last < SS_DM_WINDOW) return ['ok' => true, 'mode' => 'standard', 'until' => date('Y-m-d H:i:s', $last + SS_DM_WINDOW)];
    if ($last && ss_meta_cfg()['human_agent'] && time() - $last < SS_HUMAN_AGENT_WINDOW) return ['ok' => true, 'mode' => 'human_agent', 'until' => date('Y-m-d H:i:s', $last + SS_HUMAN_AGENT_WINDOW)];
    return ['ok' => false, 'mode' => 'none', 'until' => $last ? date('Y-m-d H:i:s', $last + SS_DM_WINDOW) : null];
}
/** $f: box (mine|unassigned|team|all), status (id|open|closed|all), network, account, kind, priority, tag, team, brand, q. */
function ss_inbox_list(PDO $db, array $ctx, array $f): array {
    ss_need($ctx, 'inbox');
    $w = [ss_conv_scope_sql($ctx)]; $a = [];
    $box = (string)($f['box'] ?? 'all');
    if ($box === 'mine') $w[] = 'c.assignee_id = ' . (int)$ctx['id'];
    elseif ($box === 'unassigned') $w[] = 'c.assignee_id IS NULL AND c.team_id IS NULL';
    elseif ($box === 'team') { $teams = array_map('intval', g7_ctx($ctx['id'])['teams']); $w[] = $teams ? 'c.team_id IN (' . implode(',', $teams) . ')' : '0'; }
    $st = ss_inbox_statuses(); $sf = (string)($f['status'] ?? 'open');
    if ($sf === 'open' || $sf === 'closed') { $ids = array_keys(array_filter($st, fn($s) => $s['type'] === $sf)); $w[] = $ids ? 'c.status IN (' . implode(',', array_map(fn($x) => $db->quote($x), $ids)) . ')' : '0'; }
    elseif ($sf !== 'all' && isset($st[$sf])) { $w[] = 'c.status = ?'; $a[] = $sf; }
    if (in_array($f['network'] ?? '', ss_connectable_networks(), true)) $w[] = ss_network_kind_sql($f['network']);
    if (in_array($f['kind'] ?? '', ['dm', 'comment'], true)) { $w[] = 'c.kind = ?'; $a[] = $f['kind']; }
    if (!empty($f['account'])) { $w[] = 'c.account_id = ?'; $a[] = (int)$f['account']; }
    if (isset(SS_PRIORITIES[$f['priority'] ?? ''])) { $w[] = 'c.priority = ?'; $a[] = $f['priority']; }
    if (!empty($f['team'])) { $w[] = 'c.team_id = ?'; $a[] = (int)$f['team']; }
    if (!empty($f['brand'])) { $w[] = 'a.brand_id = ?'; $a[] = (int)$f['brand']; }
    if (($tag = trim((string)($f['tag'] ?? ''))) !== '') { $w[] = 'FIND_IN_SET(?, c.tags)'; $a[] = $tag; }
    if (($q = trim((string)($f['q'] ?? ''))) !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($q, 0, 80)) . '%';
        $w[] = '(c.contact_name LIKE ? OR c.contact_username LIKE ? OR c.last_text LIKE ? OR EXISTS (SELECT 1 FROM g7_ss_msgs m WHERE m.conv_id = c.id AND m.body LIKE ?))';
        array_push($a, $like, $like, $like, $like);
    }
    $sql = 'SELECT c.*, a.name AS acc_name, a.username AS acc_username, a.kind AS acc_kind, a.brand_id FROM g7_ss_conv c JOIN g7_ss_accounts a ON a.id = c.account_id WHERE '
         . implode(' AND ', $w) . ' ORDER BY FIELD(c.priority, \'urgent\', \'high\') DESC, c.last_msg_at DESC LIMIT 300';
    $s = $db->prepare($sql); $s->execute($a);
    $items = array_map('ss_conv_out', $s->fetchAll(PDO::FETCH_ASSOC));
    // Counters for the side boxes (unfiltered by box, only open ones)
    $openIds = array_keys(array_filter($st, fn($x) => $x['type'] === 'open'));
    $ow = 'c.status IN (' . ($openIds ? implode(',', array_map(fn($x) => $db->quote($x), $openIds)) : "''") . ') AND ' . ss_conv_scope_sql($ctx);
    $cnt = $db->query("SELECT SUM(c.assignee_id = {$ctx['id']}) mine, SUM(c.assignee_id IS NULL AND c.team_id IS NULL) unassigned, COUNT(*) total, SUM(c.unread > 0) unread FROM g7_ss_conv c JOIN g7_ss_accounts a ON a.id = c.account_id WHERE $ow")->fetch(PDO::FETCH_ASSOC);
    return ['items' => $items, 'counts' => array_map('intval', $cnt ?: [])];
}
function ss_conv_detail(PDO $db, array $ctx, int $id, bool $markRead = true): array {
    $c = ss_conv_row($db, $ctx, $id);
    if ($markRead && (int)$c['unread']) { $db->prepare('UPDATE g7_ss_conv SET unread = 0 WHERE id = ?')->execute([$id]); $c['unread'] = 0; }
    $q = $db->prepare("SELECT m.*, COALESCE(NULLIF(u.display_name,''), u.username) AS who FROM g7_ss_msgs m LEFT JOIN g7_users u ON u.id = m.user_id WHERE m.conv_id = ? ORDER BY m.created_at, m.id LIMIT 500");
    $q->execute([$id]);
    $msgs = array_map(fn($m) => ['id' => (int)$m['id'], 'dir' => $m['dir'], 'body' => (string)$m['body'], 'att' => $m['attachments'] ? (json_decode($m['attachments'], true) ?: []) : [],
        'author' => $m['dir'] === 'in' ? (string)$m['author'] : (string)($m['who'] ?: $m['author']), 'state' => $m['state'], 'error' => $m['error'], 'at' => $m['created_at']], $q->fetchAll(PDO::FETCH_ASSOC));
    $tk = $db->prepare('SELECT l.ticket_id, t.subject, t.status FROM g7_ss_conv_tickets l LEFT JOIN g7_wk_tickets t ON t.id = l.ticket_id WHERE l.conv_id = ?');
    try { $tk->execute([$id]); $tickets = array_map(fn($r) => ['id' => (int)$r['ticket_id'], 'subject' => (string)$r['subject'], 'status' => (string)$r['status'], 'url' => '/toolz/trabajo/#tickets/' . (int)$r['ticket_id']], $tk->fetchAll(PDO::FETCH_ASSOC)); }
    catch (Throwable $e) { $tickets = []; }
    $o = ss_conv_out($c);
    return ['conv' => $o, 'msgs' => $msgs, 'tickets' => $tickets];
}

// ═══════════════════════════════════════════════════════════
// TEAM: assign, status, priority, tags, notes
// ═══════════════════════════════════════════════════════════
function ss_conv_update(PDO $db, array $ctx, int $id, array $in): void {
    $c = ss_conv_row($db, $ctx, $id);
    $set = []; $a = []; $ev = [];
    if (array_key_exists('assignee_id', $in)) {
        $to = (int)$in['assignee_id'] ?: null;
        if ($to !== ($c['assignee_id'] ? (int)$c['assignee_id'] : null)) {
            if ($to && $to !== $ctx['id']) ss_need($ctx, 'assign');
            if ($to && (!ss_user_active($db, $to) || !g7_can($to, 'social', 'inbox'))) ss_fail('Esa persona no tiene acceso al Inbox.');
            $set[] = 'assignee_id = ?'; $a[] = $to;
            $ev[] = $to ? 'asignó a ' . ss_user_name($db, $to) : 'dejó la conversación sin responsable';
            if ($to) ss_notify_inbox($db, [$to], $id, $ctx['id'], 'Te asignaron una conversación con ' . ($c['contact_name'] ?: 'un contacto'));
        }
    }
    if (array_key_exists('team_id', $in)) {
        $tm = (int)$in['team_id'] ?: null;
        if ($tm !== ($c['team_id'] ? (int)$c['team_id'] : null)) {
            ss_need($ctx, 'assign');
            $name = '';
            if ($tm) { $q = $db->prepare('SELECT name FROM g7_teams WHERE id = ? AND active = 1'); $q->execute([$tm]); $name = (string)$q->fetchColumn(); if ($name === '') ss_fail('Ese departamento no existe.'); }
            $set[] = 'team_id = ?'; $a[] = $tm; $ev[] = $tm ? 'pasó al departamento ' . $name : 'sacó el departamento';
        }
    }
    if (isset($in['status']) && $in['status'] !== $c['status']) {
        $st = ss_inbox_statuses();
        if (!isset($st[$in['status']])) ss_fail('Ese estado no existe.');
        $set[] = 'status = ?'; $a[] = $in['status']; $ev[] = 'cambió el estado a "' . $st[$in['status']]['name'] . '"';
    }
    if (isset($in['priority']) && $in['priority'] !== $c['priority']) {
        if (!isset(SS_PRIORITIES[$in['priority']])) ss_fail('Esa prioridad no existe.');
        $set[] = 'priority = ?'; $a[] = $in['priority']; $ev[] = 'puso prioridad ' . mb_strtolower(SS_PRIORITIES[$in['priority']][0]);
    }
    if (isset($in['tags']) && is_array($in['tags'])) {
        $tags = []; foreach (array_slice($in['tags'], 0, 12) as $t) { $t = mb_substr(trim(str_replace(',', ' ', (string)$t)), 0, 30); if ($t !== '' && !isset($tags[mb_strtolower($t)])) $tags[mb_strtolower($t)] = $t; }
        $tags = implode(',', $tags);
        if ($tags !== $c['tags']) { $set[] = 'tags = ?'; $a[] = $tags; $ev[] = 'cambió las etiquetas'; }
    }
    if (isset($in['unread']) && $in['unread']) { $set[] = 'unread = GREATEST(unread, 1)'; }
    if (!$set) return;
    if (!ss_rate_ok($db, 'inbox:' . $ctx['id'], 300, 300)) ss_fail('Demasiados cambios seguidos: esperá un minuto.', 429);
    $set[] = 'updated_at = ?'; $a[] = ss_now();
    $db->prepare('UPDATE g7_ss_conv SET ' . implode(', ', $set) . ' WHERE id = ?')->execute([...$a, $id]);
    foreach ($ev as $e) ss_conv_event($db, $id, $ctx['id'], $e);
}
function ss_conv_note(PDO $db, array $ctx, int $id, string $body): void {
    ss_conv_row($db, $ctx, $id);
    $body = trim($body);
    if ($body === '') ss_fail('Escribí la nota.');
    // Internal note: it stays in Cronos, it never goes to the network
    $db->prepare("INSERT INTO g7_ss_msgs (conv_id, dir, body, user_id, created_at) VALUES (?, 'note', ?, ?, ?)")->execute([$id, mb_substr($body, 0, 5000), $ctx['id'], ss_now()]);
    // Mentions @user in the notes: notify
    if (preg_match_all('/@([a-z0-9_.\-]{2,40})/i', $body, $m)) {
        $q = $db->prepare('SELECT id FROM g7_users WHERE username = ? AND active = 1'); $to = [];
        foreach (array_unique($m[1]) as $u) { $q->execute([$u]); if ($x = (int)$q->fetchColumn()) $to[] = $x; }
        ss_notify_inbox($db, $to, $id, $ctx['id'], 'Te mencionó en una nota del Inbox');
    }
}

// ═══════════════════════════════════════════════════════════
// REPLY (it goes out to the network)
// ═══════════════════════════════════════════════════════════
/** Sends a reply. $fileId: image attached in the Inbox (DM only). Returns the message created. */
function ss_conv_reply(PDO $db, array $ctx, int $id, string $text, int $fileId = 0): array {
    ss_need($ctx, 'reply');
    $c = ss_conv_row($db, $ctx, $id);
    $text = trim($text);
    if ($text === '' && !$fileId) ss_fail('Escribí la respuesta.');
    if (mb_strlen($text) > ($c['kind'] === 'dm' ? 2000 : 8000)) ss_fail('La respuesta es demasiado larga para esta red.');
    if (!ss_rate_ok($db, 'reply:' . $ctx['id'], 60, 600)) ss_fail('Demasiadas respuestas seguidas: esperá unos minutos.', 429);
    $win = ss_conv_window($c);
    if (!$win['ok']) ss_fail('Pasaron más de 24 horas desde el último mensaje de esta persona: Meta no deja responder por mensaje directo. Podés esperar a que vuelva a escribir' . (ss_meta_cfg()['human_agent'] ? '.' : ' (o, si Meta aprobó la etiqueta "agente humano", activarla en Cuentas para tener 7 días).'));
    $acc = $db->query('SELECT * FROM g7_ss_accounts WHERE id = ' . (int)$c['account_id'])->fetch(PDO::FETCH_ASSOC);
    $tok = ss_account_token($acc);
    if ($tok === '') ss_fail('La cuenta se desconectó: renovala en Cuentas.');
    $file = null;
    if ($acc['provider'] !== 'meta') return ss_conv_reply_net($db, $ctx, $c, $acc, $text, $fileId);
    if ($fileId) {
        if ($c['kind'] !== 'dm') ss_fail('Los comentarios solo admiten texto.');
        $q = $db->prepare('SELECT * FROM g7_ss_files WHERE id = ? AND conv_id = ?'); $q->execute([$fileId, $id]);
        if (!($file = $q->fetch(PDO::FETCH_ASSOC))) ss_fail('Ese archivo ya no está.');
        if (!in_array($file['ext'], ['jpg', 'jpeg', 'png', 'gif'], true)) ss_fail('Por mensaje directo se manda una imagen (JPG, PNG o GIF).');
    }
    $now = ss_now();
    $db->prepare("INSERT INTO g7_ss_msgs (conv_id, dir, body, attachments, user_id, state, created_at) VALUES (?, 'out', ?, ?, ?, 'sending', ?)")
       ->execute([$id, $text, $file ? json_encode([['type' => 'image', 'url' => '/toolz/social_file.php?t=' . $file['token']]]) : null, $ctx['id'], $now]);
    $mid = (int)$db->lastInsertId();
    try {
        $ext = '';
        if ($c['kind'] === 'dm') {
            $base = ['recipient' => json_encode(['id' => $c['thread']]), 'access_token' => $tok];
            $base += $win['mode'] === 'human_agent' ? ['messaging_type' => 'MESSAGE_TAG', 'tag' => 'HUMAN_AGENT'] : ['messaging_type' => 'RESPONSE'];
            if ($file) {
                $r = ss_meta_call($db, 'POST', $acc['page_id'] . '/messages', $base + ['message' => json_encode(['attachment' => ['type' => 'image', 'payload' => ['url' => ss_media_url($file, false, 3600), 'is_reusable' => false]]])], (int)$acc['id'], 'inbox_send_file');
                $ext = (string)($r['message_id'] ?? '');
            }
            if ($text !== '') {
                $r = ss_meta_call($db, 'POST', $acc['page_id'] . '/messages', $base + ['message' => json_encode(['text' => $text])], (int)$acc['id'], 'inbox_send');
                $ext = (string)($r['message_id'] ?? $ext);
            }
        } else {
            // Facebook: reply to the comment (if it is a reply, Meta places it in the same thread); Instagram: /replies of the comment
            $r = $acc['kind'] === 'ig_business'
                ? ss_meta_call($db, 'POST', $c['thread'] . '/replies', ['message' => $text, 'access_token' => $tok], (int)$acc['id'], 'inbox_reply_ig')
                : ss_meta_call($db, 'POST', $c['thread'] . '/comments', ['message' => $text, 'access_token' => $tok], (int)$acc['id'], 'inbox_reply_fb');
            $ext = (string)($r['id'] ?? '');
        }
        $db->prepare("UPDATE g7_ss_msgs SET state = 'ok', ext_id = ? WHERE id = ?")->execute([$ext !== '' ? $ext : null, $mid]);
        $db->prepare('UPDATE g7_ss_conv SET last_msg_at = ?, last_text = ?, unread = 0, updated_at = ? WHERE id = ?')->execute([$now, mb_substr($text !== '' ? $text : '[imagen]', 0, 300), $now, $id]);
        if (!$c['assignee_id']) $db->prepare('UPDATE g7_ss_conv SET assignee_id = ? WHERE id = ? AND assignee_id IS NULL')->execute([$ctx['id'], $id]);   // whoever replies first takes it
        g7_audit('social.inbox_reply', '#' . $id, ['kind' => $c['kind'], 'mode' => $win['mode']]);
    } catch (SsError $e) {
        $db->prepare("UPDATE g7_ss_msgs SET state = 'failed', error = ? WHERE id = ?")->execute([mb_substr($e->getMessage(), 0, 400), $mid]);
        if ($e instanceof SsMetaError && in_array($e->metaCode, [10, 551, 2018108], true)) ss_fail('Meta no deja responder esta conversación (fuera de la ventana o sin el permiso de mensajería).');
        throw $e;
    }
    return ['id' => $mid];
}
/** Reply on LinkedIn, X or YouTube (text only: those APIs do not take attachments in replies from Social Studio). */
function ss_conv_reply_net(PDO $db, array $ctx, array $c, array $acc, string $text, int $fileId): array {
    $prov = $acc['provider']; $id = (int)$c['id'];
    if ($fileId) ss_fail('En ' . SS_PROVIDERS[$prov]['name'] . ' las respuestas desde Social Studio son solo texto.');
    if ($text === '') ss_fail('Escribí la respuesta.');
    if (!ss_net_cfg($prov)['inbox']) ss_fail('El Inbox de ' . SS_PROVIDERS[$prov]['name'] . ' está apagado en Cuentas.');
    $now = ss_now();
    $db->prepare("INSERT INTO g7_ss_msgs (conv_id, dir, body, user_id, state, created_at) VALUES (?, 'out', ?, ?, 'sending', ?)")->execute([$id, $text, $ctx['id'], $now]);
    $mid = (int)$db->lastInsertId();
    try {
        $ext = (string)ss_net_fn($prov, 'reply', $db, $acc, $c, $text);
        $db->prepare("UPDATE g7_ss_msgs SET state = 'ok', ext_id = ? WHERE id = ?")->execute([$ext !== '' ? mb_substr($ext, 0, 190) : null, $mid]);
        $db->prepare('UPDATE g7_ss_conv SET last_msg_at = ?, last_text = ?, unread = 0, updated_at = ? WHERE id = ?')->execute([$now, mb_substr($text, 0, 300), $now, $id]);
        if (!$c['assignee_id']) $db->prepare('UPDATE g7_ss_conv SET assignee_id = ? WHERE id = ? AND assignee_id IS NULL')->execute([$ctx['id'], $id]);
        g7_audit('social.inbox_reply', '#' . $id, ['kind' => $c['kind'], 'network' => $prov]);
    } catch (SsError $e) {
        $db->prepare("UPDATE g7_ss_msgs SET state = 'failed', error = ? WHERE id = ?")->execute([mb_substr($e->getMessage(), 0, 400), $mid]);
        throw $e;
    }
    return ['id' => $mid];
}
/** Image attached in the Inbox (it is stored like the files of the posts, linked to the conversation). */
function ss_conv_upload(PDO $db, array $ctx, int $id, $f): array {
    ss_need($ctx, 'reply');
    ss_conv_row($db, $ctx, $id);
    if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) ss_fail('Elegí una imagen.');
    if ($pr = g7_upload_problem($f)) ss_fail($pr);
    $ext = g7_upload_ext((string)$f['name'], ['jpg', 'jpeg', 'png', 'gif']);
    if ($ext === null) ss_fail('Por mensaje directo se manda una imagen (JPG, PNG o GIF).');
    if ((int)$f['size'] > 8 * 1048576) ss_fail('La imagen pesa más de 8 MB.');
    if (@getimagesize($f['tmp_name']) === false) ss_fail('Esa imagen está dañada o no es una imagen.');
    $token = bin2hex(random_bytes(16)); $dir = ss_files_dir() . 'inbox/';
    if (!is_file(ss_files_dir() . '.htaccess')) { @mkdir(ss_files_dir(), 0755, true); @file_put_contents(ss_files_dir() . '.htaccess', "Require all denied\nphp_flag engine off\n"); }
    if ($err = g7_upload_store($f, $dir, $token . '.' . $ext)) ss_fail($err, 500);
    $db->prepare('INSERT INTO g7_ss_files (post_id, conv_id, token, name, ext, size, user_id, created_at) VALUES (0, ?, ?, ?, ?, ?, ?, ?)')
       ->execute([$id, $token, mb_substr((string)$f['name'], 0, 200), $ext, (int)$f['size'], $ctx['id'], ss_now()]);
    return ['id' => (int)$db->lastInsertId(), 'name' => (string)$f['name'], 'url' => '/toolz/social_file.php?t=' . $token];
}

// ═══════════════════════════════════════════════════════════
// QUICK REPLIES, AI AND TICKET
// ═══════════════════════════════════════════════════════════
function ss_replies(PDO $db, array $ctx): array {
    ss_need($ctx, 'inbox');
    $w = $ctx['brands'] === null ? '1' : ('(brand_id IS NULL' . ($ctx['brands'] ? ' OR brand_id IN (' . implode(',', array_map('intval', $ctx['brands'])) . ')' : '') . ')');
    return array_map(fn($r) => ['id' => (int)$r['id'], 'title' => $r['title'], 'body' => $r['body'], 'category' => $r['category'], 'brand_id' => $r['brand_id'] ? (int)$r['brand_id'] : null, 'uses' => (int)$r['uses']],
        $db->query("SELECT * FROM g7_ss_replies WHERE $w ORDER BY category, uses DESC, title")->fetchAll(PDO::FETCH_ASSOC));
}
function ss_reply_save(PDO $db, array $ctx, array $in): int {
    ss_need($ctx, 'reply');
    $title = mb_substr(trim((string)($in['title'] ?? '')), 0, 80); $body = mb_substr(trim((string)($in['body'] ?? '')), 0, 2000);
    if ($title === '' || $body === '') ss_fail('La respuesta rápida necesita nombre y texto.');
    $bid = (int)($in['brand_id'] ?? 0) ?: null;
    if ($bid && $ctx['brands'] !== null && !in_array($bid, $ctx['brands'], true)) ss_fail('No tenés acceso a esa marca.', 403);
    $cat = mb_substr(trim((string)($in['category'] ?? '')), 0, 40); $id = (int)($in['id'] ?? 0);
    if ($id) {
        $q = $db->prepare('UPDATE g7_ss_replies SET title = ?, body = ?, category = ?, brand_id = ?, updated_at = ? WHERE id = ?'); $q->execute([$title, $body, $cat, $bid, ss_now(), $id]);
        return $id;
    }
    $db->prepare('INSERT INTO g7_ss_replies (title, body, category, brand_id, created_by, updated_at) VALUES (?, ?, ?, ?, ?, ?)')->execute([$title, $body, $cat, $bid, $ctx['id'], ss_now()]);
    return (int)$db->lastInsertId();
}
function ss_reply_delete(PDO $db, array $ctx, int $id): void { ss_need($ctx, 'reply'); $db->prepare('DELETE FROM g7_ss_replies WHERE id = ?')->execute([$id]); }
/** Quick reply with its variables filled for this conversation: {nombre} {usuario} {marca} {agente} {cuenta}. */
function ss_reply_render(PDO $db, array $ctx, int $replyId, int $convId): string {
    $c = ss_conv_row($db, $ctx, $convId);
    $q = $db->prepare('SELECT * FROM g7_ss_replies WHERE id = ?'); $q->execute([$replyId]);
    if (!($r = $q->fetch(PDO::FETCH_ASSOC))) ss_fail('Esa respuesta rápida ya no existe.', 404);
    $db->prepare('UPDATE g7_ss_replies SET uses = uses + 1 WHERE id = ?')->execute([$replyId]);
    $first = trim(explode(' ', trim((string)$c['contact_name'], '@ '))[0] ?? '');
    $brand = $c['brand_id'] && ($b = g7_brands(true)[(int)$c['brand_id']] ?? null) ? $b['name'] : '';
    return strtr($r['body'], ['{nombre}' => $first, '{usuario}' => $c['contact_username'] ? '@' . $c['contact_username'] : $first, '{marca}' => $brand,
        '{agente}' => explode(' ', $ctx['name'])[0], '{cuenta}' => $c['acc_username'] ? '@' . $c['acc_username'] : (string)$c['acc_name']]);
}
/**
 * AI suggestion (Claude, if it is configured). It only returns text for the box: nobody sends anything until the person
 * presses "Send". With a per-person limit, and without passing internal notes or data of other conversations.
 */
function ss_conv_suggest(PDO $db, array $ctx, int $id, string $hint = ''): string {
    ss_need($ctx, 'reply');
    if (!ss_plan_allows('social_ai')) ss_plan_fail('las sugerencias con IA');
    $c = ss_conv_row($db, $ctx, $id);
    require_once dirname(__DIR__) . '/ia_lib.php';
    if (!function_exists('g7_claude_ready') || !g7_claude_ready()) ss_fail('La IA no está configurada en este sitio (Admin › Inteligencia artificial).');
    if (!ss_rate_ok($db, 'ai:' . $ctx['id'], 20, 600)) ss_fail('Pediste muchas sugerencias seguidas: esperá unos minutos.', 429);
    $q = $db->prepare("SELECT dir, body FROM g7_ss_msgs WHERE conv_id = ? AND dir IN ('in', 'out') AND body <> '' ORDER BY created_at DESC, id DESC LIMIT 12"); $q->execute([$id]);
    $hist = array_reverse($q->fetchAll(PDO::FETCH_ASSOC));
    if (!$hist) ss_fail('No hay mensajes para responder.');
    $brand = $c['brand_id'] && ($b = g7_brands(true)[(int)$c['brand_id']] ?? null) ? $b['name'] : 'la marca';
    $sys = "Sos community manager de $brand. Escribís una respuesta breve, cordial y útil en el idioma del cliente, sin inventar datos (precios, horarios, promociones) que no estén en la conversación: si falta un dato, pedilo o derivá. Sin hashtags. "
         . ($c['kind'] === 'comment' ? 'Es una respuesta pública a un comentario.' : 'Es un mensaje directo.');
    $user = "Conversación (cliente = C, marca = M):\n" . implode("\n", array_map(fn($m) => ($m['dir'] === 'in' ? 'C: ' : 'M: ') . mb_substr(str_replace("\n", ' ', $m['body']), 0, 600), $hist))
          . ($hint !== '' ? "\n\nIndicación del equipo: " . mb_substr($hint, 0, 300) : '');
    try { $r = g7_claude_json($sys, $user, ['type' => 'object', 'properties' => ['reply' => ['type' => 'string']], 'required' => ['reply'], 'additionalProperties' => false], ['maxTokens' => 1200, 'effort' => 'low']); }
    catch (Throwable $e) { error_log('[social ia] ' . $e->getMessage()); ss_fail('La IA no respondió. Probá de nuevo en un rato.', 502); }
    return mb_substr(trim((string)($r['reply'] ?? '')), 0, 2000);
}
/** Real Trabajo ticket from the conversation (with the last messages and the link). */
function ss_conv_ticket(PDO $db, array $ctx, int $id, array $in): int {
    ss_need($ctx, 'tickets');
    $c = ss_conv_row($db, $ctx, $id);
    ss_trabajo($db);
    $q = $db->prepare('SELECT * FROM g7_wk_ticket_cats WHERE id = ? AND active = 1'); $q->execute([(int)($in['cat_id'] ?? 0)]);
    if (!($cat = $q->fetch(PDO::FETCH_ASSOC))) ss_fail('Elegí una categoría.');
    $subject = mb_substr(trim((string)($in['subject'] ?? '')) ?: 'Inbox: ' . ($c['contact_name'] ?: 'conversación'), 0, 200);
    $q = $db->prepare("SELECT dir, body, author, created_at FROM g7_ss_msgs WHERE conv_id = ? AND dir IN ('in', 'out') ORDER BY created_at DESC, id DESC LIMIT 8"); $q->execute([$id]);
    $L = [trim((string)($in['description'] ?? '')), '', '— Conversación del Inbox de Social Studio —', 'Cuenta: ' . ($c['acc_username'] ? '@' . $c['acc_username'] : $c['acc_name']) . ' · ' . ($c['kind'] === 'dm' ? 'mensaje directo' : 'comentario'),
          'Contacto: ' . ($c['contact_name'] ?: $c['contact_id'])];
    if ($c['post_link']) $L[] = 'Publicación: ' . $c['post_link'];
    $L[] = '';
    foreach (array_reverse($q->fetchAll(PDO::FETCH_ASSOC)) as $m) $L[] = date('d/m H:i', strtotime($m['created_at'])) . ' ' . ($m['dir'] === 'in' ? ($m['author'] ?: 'Contacto') : 'Marca') . ': ' . mb_substr($m['body'], 0, 500);
    $L[] = ''; $L[] = 'Ver la conversación: ' . ss_url('inbox/c/' . $id);
    $prios = array_column(tk_config($db)['priorities'], 'id');
    $tid = wk_create_ticket($db, $ctx['id'], (int)$cat['id'], in_array($in['priority'] ?? '', $prios, true) ? $in['priority'] : '', $subject, trim(implode("\n", $L)), ['origin' => 'social']);
    $f = [];
    if ($as = (int)($in['assignee_id'] ?? 0)) { if (!ss_user_active($db, $as)) ss_fail('Esa persona no existe.'); $f['assignee_id'] = $as; }
    if ($tm = (int)($in['team_id'] ?? 0)) $f['team_id'] = $tm;
    if ($f) { wk_tk_set($db, $tid, $f); wk_task_from_tk($db, $tid, $ctx['id']); }
    $db->prepare('INSERT INTO g7_ss_conv_tickets (conv_id, ticket_id, created_by, created_at) VALUES (?, ?, ?, ?)')->execute([$id, $tid, $ctx['id'], ss_now()]);
    ss_conv_event($db, $id, $ctx['id'], 'creó el ticket #' . $tid);
    g7_audit('social.inbox_ticket', '#' . $id, ['ticket' => $tid]);
    return $tid;
}
