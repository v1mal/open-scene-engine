<?php

declare(strict_types=1);

namespace OpenScene\Engine\Application;

use OpenScene\Engine\Infrastructure\Observability\ObservabilityLogger;
use OpenScene\Engine\Infrastructure\Repository\PostRepository;
use OpenScene\Engine\Infrastructure\Repository\SavedPostRepository;

final class SavedPostService
{
    public function __construct(
        private readonly SavedPostRepository $savedPosts,
        private readonly PostRepository $posts,
        private readonly ?ObservabilityLogger $observability = null
    ) {
    }

    /** @return array{ok:bool,already_saved:bool}|array{ok:bool,error:string} */
    public function save(int $userId, int $postId): array
    {
        $observe = $this->observability?->isBasicEnabled() === true;
        $start = $observe ? microtime(true) : 0.0;
        $post = $this->posts->findPublicById($postId);
        if (! is_array($post) || ! in_array((string) ($post['status'] ?? ''), ['published', 'locked'], true)) {
            return ['ok' => false, 'error' => 'not_found'];
        }

        $result = $this->savedPosts->save($userId, $postId);
        if (! ($result['ok'] ?? false)) {
            $this->observability?->logMutationFailure('saved_post_save');
            return ['ok' => false, 'error' => 'save_failed'];
        }

        if ($observe) {
            $durationMs = (int) round((microtime(true) - $start) * 1000);
            if ($durationMs > ObservabilityLogger::SLOW_QUERY_THRESHOLD_MS) {
                $this->observability?->logSlowQuery('saved_post_save', $durationMs);
            }
        }

        return $result;
    }

    /** @return array{ok:bool,already_unsaved:bool}|array{ok:bool,error:string} */
    public function unsave(int $userId, int $postId): array
    {
        $observe = $this->observability?->isBasicEnabled() === true;
        $start = $observe ? microtime(true) : 0.0;
        $post = $this->posts->findPublicById($postId);
        if (! is_array($post)) {
            return ['ok' => false, 'error' => 'not_found'];
        }

        $result = $this->savedPosts->unsave($userId, $postId);
        if (! ($result['ok'] ?? false)) {
            $this->observability?->logMutationFailure('saved_post_unsave');
            return ['ok' => false, 'error' => 'unsave_failed'];
        }

        if ($observe) {
            $durationMs = (int) round((microtime(true) - $start) * 1000);
            if ($durationMs > ObservabilityLogger::SLOW_QUERY_THRESHOLD_MS) {
                $this->observability?->logSlowQuery('saved_post_unsave', $durationMs);
            }
        }

        return $result;
    }
}
