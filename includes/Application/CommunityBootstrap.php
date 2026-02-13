<?php

declare(strict_types=1);

namespace OpenScene\Engine\Application;

use OpenScene\Engine\Infrastructure\Cache\CacheManager;
use OpenScene\Engine\Infrastructure\Repository\CommunityRepository;
use Throwable;

final class CommunityBootstrap
{
    public function __construct(
        private readonly CommunityRepository $communities,
        private readonly CacheManager $cache
    ) {
    }

    public function ensureDefaults(): void
    {
        try {
            $seedUserId = $this->resolveSeedUserId();
            $now = gmdate('Y-m-d H:i:s');
            $rows = [
                [
                    'name' => 'Techno',
                    'slug' => 'techno',
                    'description' => 'Techno conversations and local scene updates.',
                    'icon' => 'music-4',
                ],
                [
                    'name' => 'House',
                    'slug' => 'house',
                    'description' => 'House discussions, tracks, sets, and events.',
                    'icon' => 'music-4',
                ],
                [
                    'name' => 'Ambient',
                    'slug' => 'ambient',
                    'description' => 'Ambient, downtempo, and experimental sound.',
                    'icon' => 'music-4',
                ],
                [
                    'name' => 'Leftfield',
                    'slug' => 'leftfield',
                    'description' => 'Leftfield and niche underground conversations.',
                    'icon' => 'music-4',
                ],
                [
                    'name' => 'Garage',
                    'slug' => 'garage',
                    'description' => 'Garage and club culture conversation threads.',
                    'icon' => 'music-4',
                ],
            ];

            $inserted = 0;
            foreach ($rows as $row) {
                if ($this->communities->findBySlug($row['slug']) !== null) {
                    continue;
                }

                $id = $this->communities->create([
                    'name' => $row['name'],
                    'slug' => $row['slug'],
                    'description' => $row['description'],
                    'icon' => $row['icon'],
                    'rules' => null,
                    'visibility' => 'public',
                    'created_by_user_id' => $seedUserId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                if ($id > 0) {
                    $inserted++;
                }
            }

            if ($inserted > 0) {
                $this->cache->bumpVersion();
            }
        } catch (Throwable) {
            // Fail open: if seeding fails, plugin should continue booting.
        }
    }

    private function resolveSeedUserId(): int
    {
        $admins = get_users([
            'role' => 'administrator',
            'fields' => 'ID',
            'number' => 1,
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);

        if (is_array($admins) && isset($admins[0])) {
            return (int) $admins[0];
        }

        $users = get_users([
            'fields' => 'ID',
            'number' => 1,
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);

        if (is_array($users) && isset($users[0])) {
            return (int) $users[0];
        }

        return 1;
    }
}
