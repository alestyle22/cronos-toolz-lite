<?php
// El chat viejo se reemplazó por /toolz/chat/ (ver CHAT_PLAN.md). Se conservan los enlaces a una persona (?u=ID).
$u = (int)($_GET['u'] ?? $_GET['user'] ?? 0);
header('Location: /toolz/chat/' . ($u ? '?u=' . $u : ''), true, 301);
exit;
