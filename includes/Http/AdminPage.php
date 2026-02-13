<?php

declare(strict_types=1);

namespace OpenScene\Engine\Http;

final class AdminPage
{
    public function hooks(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_init', [$this, 'registerSettings']);
    }

    public function registerMenu(): void
    {
        add_menu_page(
            'OpenScene Engine',
            'OpenScene',
            'manage_options',
            'openscene-engine',
            [$this, 'renderDashboard'],
            'dashicons-format-chat',
            58
        );

        add_submenu_page(
            'openscene-engine',
            'OpenScene Settings',
            'Settings',
            'manage_options',
            'openscene-engine-settings',
            [$this, 'renderSettings']
        );
    }

    public function registerSettings(): void
    {
        register_setting('openscene_settings', 'openscene_join_url', [
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default' => '',
        ]);

        add_settings_section(
            'openscene_membership',
            'Membership',
            function (): void {
                echo '<p>Configure membership entry behavior for OpenScene. No public registration is enabled in OpenScene MVP.</p>';
            },
            'openscene-engine-settings'
        );

        add_settings_field(
            'openscene_join_url',
            'Join URL',
            [$this, 'renderJoinUrlField'],
            'openscene-engine-settings',
            'openscene_membership'
        );
    }

    public function renderJoinUrlField(): void
    {
        $value = (string) get_option('openscene_join_url', '');
        echo '<input type="url" class="regular-text code" name="openscene_join_url" id="openscene_join_url" value="' . esc_attr($value) . '" placeholder="https://example.com/join" />';
        echo '<p class="description">Admin configures this URL for invite-interest signups. This link powers the JOIN button for logged-out users. OpenScene MVP does not expose public registration.</p>';
    }

    public function renderDashboard(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'open-scene-engine'));
        }

        echo '<div class="wrap openscene-admin-wrap"><h1>OpenScene Engine</h1>';
        echo '<p>Phase 1 baseline is active.</p>';
        echo '<ul><li>REST namespace: <code>openscene/v1</code></li><li>Shortcode: <code>[openscene_app]</code></li><li>OpenScene page ID: <code>' . esc_html((string) get_option('openscene_page_id', 0)) . '</code></li></ul>';
        echo '<p><a href="' . esc_url(admin_url('admin.php?page=openscene-engine-settings')) . '">Open Membership Settings</a></p>';
        echo '</div>';
    }

    public function renderSettings(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'open-scene-engine'));
        }

        echo '<div class="wrap openscene-admin-wrap"><h1>OpenScene Settings</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields('openscene_settings');
        do_settings_sections('openscene-engine-settings');
        submit_button('Save Settings');
        echo '</form>';
        echo '</div>';
    }
}
