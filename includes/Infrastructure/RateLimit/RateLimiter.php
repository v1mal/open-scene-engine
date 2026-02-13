<?php

declare(strict_types=1);

namespace OpenScene\Engine\Infrastructure\RateLimit;

final class RateLimiter
{
    public function allow(string $bucket, int $limit, int $windowSeconds, int $userId = 0): bool
    {
        $subject = $userId > 0 ? 'u:' . $userId : 'ip:' . $this->clientIp();
        $key = sprintf('openscene_rate:%s:%s', $bucket, $subject);

        $record = get_transient($key);
        if (! is_array($record)) {
            $record = ['count' => 0, 'reset' => time() + $windowSeconds];
        }

        if ((int) $record['reset'] <= time()) {
            $record = ['count' => 0, 'reset' => time() + $windowSeconds];
        }

        if ((int) $record['count'] >= $limit) {
            return false;
        }

        $record['count'] = (int) $record['count'] + 1;
        set_transient($key, $record, max(1, ((int) $record['reset']) - time()));

        return true;
    }

    private function clientIp(): string
    {
        $raw = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $ip = sanitize_text_field(wp_unslash((string) $raw));
        return preg_match('/^[a-f0-9:\.]+$/i', $ip) ? $ip : '0.0.0.0';
    }
}
