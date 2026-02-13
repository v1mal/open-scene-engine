<?php

declare(strict_types=1);

namespace OpenScene\Engine\Http\Rest;

use OpenScene\Engine\Infrastructure\Repository\UserRepository;
use OpenScene\Engine\Infrastructure\RateLimit\RateLimiter;
use WP_REST_Request;
use WP_REST_Response;

final class UserController extends BaseController
{
    public function __construct(
        RateLimiter $rateLimiter,
        private readonly UserRepository $users
    ) {
        parent::__construct($rateLimiter);
    }

    public function profile(WP_REST_Request $request): WP_REST_Response
    {
        $username = sanitize_user((string) $request['username'], true);
        $user = $this->users->findByUsername($username);
        if (! $user) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_not_found', 'message' => 'User not found']]], 404);
        }

        return new WP_REST_Response(['data' => $user], 200);
    }

    public function posts(WP_REST_Request $request): WP_REST_Response
    {
        $username = sanitize_user((string) $request['username'], true);
        $user = $this->users->findByUsername($username);
        if (! $user) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_not_found', 'message' => 'User not found']]], 404);
        }

        $rows = $this->users->postsByUser((int) $user['id'], min(50, max(1, (int) $request->get_param('per_page') ?: 20)), max(0, (int) $request->get_param('offset')));
        return new WP_REST_Response(['data' => $rows], 200);
    }

    public function comments(WP_REST_Request $request): WP_REST_Response
    {
        $username = sanitize_user((string) $request['username'], true);
        $user = $this->users->findByUsername($username);
        if (! $user) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_not_found', 'message' => 'User not found']]], 404);
        }

        $rows = $this->users->commentsByUser((int) $user['id'], min(50, max(1, (int) $request->get_param('per_page') ?: 20)), max(0, (int) $request->get_param('offset')));
        return new WP_REST_Response(['data' => $rows], 200);
    }
}
