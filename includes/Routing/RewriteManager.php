<?php

declare(strict_types=1);

namespace OpenScene\Engine\Routing;

final class RewriteManager
{
    public function hooks(): void
    {
        add_action('init', [$this, 'registerRules']);
        add_filter('query_vars', [$this, 'queryVars']);
    }

    public function registerRules(): void
    {
        add_rewrite_rule('^conversations/?$', 'index.php?openscene_route=conversations', 'top');
        add_rewrite_rule('^search/?$', 'index.php?openscene_route=search', 'top');
        add_rewrite_rule('^c/([^/]+)/?$', 'index.php?openscene_route=community&openscene_community_slug=$matches[1]', 'top');
        add_rewrite_rule('^post/(\d+)/?$', 'index.php?openscene_route=post&openscene_post_id=$matches[1]', 'top');
        add_rewrite_rule('^u/([^/]+)/?$', 'index.php?openscene_route=user&openscene_username=$matches[1]', 'top');
        add_rewrite_rule('^moderator/?$', 'index.php?openscene_route=moderator', 'top');
    }

    public function queryVars(array $vars): array
    {
        $vars[] = 'openscene_route';
        $vars[] = 'openscene_community_slug';
        $vars[] = 'openscene_post_id';
        $vars[] = 'openscene_username';
        return $vars;
    }
}
