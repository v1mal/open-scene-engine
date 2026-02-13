<?php

declare(strict_types=1);

namespace OpenScene\Engine\Http\Rest;

use OpenScene\Engine\Infrastructure\Repository\VoteRepository;
use OpenScene\Engine\Infrastructure\RateLimit\RateLimiter;
use WP_REST_Request;
use WP_REST_Response;

final class VoteController extends BaseController
{
    public function __construct(
        RateLimiter $rateLimiter,
        private readonly VoteRepository $votes
    ) {
        parent::__construct($rateLimiter);
    }

    public function upsert(WP_REST_Request $request): WP_REST_Response
    {
        $ban = $this->requireNotBanned();
        if ($ban !== true) {
            return $this->errorResponse($ban);
        }

        $nonce = $this->verifyNonce();
        if ($nonce !== true) {
            return $this->errorResponse($nonce);
        }

        $limit = $this->requireRateLimit('vote', 120, MINUTE_IN_SECONDS);
        if ($limit !== true) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_rate_limited', 'message' => 'Vote rate limited']]], 429);
        }

        if (! $this->can('openscene_vote')) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_forbidden', 'message' => 'Not allowed']]], 403);
        }

        $targetType = sanitize_key((string) $request->get_param('target_type'));
        $targetId = (int) $request->get_param('target_id');
        $valueRaw = $request->get_param('value');
        $value = $valueRaw === null ? null : (int) $valueRaw;

        if (! in_array($targetType, ['post', 'comment'], true)) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_invalid_request', 'message' => 'Invalid vote target']]], 422);
        }

        $result = $this->votes->mutate(get_current_user_id(), $targetType, $targetId, $value);
        if (! $result['ok']) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_conflict', 'message' => 'Vote transaction failed']]], 500);
        }

        return new WP_REST_Response(['data' => ['delta' => $result['delta']]], 200);
    }
}
