<?php

declare(strict_types=1);

namespace OpenScene\Engine\Infrastructure\Observability;

use OpenScene\Engine\Infrastructure\Database\TableNames;
use wpdb;

final class IntegrityChecker
{
    public function __construct(
        private readonly wpdb $wpdb,
        private readonly TableNames $tables
    ) {
    }

    /** @return array{score_drift:int,comment_drift:int,duplicate_votes:int,orphan_comments:int} */
    public function run(): array
    {
        $posts = $this->tables->posts();
        $comments = $this->tables->comments();
        $votes = $this->tables->votes();

        $scoreDrift = (int) $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$posts} p
             LEFT JOIN (
               SELECT target_id, COALESCE(SUM(value), 0) AS vote_sum
               FROM {$votes}
               WHERE target_type = 'post'
               GROUP BY target_id
             ) v ON v.target_id = p.id
             WHERE p.score <> COALESCE(v.vote_sum, 0)"
        );

        $commentDrift = (int) $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$posts} p
             LEFT JOIN (
               SELECT post_id, COUNT(*) AS comment_total
               FROM {$comments}
               WHERE status = 'published'
               GROUP BY post_id
             ) c ON c.post_id = p.id
             WHERE p.comment_count <> COALESCE(c.comment_total, 0)"
        );

        $duplicateVotes = (int) $this->wpdb->get_var(
            "SELECT COALESCE(SUM(dup_count), 0) FROM (
               SELECT (COUNT(*) - 1) AS dup_count
               FROM {$votes}
               GROUP BY user_id, target_type, target_id
               HAVING COUNT(*) > 1
             ) d"
        );

        $orphanComments = (int) $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$comments} c
             LEFT JOIN {$comments} p ON p.id = c.parent_id
             WHERE c.parent_id IS NOT NULL
               AND p.id IS NULL"
        );

        return [
            'score_drift' => $scoreDrift,
            'comment_drift' => $commentDrift,
            'duplicate_votes' => $duplicateVotes,
            'orphan_comments' => $orphanComments,
        ];
    }
}

