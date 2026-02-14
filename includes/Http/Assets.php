<?php

declare(strict_types=1);

namespace OpenScene\Engine\Http;

final class Assets
{
    public function __construct(private readonly TemplateLoader $templateLoader)
    {
    }

    public function hooks(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
    }

    public function enqueue(): void
    {
        if (! $this->shouldLoad()) {
            return;
        }

        $cssPath = OPENSCENE_ENGINE_PATH . 'build/assets/app.css';
        $jsPath = OPENSCENE_ENGINE_PATH . 'build/assets/app.js';

        if (file_exists($cssPath)) {
            wp_enqueue_style('openscene-app', OPENSCENE_ENGINE_URL . 'build/assets/app.css', [], (string) filemtime($cssPath));
        }

        wp_enqueue_script('wp-api-fetch');
        wp_enqueue_script('wp-element');
        wp_enqueue_script('openscene-lucide', 'https://unpkg.com/lucide@latest/dist/umd/lucide.min.js', [], OPENSCENE_ENGINE_VERSION, true);

        if (file_exists($jsPath)) {
            $currentUser = wp_get_current_user();
            $username = ($currentUser instanceof \WP_User && $currentUser->ID > 0) ? strtolower(sanitize_user((string) $currentUser->user_login, true)) : '';
            $displayName = ($currentUser instanceof \WP_User && $currentUser->ID > 0) ? (string) $currentUser->display_name : '';
            $joinUrl = (string) get_option('openscene_join_url', '');
            $settings = get_option('openscene_admin_settings', []);
            $rawFlags = is_array($settings) && isset($settings['feature_flags']) && is_array($settings['feature_flags'])
                ? $settings['feature_flags']
                : [];
            $featureFlags = [
                'reporting' => (bool) ($rawFlags['reporting'] ?? true),
                'voting' => (bool) ($rawFlags['voting'] ?? true),
                'delete' => (bool) ($rawFlags['delete'] ?? true),
            ];
            $logoAttachmentId = is_array($settings) ? (int) ($settings['logo_attachment_id'] ?? 0) : 0;
            $logoUrl = $logoAttachmentId > 0 ? (string) wp_get_attachment_image_url($logoAttachmentId, 'full') : '';
            $brandText = is_array($settings) ? trim((string) ($settings['brand_text'] ?? '')) : '';
            wp_enqueue_script('openscene-app', OPENSCENE_ENGINE_URL . 'build/assets/app.js', ['wp-element', 'wp-api-fetch'], (string) filemtime($jsPath), true);
            wp_localize_script('openscene-app', 'OpenSceneConfig', [
                'restBase' => esc_url_raw(rest_url('openscene/v1')),
                'nonce' => wp_create_nonce('wp_rest'),
                'userId' => get_current_user_id(),
                'joinUrl' => esc_url_raw($joinUrl),
                'currentUser' => [
                    'username' => $username,
                    'displayName' => $displayName,
                ],
                'permissions' => [
                    'canDeleteAnyPost' => current_user_can('openscene_delete_any_post'),
                    'canModerate' => current_user_can('openscene_moderate'),
                    'canManageOptions' => current_user_can('manage_options'),
                ],
                'features' => $featureFlags,
                'branding' => [
                    'logoUrl' => esc_url_raw($logoUrl),
                    'brandText' => $brandText !== '' ? $brandText : null,
                ],
                'routeContext' => $this->templateLoader->routeContext(),
                'limits' => [
                    'maxThreadComments' => 500,
                    'maxDepth' => 6,
                    'childrenPerPage' => 20,
                ],
            ]);
        }
    }

    private function shouldLoad(): bool
    {
        if (get_query_var('openscene_route', '') !== '') {
            return true;
        }

        if (is_page((int) get_option('openscene_page_id', 0))) {
            return true;
        }

        if (is_page('openscene')) {
            return true;
        }

        $requestPath = trim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
        if (
            $requestPath === 'openscene'
            || str_starts_with($requestPath, 'openscene/')
            || $requestPath === 'search'
            || str_starts_with($requestPath, 'search/')
        ) {
            return true;
        }

        global $post;
        if ($post instanceof \WP_Post && has_shortcode($post->post_content, 'openscene_app')) {
            return true;
        }

        return false;
    }
}
