<?php

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$cleanup = (bool) get_option('openscene_cleanup_on_uninstall', false);

if (! $cleanup) {
    return;
}

global $wpdb;

$tables = [
    'openscene_events',
    'openscene_votes',
    'openscene_comments',
    'openscene_posts',
    'openscene_communities',
    'openscene_bans',
    'openscene_moderation_logs',
    'openscene_vote_events',
];

foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}{$table}"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
}

delete_option('openscene_db_version');
delete_option('openscene_cache_version');
delete_option('openscene_page_id');
delete_option('openscene_cleanup_on_uninstall');

wp_clear_scheduled_hook('openscene_reconcile_aggregates');
wp_clear_scheduled_hook('openscene_orphan_cleanup');
