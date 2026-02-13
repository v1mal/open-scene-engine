<?php

declare(strict_types=1);

namespace OpenScene\Engine\Http;

final class RegistrationGuard
{
    public function hooks(): void
    {
        add_action('login_init', [$this, 'blockRegistrationAction']);
        add_filter('register_url', [$this, 'registerUrlToLogin']);
        add_filter('pre_option_users_can_register', [$this, 'disablePublicRegistration']);
        add_filter('rest_endpoints', [$this, 'hidePublicUserCreationRoutes']);
    }

    public function blockRegistrationAction(): void
    {
        $action = isset($_REQUEST['action']) ? sanitize_key((string) wp_unslash($_REQUEST['action'])) : '';
        if ($action !== 'register') {
            return;
        }

        wp_safe_redirect(wp_login_url());
        exit;
    }

    public function registerUrlToLogin(string $url): string
    {
        return wp_login_url();
    }

    public function disablePublicRegistration(): string
    {
        return '0';
    }

    public function hidePublicUserCreationRoutes(array $endpoints): array
    {
        if (is_user_logged_in()) {
            return $endpoints;
        }

        foreach (['/wp/v2/users', '/wp/v2/users/(?P<id>[\\d]+)'] as $route) {
            if (! isset($endpoints[$route]) || ! is_array($endpoints[$route])) {
                continue;
            }

            $endpoints[$route] = array_values(array_filter(
                $endpoints[$route],
                fn($handler): bool => ! $this->isCreatableHandler($handler)
            ));

            if ($endpoints[$route] === []) {
                unset($endpoints[$route]);
            }
        }

        return $endpoints;
    }

    private function isCreatableHandler(mixed $handler): bool
    {
        if (! is_array($handler)) {
            return false;
        }

        $methods = $handler['methods'] ?? '';
        if (is_int($methods)) {
            return ($methods & \WP_REST_Server::CREATABLE) !== 0;
        }

        if (is_array($methods)) {
            $normalized = array_map(
                static fn($method): string => strtoupper((string) $method),
                $methods
            );
            return in_array('POST', $normalized, true);
        }

        return str_contains(strtoupper((string) $methods), 'POST');
    }
}
