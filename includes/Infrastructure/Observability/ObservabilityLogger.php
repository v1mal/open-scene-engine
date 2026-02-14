<?php

declare(strict_types=1);

namespace OpenScene\Engine\Infrastructure\Observability;

use OpenScene\Engine\Infrastructure\Database\TableNames;
use wpdb;

final class ObservabilityLogger
{
    public const MODE_OFF = 'off';
    public const MODE_BASIC = 'basic';
    public const SLOW_QUERY_THRESHOLD_MS = 200;
    private const RETENTION_DAYS = 7;

    public function __construct(
        private readonly wpdb $wpdb,
        private readonly TableNames $tables
    ) {
    }

    public function isBasicEnabled(): bool
    {
        $settings = get_option('openscene_admin_settings', []);
        if (! is_array($settings)) {
            return false;
        }

        $mode = (string) ($settings['observability_mode'] ?? self::MODE_OFF);
        return $mode === self::MODE_BASIC;
    }

    public function logSlowQuery(string $context, int $durationMs): void
    {
        if (! $this->isBasicEnabled()) {
            return;
        }

        $this->insert('slow_query', $context, $durationMs);
    }

    public function logMutationFailure(string $context): void
    {
        if (! $this->isBasicEnabled()) {
            return;
        }

        $this->insert('mutation_failure', $context, null);
    }

    private function insert(string $type, string $context, ?int $durationMs): void
    {
        $table = $this->tables->observabilityLogs();
        $now = current_time('mysql', true);
        $cutoff = gmdate('Y-m-d H:i:s', strtotime('-' . self::RETENTION_DAYS . ' days', strtotime($now)));

        try {
            $this->wpdb->query($this->wpdb->prepare(
                "DELETE FROM {$table} WHERE created_at < %s",
                $cutoff
            ));

            $payload = [
                'type' => substr(sanitize_key($type), 0, 50),
                'context' => substr(sanitize_key($context), 0, 100),
                'duration_ms' => $durationMs,
                'created_at' => $now,
            ];
            $format = ['%s', '%s', $durationMs === null ? '%s' : '%d', '%s'];
            $this->wpdb->insert($table, $payload, $format);
        } catch (\Throwable) {
            // Observability must never impact runtime behavior.
        }
    }
}
