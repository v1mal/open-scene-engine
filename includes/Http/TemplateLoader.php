<?php

declare(strict_types=1);

namespace OpenScene\Engine\Http;

use OpenScene\Engine\Infrastructure\Database\TableNames;
use OpenScene\Engine\Infrastructure\Repository\CommentRepository;
use OpenScene\Engine\Infrastructure\Repository\CommunityRepository;
use OpenScene\Engine\Infrastructure\Repository\EventRepository;
use OpenScene\Engine\Infrastructure\Repository\PostRepository;
use OpenScene\Engine\Infrastructure\Repository\VoteRepository;

final class TemplateLoader
{
    public function hooks(): void
    {
        add_filter('template_include', [$this, 'intercept'], 99);
    }

    public function intercept(string $template): string
    {
        $context = $this->routeContext();
        $route = (string) ($context['route'] ?? '');
        if ($route === '') {
            $route = $this->fallbackRouteFromPath();
            $context['route'] = $route;
        }
        $openscenePageId = (int) get_option('openscene_page_id', 0);
        $isOpenScenePage = $openscenePageId > 0 && is_page($openscenePageId);

        if ($route === '' && ! $isOpenScenePage) {
            return $template;
        }

        $customTemplate = OPENSCENE_ENGINE_PATH . 'templates/app-shell.php';
        if (is_readable($customTemplate)) {
            $ssrData = $this->buildSsrData($context);
            set_query_var('openscene_ssr_data', $ssrData);

            if ($route === 'post') {
                $post = is_array($ssrData['post'] ?? null) ? $ssrData['post'] : null;
                $status = is_array($post) ? (string) ($post['status'] ?? '') : '';
                $communityVisible = (bool) ($ssrData['post_community_visible'] ?? false);
                if (! is_array($post) || ! in_array($status, ['published', 'locked', 'removed'], true) || ! $communityVisible) {
                    status_header(404);
                    nocache_headers();
                    return $customTemplate;
                }
            }

            if ($route === 'community') {
                $community = is_array($ssrData['community'] ?? null) ? $ssrData['community'] : null;
                if (! is_array($community) || (string) ($community['visibility'] ?? '') !== 'public') {
                    status_header(404);
                    nocache_headers();
                    return $customTemplate;
                }
            }

            if ($route === 'user') {
                $user = $ssrData['user'] ?? null;
                if (! ($user instanceof \WP_User)) {
                    status_header(404);
                    nocache_headers();
                    return $customTemplate;
                }
            }

            if ($route === 'moderator' && ! current_user_can('openscene_moderate')) {
                status_header(403);
                nocache_headers();
                return $customTemplate;
            }

            status_header(200);
            return $customTemplate;
        }

        return $template;
    }

    public function routeContext(): array
    {
        $route = (string) get_query_var('openscene_route', 'page');
        if ($route === 'page') {
            $fallback = $this->fallbackRouteFromPath();
            if ($fallback !== '') {
                $route = $fallback;
            }
        }

        return [
            'route' => $route,
            'communitySlug' => (string) get_query_var('openscene_community_slug', ''),
            'postId' => (int) get_query_var('openscene_post_id', 0),
            'username' => strtolower(sanitize_user((string) get_query_var('openscene_username', ''), true)),
        ];
    }

    private function fallbackRouteFromPath(): string
    {
        $path = trim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
        if ($path === 'search' || str_starts_with($path, 'search/')) {
            return 'search';
        }

        return '';
    }

    private function loadPostForRoute(int $postId): ?array
    {
        if ($postId <= 0) {
            return null;
        }

        global $wpdb;
        $repo = new PostRepository($wpdb, new TableNames());
        return $repo->find($postId);
    }

    private function loadUserForRoute(string $username): ?\WP_User
    {
        if ($username === '') {
            return null;
        }

        $user = get_user_by('login', $username);
        if (! ($user instanceof \WP_User)) {
            return null;
        }

        // Future-proof visibility policy: non-zero status is treated as non-public.
        if ((int) ($user->user_status ?? 0) !== 0) {
            return null;
        }

        return $user;
    }

