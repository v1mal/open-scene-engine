<?php

declare(strict_types=1);

namespace OpenScene\Engine\Http\Rest;

final class RestRegistrar
{
    public function __construct(
        private readonly FeedController $feed,
        private readonly CommunityController $communities,
        private readonly PostController $posts,
        private readonly EventController $events,
        private readonly CommentController $comments,
        private readonly VoteController $votes,
        private readonly UserController $users,
        private readonly ModerationController $moderation,
        private readonly SavedPostController $savedPosts
    ) {
    }

    public function hooks(): void
    {
        add_action('rest_api_init', [$this, 'register']);
    }

    public function register(): void
    {
        register_rest_route('openscene/v1', '/posts', [
            ['methods' => 'GET', 'callback' => [$this->feed, 'list'], 'permission_callback' => '__return_true'],
            ['methods' => 'POST', 'callback' => [$this->posts, 'create'], 'permission_callback' => fn(): bool => is_user_logged_in()],
        ]);

        register_rest_route('openscene/v1', '/search', [
            ['methods' => 'GET', 'callback' => [$this->feed, 'search'], 'permission_callback' => '__return_true'],
        ]);

        register_rest_route('openscene/v1', '/activity/recent', [
            ['methods' => 'GET', 'callback' => [$this->feed, 'recentActivity'], 'permission_callback' => '__return_true'],
        ]);

        register_rest_route('openscene/v1', '/posts/(?P<id>\d+)', [
            ['methods' => 'GET', 'callback' => [$this->posts, 'show'], 'permission_callback' => '__return_true'],
            ['methods' => 'PATCH', 'callback' => [$this->posts, 'update'], 'permission_callback' => fn(): bool => is_user_logged_in()],
            ['methods' => 'DELETE', 'callback' => [$this->posts, 'delete'], 'permission_callback' => fn(): bool => is_user_logged_in()],
        ]);

        register_rest_route('openscene/v1', '/posts/(?P<id>\d+)/vote', [
            ['methods' => 'POST', 'callback' => [$this->posts, 'vote'], 'permission_callback' => '__return_true'],
        ]);

        register_rest_route('openscene/v1', '/posts/(?P<id>\d+)/report', [
            ['methods' => 'POST', 'callback' => [$this->posts, 'report'], 'permission_callback' => fn(): bool => is_user_logged_in()],
        ]);

        register_rest_route('openscene/v1', '/posts/(?P<id>\d+)/save', [
            ['methods' => 'POST', 'callback' => [$this->savedPosts, 'save'], 'permission_callback' => fn(): bool => is_user_logged_in()],
            ['methods' => 'DELETE', 'callback' => [$this->savedPosts, 'unsave'], 'permission_callback' => fn(): bool => is_user_logged_in()],
        ]);

        register_rest_route('openscene/v1', '/posts/(?P<id>\d+)/clear-reports', [
            ['methods' => 'POST', 'callback' => [$this->posts, 'clearReports'], 'permission_callback' => fn(): bool => is_user_logged_in()],
        ]);

        register_rest_route('openscene/v1', '/events', [
            ['methods' => 'GET', 'callback' => [$this->events, 'list'], 'permission_callback' => '__return_true'],
            ['methods' => 'POST', 'callback' => [$this->events, 'create'], 'permission_callback' => fn(): bool => is_user_logged_in()],
        ]);

        register_rest_route('openscene/v1', '/events/(?P<id>\d+)', [
            ['methods' => 'GET', 'callback' => [$this->events, 'show'], 'permission_callback' => '__return_true'],
            ['methods' => 'PATCH', 'callback' => [$this->events, 'update'], 'permission_callback' => fn(): bool => is_user_logged_in()],
            ['methods' => 'DELETE', 'callback' => [$this->events, 'delete'], 'permission_callback' => fn(): bool => is_user_logged_in()],
        ]);

        register_rest_route('openscene/v1', '/posts/(?P<id>\d+)/lock', [
            ['methods' => 'POST', 'callback' => [$this->posts, 'lock'], 'permission_callback' => fn(): bool => is_user_logged_in()],
        ]);

        register_rest_route('openscene/v1', '/posts/(?P<id>\d+)/sticky', [
            ['methods' => 'POST', 'callback' => [$this->posts, 'sticky'], 'permission_callback' => fn(): bool => is_user_logged_in()],
        ]);

        register_rest_route('openscene/v1', '/communities', [
            ['methods' => 'GET', 'callback' => [$this->communities, 'list'], 'permission_callback' => '__return_true'],
        ]);

        register_rest_route('openscene/v1', '/communities/(?P<slug>[a-z0-9\-]+)', [
            ['methods' => 'GET', 'callback' => [$this->communities, 'show'], 'permission_callback' => '__return_true'],
        ]);

        register_rest_route('openscene/v1', '/communities/(?P<id>\d+)/posts', [
            ['methods' => 'GET', 'callback' => [$this->communities, 'posts'], 'permission_callback' => '__return_true'],
        ]);

        register_rest_route('openscene/v1', '/posts/(?P<id>\d+)/comments', [
            ['methods' => 'GET', 'callback' => [$this->comments, 'listTopLevel'], 'permission_callback' => '__return_true'],
            ['methods' => 'POST', 'callback' => [$this->comments, 'create'], 'permission_callback' => fn(): bool => is_user_logged_in()],
        ]);

        register_rest_route('openscene/v1', '/comments/(?P<id>\d+)', [
            ['methods' => 'DELETE', 'callback' => [$this->comments, 'delete'], 'permission_callback' => fn(): bool => is_user_logged_in()],
        ]);

        register_rest_route('openscene/v1', '/posts/(?P<post_id>\d+)/comments/(?P<id>\d+)/children', [
            ['methods' => 'GET', 'callback' => [$this->comments, 'listChildren'], 'permission_callback' => '__return_true'],
        ]);

        register_rest_route('openscene/v1', '/votes', [
            ['methods' => 'POST', 'callback' => [$this->votes, 'upsert'], 'permission_callback' => fn(): bool => is_user_logged_in()],
        ]);

        register_rest_route('openscene/v1', '/users/(?P<username>[A-Za-z0-9_\-.]+)', [
            ['methods' => 'GET', 'callback' => [$this->users, 'profile'], 'permission_callback' => '__return_true'],
        ]);

        register_rest_route('openscene/v1', '/users/(?P<username>[A-Za-z0-9_\-.]+)/posts', [
            ['methods' => 'GET', 'callback' => [$this->users, 'posts'], 'permission_callback' => '__return_true'],
        ]);

        register_rest_route('openscene/v1', '/users/(?P<username>[A-Za-z0-9_\-.]+)/comments', [
            ['methods' => 'GET', 'callback' => [$this->users, 'comments'], 'permission_callback' => '__return_true'],
        ]);

        register_rest_route('openscene/v1', '/users/(?P<username>[A-Za-z0-9_\-.]+)/saved', [
            ['methods' => 'GET', 'callback' => [$this->savedPosts, 'list'], 'permission_callback' => fn(): bool => is_user_logged_in()],
        ]);

        register_rest_route('openscene/v1', '/moderation/ban', [
            ['methods' => 'POST', 'callback' => [$this->moderation, 'ban'], 'permission_callback' => fn(): bool => is_user_logged_in()],
        ]);

        register_rest_route('openscene/v1', '/moderation/ban/(?P<id>\d+)', [
            ['methods' => 'DELETE', 'callback' => [$this->moderation, 'unban'], 'permission_callback' => fn(): bool => is_user_logged_in()],
        ]);

        register_rest_route('openscene/v1', '/moderation/logs', [
            ['methods' => 'GET', 'callback' => [$this->moderation, 'logs'], 'permission_callback' => fn(): bool => is_user_logged_in()],
        ]);

        register_rest_route('openscene/v1', '/moderation', [
            ['methods' => 'GET', 'callback' => [$this->moderation, 'list'], 'permission_callback' => fn(): bool => is_user_logged_in()],
        ]);
    }
}
