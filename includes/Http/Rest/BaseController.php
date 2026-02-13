<?php

declare(strict_types=1);

namespace OpenScene\Engine\Http\Rest;

use OpenScene\Engine\Infrastructure\RateLimit\RateLimiter;
use WP_Error;

abstract class BaseController
{
    public function __construct(protected readonly RateLimiter $rateLimiter)
    {
    }

    protected function can(string $capability): bool
    {
        return current_user_can($capability);
    }

    protected function requireNotBanned(): true|WP_Error
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return true;
        }

        $isBanned = (bool) get_user_meta($userId, 'openscene_banned', true);
        if ($isBanned) {
            return new WP_Error('openscene_forbidden', 'Account is banned', ['status' => 403]);
        }

        return true;
    }

    protected function errorResponse(WP_Error $error): \WP_REST_Response
    {
        $status = 403;
        $data = $error->get_error_data();
        if (is_array($data) && isset($data['status'])) {
            $status = (int) $data['status'];
        }

        return new \WP_REST_Response([
            'errors' => [[
                'code' => $error->get_error_code(),
                'message' => $error->get_error_message(),
            ]],
        ], $status);
    }

    protected function requireRateLimit(string $bucket, int $limit, int $windowSeconds): true|WP_Error
    {
        $allowed = $this->rateLimiter->allow($bucket, $limit, $windowSeconds, get_current_user_id());
        if ($allowed) {
            return true;
        }

        return new WP_Error('openscene_rate_limited', 'Rate limit exceeded', ['status' => 429]);
    }

    protected function verifyNonce(): true|WP_Error
    {
        $nonce = isset($_SERVER['HTTP_X_WP_NONCE']) ? sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_X_WP_NONCE'])) : '';
        if (! wp_verify_nonce($nonce, 'wp_rest')) {
            return new WP_Error('openscene_invalid_nonce', 'Invalid nonce', ['status' => 403]);
        }

        return true;
    }
}
