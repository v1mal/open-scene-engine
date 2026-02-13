<?php

declare(strict_types=1);

namespace OpenScene\Engine\Infrastructure\Database;

final class TableNames
{
    public function communities(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'openscene_communities';
    }

    public function posts(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'openscene_posts';
    }

    public function comments(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'openscene_comments';
    }

    public function votes(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'openscene_votes';
    }

    public function events(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'openscene_events';
    }

    public function bans(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'openscene_bans';
    }

    public function moderationLogs(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'openscene_moderation_logs';
    }

    public function voteEvents(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'openscene_vote_events';
    }

    public function postReports(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'openscene_post_reports';
    }
}
