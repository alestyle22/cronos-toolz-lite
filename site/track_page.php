<?php
// /toolz/track_page.php
// Include this in any page to log a page access to g7_accesos_rapidos
// Usage: define('PAGE_TOOL_URL', '/toolz/mything/') and PAGE_TOOL_NAME before including
// OR pass: include_page_track($db, $currentUser['id'], '/toolz/mything/', 'My Tool');

if (!function_exists('include_page_track')):
function include_page_track($db, $userId, $url, $name) {
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS g7_accesos_rapidos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            tool VARCHAR(255) NOT NULL,
            tool_name VARCHAR(255) NOT NULL DEFAULT '',
            meta VARCHAR(50) DEFAULT '',
            accessed_at DATETIME DEFAULT NOW(),
            INDEX idx_user(user_id),
            INDEX idx_time(accessed_at)
        )");
        $db->prepare("INSERT INTO g7_accesos_rapidos (user_id, tool, tool_name, meta) VALUES (?,?,?,?)")
           ->execute([$userId, $url, $name, '']);
    } catch(Exception $e) {}
}
endif;

// Auto-run if constants defined
if (defined('PAGE_TOOL_URL') && defined('PAGE_TOOL_NAME') && isset($db) && isset($currentUser)) {
    include_page_track($db, $currentUser['id'], PAGE_TOOL_URL, PAGE_TOOL_NAME);
}
