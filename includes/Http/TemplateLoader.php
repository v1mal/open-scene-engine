<?php

declare(strict_types=1);

namespace OpenScene\Engine\Http;

use OpenScene\Engine\Infrastructure\Database\TableNames;
use OpenScene\Engine\Infrastructure\Repository\CommunityRepository;
use OpenScene\Engine\Infrastructure\Repository\PostRepository;

final class TemplateLoader
{
    public function hooks(): void
    {
        add_filter('template_include', [$this, 'intercept'], 99);
    }

    public function intercept(string $template): string
    {
        $route = (string) get_query_var('openscene_route', '');
        if ($route === '') {
            $route = $this->fallbackRouteFromPath();
        }
        $openscenePageId = (int) get_option('openscene_page_id', 0);
        $isOpenScenePage = $openscenePageId > 0 && is_page($openscenePageId);

        if ($route === '' && ! $isOpenScenePage) {
            return $template;
        }

        $customTemplate = OPENSCENE_ENGINE_PATH . 'templates/app-shell.php';
        if (is_readable($customTemplate)) {
            if ($route === 'post') {
                $postId = (int) get_query_var('openscene_post_id', 0);
                $post = $this->loadPostForRoute($postId);
                $status = is_array($post) ? (string) ($post['status'] ?? '') : '';
                $communityVisible = is_array($post) ? $this->isCommunityVisibleById((int) ($post['community_id'] ?? 0)) : false;
                if (! is_array($post) || ! in_array($status, ['published', 'locked', 'removed'], true) || ! $communityVisible) {
                    status_header(404);
                    nocache_headers();
                    return $customTemplate;
                }
            }

            if ($route === 'community') {
                $slug = sanitize_title((string) get_query_var('openscene_community_slug', ''));
                if ($slug === '' || ! $this->isCommunityVisibleBySlug($slug)) {
                    status_header(404);
                    nocache_headers();
                    return $customTemplate;
                }
            }

            if ($route === 'user') {
                $username = strtolower(sanitize_user((string) get_query_var('openscene_username', ''), true));
                $user = $this->loadUserForRoute($username);
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
}
