<?php

declare(strict_types=1);

function out(string $msg): void { echo $msg, "\n"; }
function pass(string $label): void { out("✔ [PASS] {$label}"); }
function fail(string $label, string $reason): void { out("✘ [FAIL] {$label} — {$reason}"); }

$settings = get_option('openscene_admin_settings', []);
if (!is_array($settings)) { $settings = []; }
$original = $settings;
$flags = isset($settings['feature_flags']) && is_array($settings['feature_flags']) ? $settings['feature_flags'] : [];

$admin = get_users(['role__in' => ['administrator'], 'number' => 1, 'fields' => ['ID','user_login']]);
if (empty($admin)) {
    fail('bootstrap', 'No administrator user found');
    exit(1);
}
$adminId = (int)$admin[0]->ID;
$adminLogin = (string)$admin[0]->user_login;

$post = $GLOBALS['wpdb']->get_row(
    "SELECT p.id
     FROM {$GLOBALS['wpdb']->prefix}openscene_posts p
     INNER JOIN {$GLOBALS['wpdb']->prefix}openscene_communities c ON c.id = p.community_id
     WHERE p.status = 'published' AND c.visibility = 'public'
     ORDER BY p.id DESC
     LIMIT 1",
    ARRAY_A
);
if (!is_array($post)) {
    fail('bootstrap', 'No published post in public community found');
    exit(1);
}
$postId = (int)$post['id'];

wp_set_current_user($adminId);
$nonce = wp_create_nonce('wp_rest');
$_SERVER['HTTP_X_WP_NONCE'] = $nonce;

function do_rest(string $method, string $route): WP_REST_Response {
    $req = new WP_REST_Request($method, $route);
    return rest_do_request($req);
}

function set_saved_posts_flag(bool $enabled, array &$settings, array $flags): void {
    $flags['saved_posts'] = $enabled;
    $settings['feature_flags'] = $flags;
    update_option('openscene_admin_settings', $settings, false);
}

$total = 0; $passed = 0;

try {
    // Ensure clean state
    do_rest('DELETE', "/openscene/v1/posts/{$postId}/save");

    // 1) Feature disabled -> 403 openscene_feature_disabled
    set_saved_posts_flag(false, $settings, $flags);
    $total++;
    $r = do_rest('POST', "/openscene/v1/posts/{$postId}/save");
    $d = $r->get_data();
    $code = (string)($d['errors'][0]['code'] ?? '');
    if ($r->get_status() === 403 && $code === 'openscene_feature_disabled') { $passed++; pass('save blocked when saved_posts=false'); }
    else { fail('save blocked when saved_posts=false', 'status=' . $r->get_status() . ' code=' . $code); }

    // 2) Enable flag
    set_saved_posts_flag(true, $settings, $flags);

    // 3) Save -> 200 saved true
    $total++;
    $r = do_rest('POST', "/openscene/v1/posts/{$postId}/save");
    $d = $r->get_data();
    $saved = (bool)($d['data']['saved'] ?? false);
    if ($r->get_status() === 200 && $saved === true) { $passed++; pass('save returns 200 and saved=true'); }
    else { fail('save returns 200 and saved=true', 'status=' . $r->get_status()); }

    // 4) Save again idempotent -> already_saved true
    $total++;
    $r = do_rest('POST', "/openscene/v1/posts/{$postId}/save");
    $d = $r->get_data();
    $already = (bool)($d['data']['already_saved'] ?? false);
    if ($r->get_status() === 200 && $already === true) { $passed++; pass('duplicate save is idempotent'); }
    else { fail('duplicate save is idempotent', 'status=' . $r->get_status() . ' already=' . ($already ? '1':'0')); }

    // 5) List saved -> contains post
    $total++;
    $r = do_rest('GET', "/openscene/v1/users/{$adminLogin}/saved");
    $d = $r->get_data();
    $found = false;
    foreach ((array)($d['data'] ?? []) as $row) {
        if ((int)($row['id'] ?? 0) === $postId) { $found = true; break; }
    }
    if ($r->get_status() === 200 && $found) { $passed++; pass('saved list includes post'); }
    else { fail('saved list includes post', 'status=' . $r->get_status() . ' found=' . ($found ? '1':'0')); }

    // 6) Unsave -> 200 saved false
    $total++;
    $r = do_rest('DELETE', "/openscene/v1/posts/{$postId}/save");
    $d = $r->get_data();
    $saved = (bool)($d['data']['saved'] ?? true);
    if ($r->get_status() === 200 && $saved === false) { $passed++; pass('unsave returns 200 and saved=false'); }
    else { fail('unsave returns 200 and saved=false', 'status=' . $r->get_status()); }

    // 7) Unsave again idempotent
    $total++;
    $r = do_rest('DELETE', "/openscene/v1/posts/{$postId}/save");
    $d = $r->get_data();
    $already = (bool)($d['data']['already_unsaved'] ?? false);
    if ($r->get_status() === 200 && $already === true) { $passed++; pass('duplicate unsave is idempotent'); }
    else { fail('duplicate unsave is idempotent', 'status=' . $r->get_status() . ' already=' . ($already ? '1':'0')); }

} finally {
    // Cleanup and restore settings
    do_rest('DELETE', "/openscene/v1/posts/{$postId}/save");
    update_option('openscene_admin_settings', $original, false);
}

out('----------------------------------------');
out('RESULTS');
out('----------------------------------------');
out('Total: ' . $total);
out('Passed: ' . $passed);
out('Failed: ' . ($total - $passed));