    private function isCommunityVisibleBySlug(string $slug): bool
    {
        if ($slug === '') {
            return false;
        }

        global $wpdb;
        $repo = new CommunityRepository($wpdb, new TableNames());
        $community = $repo->findBySlug($slug);
        return is_array($community) && (string) ($community['visibility'] ?? '') === 'public';
    }

    private function isCommunityVisibleById(int $communityId): bool
    {
        if ($communityId <= 0) {
            return false;
        }

        global $wpdb;
        $repo = new CommunityRepository($wpdb, new TableNames());
        $community = $repo->findById($communityId);
        return is_array($community) && (string) ($community['visibility'] ?? '') === 'public';
    }

    private function buildSsrData(array $context): array
    {
        global $wpdb;
        $tables = new TableNames();
        $communityRepo = new CommunityRepository($wpdb, $tables);
        $postRepo = new PostRepository($wpdb, $tables);

        $route = (string) ($context['route'] ?? 'page');
        $postId = (int) ($context['postId'] ?? 0);
        $communitySlug = sanitize_title((string) ($context['communitySlug'] ?? ''));
        $username = strtolower(sanitize_user((string) ($context['username'] ?? ''), true));

        $post = null;
        $postCommunityVisible = true;
        if ($route === 'post' && $postId > 0) {
            $post = $postRepo->find($postId);
            if (is_array($post)) {
                $postCommunity = $communityRepo->findById((int) ($post['community_id'] ?? 0));
                $postCommunityVisible = is_array($postCommunity) && (string) ($postCommunity['visibility'] ?? '') === 'public';
            } else {
                $postCommunityVisible = false;
            }
        }

        $community = null;
        if ($route === 'community' && $communitySlug !== '') {
            $community = $communityRepo->findBySlug($communitySlug);
        }
        $user = null;
        if ($route === 'user' && $username !== '') {
            $user = $this->loadUserForRoute($username);
        }

        $postUserVote = 0;
        $postUserReported = false;
        $postStatus = is_array($post) ? (string) ($post['status'] ?? '') : '';
        if (
            $route === 'post'
            && is_array($post)
            && in_array($postStatus, ['published', 'locked'], true)
            && $postCommunityVisible
            && is_user_logged_in()
        ) {
            $voteRepo = new VoteRepository($wpdb, $tables, $postRepo, new CommentRepository($wpdb, $tables));
            $postUserVote = $voteRepo->findUserVoteValue(get_current_user_id(), 'post', $postId);
            $postUserReported = $postRepo->hasUserReported($postId, get_current_user_id());
        }

        $communityRows = [];
        $eventRows = [];
        if (in_array($route, ['user', 'post'], true)) {
            $communityRows = $communityRepo->listVisible(8);
            $eventRows = (new EventRepository($wpdb, $tables))->listByScope('upcoming', 3, null);
        }

        $rules = $this->communityRules();

        return [
            'context' => $context,
            'post' => $post,
            'post_community_visible' => $postCommunityVisible,
            'community' => $community,
            'user' => $user,
            'post_user_vote' => $postUserVote,
            'post_user_reported' => $postUserReported,
            'community_rows' => $communityRows,
            'event_rows' => $eventRows,
            'rules' => $rules,
        ];
    }

    /** @return list<string> */
    private function communityRules(): array
    {
        $raw = trim((string) get_option('openscene_community_rules', ''));
        if ($raw !== '') {
            $lines = preg_split('/\R+/', $raw) ?: [];
            $rules = [];
            foreach ($lines as $line) {
                $line = trim((string) $line);
                if ($line !== '') {
                    $rules[] = $line;
                }
            }
            if ($rules !== []) {
                return array_values($rules);
            }
        }

        return [
            'No gatekeeping. Everyone was new once.',
            'Respect the artists and venue staff.',
            'No promotion of commercial mainstream events.',
            'High signal, low noise content only.',
        ];
    }
}
