<?php

declare(strict_types=1);

namespace OpenScene\Engine\Application;

final class Scheduler
{
    public function hooks(): void
    {
        add_action('init', [$this, 'registerSchedules']);
        add_action('openscene_reconcile_aggregates', [$this, 'reconcileAggregates']);
        add_action('openscene_orphan_cleanup', [$this, 'cleanupOrphans']);
    }

    public function registerSchedules(): void
    {
        if (! wp_next_scheduled('openscene_reconcile_aggregates')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'openscene_reconcile_aggregates');
        }

        if (! wp_next_scheduled('openscene_orphan_cleanup')) {
            wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', 'openscene_orphan_cleanup');
        }
    }

    public function reconcileAggregates(): void
    {
        do_action('openscene_reconciliation_tick');
    }

    public function cleanupOrphans(): void
    {
        do_action('openscene_orphan_cleanup_tick');
    }
}
