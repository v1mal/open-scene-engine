<?php
if (!defined('ABSPATH')) {
    exit("Run via WP-CLI.\n");
}

global $wpdb;

function argValue(string $name, $default) {
    global $argv;
    $map = [
        'users' => 0,
        'posts_per_user' => 1,
        'burst' => 2,
        'list_limit' => 3,
    ];
    if (!isset($map[$name])) {
        return $default;
    }
    // Positional args after script filename:
    // wp eval-file verify-saved-posts-load.php <users> <posts_per_user> <burst> <list_limit>
    $idx = 2 + $map[$name];
    if (isset($argv[$idx]) && $argv[$idx] !== '') {
        return $argv[$idx];
    }
    return $default;
}

function out(string $line): void { echo $line . PHP_EOL; }

$usersTarget  = max(1, (int) argValue('users', 5000));
$postsPerUser = max(1, (int) argValue('posts_per_user', 20));
$burstSize    = max(2, (int) argValue('burst', 200));
$listLimit    = max(1, (int) argValue('list_limit', 20));

$prefix = $wpdb->prefix;
$savedTable = $prefix . 'openscene_saved_posts';
$postsTable = $prefix . 'openscene_posts';
$usersTable = $prefix . 'users';

out("=== OpenScene Saved Posts Load Simulation ===");
out("users={$usersTarget} posts_per_user={$postsPerUser} burst={$burstSize} list_limit={$listLimit}");

$hasSavedTable = (bool) $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $savedTable));
if (!$hasSavedTable) {
    out("FAIL: {$savedTable} missing. Run saved-posts migration first.");
    return;
}

$users = $wpdb->get_col($wpdb->prepare(
    "SELECT ID FROM {$usersTable} ORDER BY ID ASC LIMIT %d",
    $usersTarget
));
if (empty($users)) {
    out("FAIL: no users found.");
    return;
}

$posts = $wpdb->get_col($wpdb->prepare(
    "SELECT id FROM {$postsTable}
     WHERE status IN ('published','removed')
     ORDER BY created_at DESC, id DESC
     LIMIT %d",
    max($postsPerUser * 3, 500)
));
if (empty($posts)) {
    out("FAIL: no posts found.");
    return;
}

out("picked_users=" . count($users) . " posts_pool=" . count($posts));

$startAll = microtime(true);
$start = microtime(true);
$inserted = 0;
$dupes = 0;

foreach ($users as $userId) {
    $pick = array_slice($posts, 0, min($postsPerUser, count($posts)));
    foreach ($pick as $postId) {
        $sql = $wpdb->prepare(
            "INSERT INTO {$savedTable} (user_id, post_id, created_at)
             VALUES (%d, %d, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE id = id",
            (int) $userId,
            (int) $postId
        );
        $r = $wpdb->query($sql);
        if ($r === 1) $inserted++; else $dupes++;
    }
}
$saveSec = microtime(true) - $start;
out("save_pass inserted={$inserted} duplicate_or_noop={$dupes} sec=" . round($saveSec, 3));

$burstStart = microtime(true);
$burstInserts = 0;
$burstNoops = 0;
for ($i = 0; $i < $burstSize; $i++) {
    $userId = (int) $users[$i % count($users)];
    $postId = (int) $posts[$i % count($posts)];
    $sql = $wpdb->prepare(
        "INSERT INTO {$savedTable} (user_id, post_id, created_at)
         VALUES (%d, %d, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE id = id",
        $userId,
        $postId
    );
    $r = $wpdb->query($sql);
    if ($r === 1) $burstInserts++; else $burstNoops++;
}
$burstSec = microtime(true) - $burstStart;
out("burst_pass inserts={$burstInserts} noops={$burstNoops} sec=" . round($burstSec, 3));

$listStart = microtime(true);
$listRows = 0;
$sampleUsers = array_slice($users, 0, min(200, count($users)));
foreach ($sampleUsers as $userId) {
    $sql = $wpdb->prepare(
        "SELECT sp.post_id, sp.created_at
         FROM {$savedTable} sp
         WHERE sp.user_id = %d
         ORDER BY sp.created_at DESC, sp.id DESC
         LIMIT %d",
        (int) $userId,
        $listLimit
    );
    $rows = $wpdb->get_results($sql, ARRAY_A) ?: [];
    $listRows += count($rows);
}
$listSec = microtime(true) - $listStart;
out("list_pass sampled_users=" . count($sampleUsers) . " rows={$listRows} sec=" . round($listSec, 3));

$explain = $wpdb->get_results(
    "EXPLAIN SELECT sp.post_id, sp.created_at
     FROM {$savedTable} sp
     WHERE sp.user_id = 1
     ORDER BY sp.created_at DESC, sp.id DESC
     LIMIT 20",
    ARRAY_A
);
$key = $explain[0]['key'] ?? '(none)';
$extra = strtolower((string) ($explain[0]['Extra'] ?? ''));
$filesort = str_contains($extra, 'filesort');
out("explain_list_key={$key}");
out("explain_list_extra=" . ($explain[0]['Extra'] ?? '')); 
out("explain_list_filesort=" . ($filesort ? 'yes' : 'no'));

$totalRows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$savedTable}");
out("saved_rows_total={$totalRows}");
out("total_sec=" . round(microtime(true) - $startAll, 3));
