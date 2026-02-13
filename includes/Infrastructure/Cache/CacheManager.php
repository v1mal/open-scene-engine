<?php

declare(strict_types=1);

namespace OpenScene\Engine\Infrastructure\Cache;

use Throwable;

final class CacheManager
{
    private string $group = 'openscene';

    public function key(string $segment): string
    {
        $version = (string) get_option('openscene_cache_version', '1');
        return sprintf('openscene:v%s:%s', $version, $segment);
    }

    public function get(string $segment): mixed
    {
        try {
            return wp_cache_get($this->key($segment), $this->group);
        } catch (Throwable) {
            return false;
        }
    }

    public function set(string $segment, mixed $value, int $ttl = 60): bool
    {
        try {
            return (bool) wp_cache_set($this->key($segment), $value, $this->group, $ttl);
        } catch (Throwable) {
            return false;
        }
    }

    public function delete(string $segment): bool
    {
        try {
            return (bool) wp_cache_delete($this->key($segment), $this->group);
        } catch (Throwable) {
            return false;
        }
    }

    /** @param list<string> $segments */
    public function invalidateMany(array $segments): void
    {
        foreach ($segments as $segment) {
            $this->delete($segment);
        }
    }

    public function bumpVersion(): void
    {
        $version = (int) get_option('openscene_cache_version', 1);
        update_option('openscene_cache_version', (string) ($version + 1), false);
    }
}
