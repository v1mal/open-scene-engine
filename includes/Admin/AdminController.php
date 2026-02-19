<?php

declare(strict_types=1);

namespace OpenScene\Engine\Admin;

use OpenScene\Engine\Infrastructure\Cache\CacheManager;
use OpenScene\Engine\Infrastructure\Database\MigrationRunner;
use OpenScene\Engine\Infrastructure\Database\TableNames;
use OpenScene\Engine\Infrastructure\Observability\IntegrityChecker;
use OpenScene\Engine\Infrastructure\Observability\ObservabilityLogger;
use OpenScene\Engine\Infrastructure\Repository\CommunityRepository;
use wpdb;

final class AdminController
{
    private const MENU_SLUG = 'openscene-engine';

    public function __construct(
        private readonly CommunityRepository $communities,
        private readonly TableNames $tables,
        private readonly CacheManager $cache,
        private readonly IntegrityChecker $integrityChecker,
        private readonly wpdb $wpdb
    ) {
    }

    public function hooks(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_init', [$this, 'handlePostRequest']);
    }

    public function registerMenu(): void
    {
        add_menu_page(
            'OpenScene Engine',
            'OpenScene',
            'manage_options',
            self::MENU_SLUG,
            [$this, 'renderPage'],
            'dashicons-format-chat',
            58
        );
    }

    public function enqueueAssets(string $hookSuffix): void
    {
        if ($hookSuffix !== 'toplevel_page_' . self::MENU_SLUG) {
            return;
        }

        $path = OPENSCENE_ENGINE_PATH . 'build/assets/admin.css';
        if (is_readable($path)) {
            wp_enqueue_style('openscene-admin', OPENSCENE_ENGINE_URL . 'build/assets/admin.css', [], (string) filemtime($path));
        }

        wp_enqueue_media();
    }

    public function renderPage(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'open-scene-engine'));
        }

        $tab = $this->activeTab();

        echo '<div class="wrap openscene-admin-wrap">';
        echo '<h1>' . esc_html__('OpenScene Engine', 'open-scene-engine') . '</h1>';
        $this->renderNotices();
        $this->renderTabs($tab);

        switch ($tab) {
            case 'settings':
                $this->renderSettingsTab();
                break;
            case 'communities':
                $this->renderCommunitiesTab();
                break;
            case 'analytics':
                $this->renderAnalyticsTab();
                break;
            case 'system':
                $this->renderSystemTab();
                break;
            case 'observability':
                $this->renderObservabilityTab();
                break;
            case 'overview':
            default:
                $this->renderOverviewTab();
                break;
        }

        echo '</div>';
    }

    public function handlePostRequest(): void
    {
        if (! is_admin()) {
            return;
        }

        $page = sanitize_key((string) ($_GET['page'] ?? ''));
        if ($page !== self::MENU_SLUG) {
            return;
        }

        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            return;
        }

        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'open-scene-engine'));
        }

        $tab = $this->activeTabFromRequest();
        $action = sanitize_key((string) ($_POST['openscene_admin_action'] ?? ''));
        if ($action === '') {
            return;
        }

        $error = '';
        switch ($action) {
            case 'save_settings':
                check_admin_referer('openscene_admin_save_settings');
                $this->saveSettings();
                break;
            case 'save_observability':
                check_admin_referer('openscene_admin_save_observability');
                $this->saveObservabilitySettings();
                break;
            case 'run_integrity_check':
                check_admin_referer('openscene_admin_run_integrity_check');
                $this->runIntegrityCheck();
                break;
            case 'community_add':
                check_admin_referer('openscene_admin_community_add');
                $error = $this->createCommunity();
                break;
            case 'community_edit':
                check_admin_referer('openscene_admin_community_edit');
                $error = $this->editCommunity();
                break;
            case 'community_toggle':
                check_admin_referer('openscene_admin_community_toggle');
                $error = $this->toggleCommunity();
                break;
            case 'community_delete':
                check_admin_referer('openscene_admin_community_delete');
                $error = $this->deleteCommunity();
                break;
            default:
                return;
        }

        $url = add_query_arg(
            [
                'page' => self::MENU_SLUG,
                'tab' => $tab,
                'updated' => $error === '' ? '1' : '0',
                'message' => $error !== '' ? rawurlencode($error) : null,
            ],
            admin_url('admin.php')
        );
        wp_safe_redirect($url);
        exit;
    }

    private function activeTab(): string
    {
        $tab = sanitize_key((string) ($_GET['tab'] ?? 'overview'));
        return $this->sanitizeTab($tab);
    }

    private function activeTabFromRequest(): string
    {
        $tab = sanitize_key((string) ($_REQUEST['tab'] ?? 'overview'));
        return $this->sanitizeTab($tab);
    }

    private function sanitizeTab(string $tab): string
    {
        $allowed = ['overview', 'settings', 'communities', 'analytics', 'system', 'observability'];
        return in_array($tab, $allowed, true) ? $tab : 'overview';
    }

    private function renderTabs(string $active): void
    {
        $tabs = [
            'overview' => 'Overview',
            'settings' => 'Platform Settings',
            'communities' => 'Communities',
            'analytics' => 'Analytics',
            'system' => 'System Health',
            'observability' => 'Observability',
        ];

        echo '<nav class="nav-tab-wrapper">';
        foreach ($tabs as $slug => $label) {
            $class = 'nav-tab' . ($active === $slug ? ' nav-tab-active' : '');
            $url = add_query_arg(['page' => self::MENU_SLUG, 'tab' => $slug], admin_url('admin.php'));
            echo '<a class="' . esc_attr($class) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';
    }

    private function renderNotices(): void
    {
        if (! isset($_GET['updated'])) {
            return;
        }

        $updated = (string) $_GET['updated'] === '1';
        $message = isset($_GET['message']) ? sanitize_text_field((string) wp_unslash($_GET['message'])) : '';
        if ($updated) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Settings saved.', 'open-scene-engine') . '</p></div>';
            return;
        }

        if ($message === '') {
            $message = __('Unable to complete action.', 'open-scene-engine');
        }
        echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }

    private function renderOverviewTab(): void
    {
        $stats = $this->overviewStats();
        $settingsUrl = add_query_arg(['page' => self::MENU_SLUG, 'tab' => 'settings'], admin_url('admin.php'));

        echo '<h2>' . esc_html__('Overview', 'open-scene-engine') . '</h2>';
        echo '<table class="widefat striped" style="max-width:760px"><tbody>';
        $this->summaryRow('Total Posts', (string) $stats['posts']);
        $this->summaryRow('Total Comments', (string) $stats['comments']);
        $this->summaryRow('Total Reports', (string) $stats['reports']);
        $this->summaryRow('Posts Today', (string) $stats['posts_today']);
        $this->summaryRow('Enabled Communities', (string) $stats['enabled_communities']);
        echo '</tbody></table>';
        echo '<p><a class="button button-primary" href="' . esc_url($settingsUrl) . '">' . esc_html__('Open Platform Settings', 'open-scene-engine') . '</a></p>';
    }

    private function renderSettingsTab(): void
    {
        $settings = $this->settings();
        $rules = (string) get_option('openscene_community_rules', '');
        $attachmentId = (int) ($settings['logo_attachment_id'] ?? 0);
        $logoUrl = $attachmentId > 0 ? (string) wp_get_attachment_image_url($attachmentId, 'thumbnail') : '';

        echo '<h2>' . esc_html__('Platform Settings', 'open-scene-engine') . '</h2>';
        echo '<form method="post" action="">';
        wp_nonce_field('openscene_admin_save_settings');
        echo '<input type="hidden" name="openscene_admin_action" value="save_settings" />';

        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row">' . esc_html__('Logo Override', 'open-scene-engine') . '</th><td>';
        echo '<input type="hidden" id="openscene_logo_attachment_id" name="openscene_logo_attachment_id" value="' . esc_attr((string) $attachmentId) . '" />';
        echo '<button type="button" class="button" id="openscene_logo_select">' . esc_html__('Select Logo', 'open-scene-engine') . '</button> ';
        echo '<button type="button" class="button" id="openscene_logo_clear">' . esc_html__('Clear', 'open-scene-engine') . '</button>';
        echo '<p class="description">' . esc_html__('Stored in openscene_admin_settings.logo_attachment_id.', 'open-scene-engine') . '</p>';
        echo '<div id="openscene_logo_preview" style="margin-top:8px;">';
        if ($logoUrl !== '') {
            echo '<img src="' . esc_url($logoUrl) . '" alt="" style="max-height:64px; width:auto;" />';
        }
        echo '</div></td></tr>';

        echo '<tr><th scope="row"><label for="openscene_brand_text">' . esc_html__('Text Logo', 'open-scene-engine') . '</label></th>';
        echo '<td><input name="openscene_brand_text" id="openscene_brand_text" type="text" class="regular-text" value="' . esc_attr((string) ($settings['brand_text'] ?? '')) . '" />';
        echo '<p class="description">' . esc_html__('Used when no logo image is configured. Leave empty to fallback to scene.wtf.', 'open-scene-engine') . '</p></td></tr>';

        echo '<tr><th scope="row"><label for="openscene_join_url">' . esc_html__('Join Link', 'open-scene-engine') . '</label></th>';
        echo '<td><input name="openscene_join_url" id="openscene_join_url" type="url" class="regular-text code" value="' . esc_attr((string) ($settings['join_url'] ?? '')) . '" /></td></tr>';

        $flags = (array) ($settings['feature_flags'] ?? []);
        echo '<tr><th scope="row">' . esc_html__('Feature Flags', 'open-scene-engine') . '</th><td>';
        $this->checkbox('openscene_feature_reporting', 'Reporting', ! empty($flags['reporting']));
        $this->checkbox('openscene_feature_voting', 'Voting', ! empty($flags['voting']));
        $this->checkbox('openscene_feature_delete', 'Delete', ! empty($flags['delete']));
        $this->checkbox('openscene_feature_saved_posts', 'Saved Posts', ! empty($flags['saved_posts']));
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="openscene_community_rules">' . esc_html__('Community Rules', 'open-scene-engine') . '</label></th>';
        echo '<td><textarea name="openscene_community_rules" id="openscene_community_rules" class="large-text" rows="8">' . esc_textarea($rules) . '</textarea></td></tr>';

        echo '</tbody></table>';
        submit_button(__('Save Settings', 'open-scene-engine'));
        echo '</form>';

        echo '<script>(function($){var frame;$("#openscene_logo_select").on("click",function(e){e.preventDefault();if(frame){frame.open();return;}frame=wp.media({title:"Select OpenScene Logo",button:{text:"Use Logo"},multiple:false});frame.on("select",function(){var a=frame.state().get("selection").first().toJSON();$("#openscene_logo_attachment_id").val(a.id||"");var u=(a.sizes&&a.sizes.thumbnail)?a.sizes.thumbnail.url:a.url;$("#openscene_logo_preview").html(u?\'<img src="\'+u+\'" alt="" style="max-height:64px;width:auto;" />\':"");});frame.open();});$("#openscene_logo_clear").on("click",function(e){e.preventDefault();$("#openscene_logo_attachment_id").val("");$("#openscene_logo_preview").empty();});})(jQuery);</script>';
    }

    private function renderCommunitiesTab(): void
    {
        $rows = $this->communities->listAllWithPostCounts();
        $editId = (int) ($_GET['edit'] ?? 0);
        $editRow = $editId > 0 ? $this->communities->findById($editId) : null;

        echo '<h2>' . esc_html__('Communities', 'open-scene-engine') . '</h2>';

        echo '<h3>' . esc_html($editRow ? __('Edit Community', 'open-scene-engine') : __('Add New Community', 'open-scene-engine')) . '</h3>';
        echo '<form method="post" action="">';
        if ($editRow) {
            wp_nonce_field('openscene_admin_community_edit');
            echo '<input type="hidden" name="openscene_admin_action" value="community_edit" />';
            echo '<input type="hidden" name="community_id" value="' . esc_attr((string) (int) ($editRow['id'] ?? 0)) . '" />';
        } else {
            wp_nonce_field('openscene_admin_community_add');
            echo '<input type="hidden" name="openscene_admin_action" value="community_add" />';
        }

        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="community_name">' . esc_html__('Name', 'open-scene-engine') . '</label></th><td><input class="regular-text" type="text" name="community_name" id="community_name" required value="' . esc_attr((string) ($editRow['name'] ?? '')) . '" /></td></tr>';
        echo '<tr><th scope="row"><label for="community_slug">' . esc_html__('Slug', 'open-scene-engine') . '</label></th><td><input class="regular-text code" type="text" name="community_slug" id="community_slug" required value="' . esc_attr((string) ($editRow['slug'] ?? '')) . '" /></td></tr>';
        echo '<tr><th scope="row"><label for="community_icon">' . esc_html__('Icon', 'open-scene-engine') . '</label></th><td><input class="regular-text code" type="text" name="community_icon" id="community_icon" value="' . esc_attr((string) ($editRow['icon'] ?? '')) . '" />';
        echo '<p class="description">' . esc_html__('Use a Lucide icon name, e.g. keyboard-music.', 'open-scene-engine') . '</p></td></tr>';
        echo '<tr><th scope="row"><label for="community_description">' . esc_html__('Description', 'open-scene-engine') . '</label></th><td><textarea class="large-text" rows="3" name="community_description" id="community_description">' . esc_textarea((string) ($editRow['description'] ?? '')) . '</textarea></td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Status', 'open-scene-engine') . '</th><td>';
        $enabled = ! $editRow || (string) ($editRow['visibility'] ?? 'public') === 'public';
        $this->checkbox('community_enabled', 'Enabled (public)', $enabled);
        echo '</td></tr>';
        echo '</tbody></table>';

        submit_button($editRow ? __('Save Community', 'open-scene-engine') : __('Add Community', 'open-scene-engine'));
        echo '</form>';

        echo '<hr/>';
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>ID</th><th>Name</th><th>Slug</th><th>Status</th><th>Posts</th><th>Actions</th>';
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $isEnabled = (string) ($row['visibility'] ?? 'public') === 'public';
            $editUrl = add_query_arg(['page' => self::MENU_SLUG, 'tab' => 'communities', 'edit' => $id], admin_url('admin.php'));
            echo '<tr>';
            echo '<td>' . esc_html((string) $id) . '</td>';
            echo '<td>' . esc_html((string) ($row['name'] ?? '')) . '</td>';
            echo '<td><code>' . esc_html((string) ($row['slug'] ?? '')) . '</code></td>';
            echo '<td>' . esc_html($isEnabled ? __('Enabled', 'open-scene-engine') : __('Disabled', 'open-scene-engine')) . '</td>';
            echo '<td>' . esc_html((string) (int) ($row['post_count'] ?? 0)) . '</td>';
            echo '<td style="display:flex;gap:8px;align-items:center;">';
            echo '<a class="button" href="' . esc_url($editUrl) . '">' . esc_html__('Edit', 'open-scene-engine') . '</a>';

            echo '<form method="post" action="" style="display:inline-block;">';
            wp_nonce_field('openscene_admin_community_toggle');
            echo '<input type="hidden" name="openscene_admin_action" value="community_toggle" />';
            echo '<input type="hidden" name="community_id" value="' . esc_attr((string) $id) . '" />';
            echo '<input type="hidden" name="community_enabled" value="' . ($isEnabled ? '0' : '1') . '" />';
            submit_button($isEnabled ? __('Disable', 'open-scene-engine') : __('Enable', 'open-scene-engine'), 'secondary small', '', false);
            echo '</form>';

            echo '<form method="post" action="" style="display:inline-block;" onsubmit="return confirm(\'Delete this community?\');">';
            wp_nonce_field('openscene_admin_community_delete');
            echo '<input type="hidden" name="openscene_admin_action" value="community_delete" />';
            echo '<input type="hidden" name="community_id" value="' . esc_attr((string) $id) . '" />';
            submit_button(__('Delete', 'open-scene-engine'), 'delete small', '', false);
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }
        if (empty($rows)) {
            echo '<tr><td colspan="6">' . esc_html__('No communities found.', 'open-scene-engine') . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private function renderAnalyticsTab(): void
    {
        $postsTable = $this->tables->posts();
        $commentsTable = $this->tables->comments();
        $votesTable = $this->tables->votes();

        $totalPosts = (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$postsTable}");
        $totalComments = (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$commentsTable}");
        $totalReports = (int) $this->wpdb->get_var("SELECT COALESCE(SUM(reports_count), 0) FROM {$postsTable}");
        $postsToday = (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$postsTable} WHERE DATE(created_at) = UTC_DATE()");
        $activeUsers = (int) $this->wpdb->get_var(
            "SELECT COUNT(DISTINCT user_id) FROM (
                SELECT user_id FROM {$postsTable} WHERE created_at >= (UTC_TIMESTAMP() - INTERVAL 24 HOUR)
                UNION ALL
                SELECT user_id FROM {$commentsTable} WHERE created_at >= (UTC_TIMESTAMP() - INTERVAL 24 HOUR)
                UNION ALL
                SELECT user_id FROM {$votesTable} WHERE updated_at >= (UTC_TIMESTAMP() - INTERVAL 24 HOUR)
            ) openscene_active_users"
        );

        echo '<h2>' . esc_html__('Analytics', 'open-scene-engine') . '</h2>';
        echo '<table class="widefat striped" style="max-width:760px"><tbody>';
        $this->summaryRow('Total Posts', (string) $totalPosts);
        $this->summaryRow('Total Comments', (string) $totalComments);
        $this->summaryRow('Total Reports', (string) $totalReports);
        $this->summaryRow('Posts Today', (string) $postsToday);
        $this->summaryRow('Active Users (24h)', (string) $activeUsers);
        echo '</tbody></table>';
    }

    private function renderSystemTab(): void
    {
        $storedDbVersion = (string) get_option('openscene_db_version', '0');
        $codeDbVersion = MigrationRunner::DB_VERSION;
        $cacheVersion = (string) get_option('openscene_cache_version', '1');
        $pageId = (int) get_option('openscene_page_id', 0);

        echo '<h2>' . esc_html__('System Health', 'open-scene-engine') . '</h2>';
        echo '<table class="widefat striped" style="max-width:760px"><tbody>';
        $this->summaryRow('DB Version (stored)', $storedDbVersion);
        $this->summaryRow('DB Version (code)', $codeDbVersion);
        $this->summaryRow('Migration Status', version_compare($storedDbVersion, $codeDbVersion, '>=') ? 'Up to date' : 'Pending');
        $this->summaryRow('Cache Version', $cacheVersion);
        $this->summaryRow('OpenScene Page ID', (string) $pageId);
        $this->summaryRow('REST Namespace', 'openscene/v1');
        echo '</tbody></table>';
    }

    private function renderObservabilityTab(): void
    {
        $settings = $this->settings();
        $mode = (string) ($settings['observability_mode'] ?? ObservabilityLogger::MODE_OFF);
        $table = $this->tables->observabilityLogs();
        $hasTable = (bool) $this->wpdb->get_var($this->wpdb->prepare('SHOW TABLES LIKE %s', $table));
        $snapshot = $this->observabilitySnapshot($hasTable);
        $lastIntegrity = get_option('openscene_observability_integrity_last', []);
        if (! is_array($lastIntegrity)) {
            $lastIntegrity = [];
        }

        echo '<h2>' . esc_html__('Observability', 'open-scene-engine') . '</h2>';

        echo '<h3>' . esc_html__('Mode', 'open-scene-engine') . '</h3>';
        echo '<form method="post" action="">';
        wp_nonce_field('openscene_admin_save_observability');
        echo '<input type="hidden" name="openscene_admin_action" value="save_observability" />';
        echo '<fieldset><legend class="screen-reader-text">' . esc_html__('Observability mode', 'open-scene-engine') . '</legend>';
        echo '<label style="display:block;margin-bottom:8px;">';
        echo '<input type="radio" name="openscene_observability_mode" value="off" ' . checked($mode, ObservabilityLogger::MODE_OFF, false) . ' /> ';
        echo esc_html__('Off', 'open-scene-engine');
        echo '</label>';
        echo '<label style="display:block;margin-bottom:8px;">';
        echo '<input type="radio" name="openscene_observability_mode" value="basic" ' . checked($mode, ObservabilityLogger::MODE_BASIC, false) . ' /> ';
        echo esc_html__('Basic', 'open-scene-engine');
        echo '</label>';
        echo '</fieldset>';
        submit_button(__('Save Observability', 'open-scene-engine'));
        echo '</form>';

        if ($mode === ObservabilityLogger::MODE_BASIC) {
            echo '<hr />';
            echo '<h3>' . esc_html__('Snapshot (last 24h)', 'open-scene-engine') . '</h3>';
            echo '<table class="widefat striped" style="max-width:760px"><tbody>';
            $this->summaryRow('Slow queries', (string) $snapshot['slow_queries']);
            $this->summaryRow('Mutation failures', (string) $snapshot['mutation_failures']);
            $this->summaryRow('DB Version', (string) get_option('openscene_db_version', '0'));
            $this->summaryRow('Cache Version', (string) get_option('openscene_cache_version', '1'));
            if (isset($lastIntegrity['result']) && is_array($lastIntegrity['result'])) {
                $result = (array) $lastIntegrity['result'];
                $this->summaryRow('Integrity: score drift', (string) (int) ($result['score_drift'] ?? 0));
                $this->summaryRow('Integrity: comment drift', (string) (int) ($result['comment_drift'] ?? 0));
                $this->summaryRow('Integrity: duplicate votes', (string) (int) ($result['duplicate_votes'] ?? 0));
                $this->summaryRow('Integrity: orphan comments', (string) (int) ($result['orphan_comments'] ?? 0));
            }
            echo '</tbody></table>';
        }

        echo '<h3>' . esc_html__('Integrity Check', 'open-scene-engine') . '</h3>';
        echo '<form method="post" action="">';
        wp_nonce_field('openscene_admin_run_integrity_check');
        echo '<input type="hidden" name="openscene_admin_action" value="run_integrity_check" />';
        submit_button(__('Run Integrity Check', 'open-scene-engine'), 'secondary');
        echo '</form>';
    }

    private function saveSettings(): void
    {
        $current = $this->settings();
        $settings = [
            'join_url' => esc_url_raw((string) wp_unslash($_POST['openscene_join_url'] ?? '')),
            'brand_text' => sanitize_text_field((string) wp_unslash($_POST['openscene_brand_text'] ?? '')),
            'observability_mode' => (string) ($current['observability_mode'] ?? ObservabilityLogger::MODE_OFF),
            'feature_flags' => [
                'reporting' => isset($_POST['openscene_feature_reporting']),
                'voting' => isset($_POST['openscene_feature_voting']),
                'delete' => isset($_POST['openscene_feature_delete']),
                'saved_posts' => isset($_POST['openscene_feature_saved_posts']),
            ],
            'logo_attachment_id' => max(0, (int) ($_POST['openscene_logo_attachment_id'] ?? 0)),
        ];
        update_option('openscene_admin_settings', $settings, false);
        update_option('openscene_join_url', $settings['join_url'], false);
        update_option('openscene_community_rules', sanitize_textarea_field((string) wp_unslash($_POST['openscene_community_rules'] ?? '')), false);

        if (($current['join_url'] ?? '') !== $settings['join_url']) {
            $this->cache->bumpVersion();
        }
    }

    private function saveObservabilitySettings(): void
    {
        $current = $this->settings();
        $mode = sanitize_key((string) wp_unslash($_POST['openscene_observability_mode'] ?? ObservabilityLogger::MODE_OFF));
        $mode = in_array($mode, [ObservabilityLogger::MODE_OFF, ObservabilityLogger::MODE_BASIC], true)
            ? $mode
            : ObservabilityLogger::MODE_OFF;

        $settings = [
            'join_url' => (string) ($current['join_url'] ?? ''),
            'brand_text' => (string) ($current['brand_text'] ?? ''),
            'observability_mode' => $mode,
            'feature_flags' => (array) ($current['feature_flags'] ?? []),
            'logo_attachment_id' => max(0, (int) ($current['logo_attachment_id'] ?? 0)),
        ];

        update_option('openscene_admin_settings', $settings, false);
    }

    private function runIntegrityCheck(): void
    {
        $result = $this->integrityChecker->run();
        update_option('openscene_observability_integrity_last', [
            'ran_at' => current_time('mysql', true),
            'result' => $result,
        ], false);
    }

    /** @return array{slow_queries:int,mutation_failures:int} */
    private function observabilitySnapshot(bool $hasTable): array
    {
        if (! $hasTable) {
            return [
                'slow_queries' => 0,
                'mutation_failures' => 0,
            ];
        }

        $table = $this->tables->observabilityLogs();
        $slowQueries = (int) $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} WHERE type = 'slow_query' AND created_at >= (UTC_TIMESTAMP() - INTERVAL 24 HOUR)"
        );
        $mutationFailures = (int) $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} WHERE type = 'mutation_failure' AND created_at >= (UTC_TIMESTAMP() - INTERVAL 24 HOUR)"
        );

        return [
            'slow_queries' => $slowQueries,
            'mutation_failures' => $mutationFailures,
        ];
    }

    private function createCommunity(): string
    {
        $name = sanitize_text_field((string) wp_unslash($_POST['community_name'] ?? ''));
        $slug = sanitize_title((string) wp_unslash($_POST['community_slug'] ?? ''));
        $icon = $this->sanitizeIconName((string) wp_unslash($_POST['community_icon'] ?? ''));
        $description = sanitize_textarea_field((string) wp_unslash($_POST['community_description'] ?? ''));
        $enabled = isset($_POST['community_enabled']);

        if ($name === '' || $slug === '') {
            return 'Name and slug are required.';
        }
        if ($this->communities->findBySlug($slug)) {
            return 'Community slug already exists.';
        }

        $now = current_time('mysql', true);
        $id = $this->communities->create([
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'icon' => $icon !== '' ? $icon : null,
            'rules' => null,
            'visibility' => $enabled ? 'public' : 'private',
            'created_by_user_id' => get_current_user_id(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($id <= 0) {
            return 'Failed to create community.';
        }

        $this->cache->bumpVersion();
        return '';
    }

    private function editCommunity(): string
    {
        $id = (int) ($_POST['community_id'] ?? 0);
        if ($id <= 0) {
            return 'Invalid community.';
        }
        $row = $this->communities->findById($id);
        if (! $row) {
            return 'Community not found.';
        }

        $name = sanitize_text_field((string) wp_unslash($_POST['community_name'] ?? ''));
        $slug = sanitize_title((string) wp_unslash($_POST['community_slug'] ?? ''));
        $icon = $this->sanitizeIconName((string) wp_unslash($_POST['community_icon'] ?? ''));
        $description = sanitize_textarea_field((string) wp_unslash($_POST['community_description'] ?? ''));
        $enabled = isset($_POST['community_enabled']);
        if ($name === '' || $slug === '') {
            return 'Name and slug are required.';
        }

        $existingSlug = $this->communities->findBySlug($slug);
        if ($existingSlug && (int) ($existingSlug['id'] ?? 0) !== $id) {
            return 'Community slug already exists.';
        }

        $ok = $this->communities->updateById($id, [
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'visibility' => $enabled ? 'public' : 'private',
            'icon' => $icon,
            'rules' => (string) ($row['rules'] ?? ''),
        ]);
        if (! $ok) {
            return 'Failed to update community.';
        }

        $this->cache->bumpVersion();
        return '';
    }

    private function toggleCommunity(): string
    {
        $id = (int) ($_POST['community_id'] ?? 0);
        $enabled = (string) ($_POST['community_enabled'] ?? '') === '1';
        if ($id <= 0) {
            return 'Invalid community.';
        }

        $ok = $this->communities->setEnabled($id, $enabled);
        if (! $ok) {
            return 'Failed to update status.';
        }

        $this->cache->bumpVersion();
        return '';
    }

    private function deleteCommunity(): string
    {
        $id = (int) ($_POST['community_id'] ?? 0);
        if ($id <= 0) {
            return 'Invalid community.';
        }

        $postCount = $this->communities->postCountForCommunity($id);
        if ($postCount > 0) {
            return 'Cannot delete community with existing posts.';
        }

        $ok = $this->communities->deleteById($id);
        if (! $ok) {
            return 'Failed to delete community.';
        }

        $this->cache->bumpVersion();
        return '';
    }

    private function overviewStats(): array
    {
        $postsTable = $this->tables->posts();
        $commentsTable = $this->tables->comments();

        return [
            'posts' => (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$postsTable}"),
            'comments' => (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$commentsTable}"),
            'reports' => (int) $this->wpdb->get_var("SELECT COALESCE(SUM(reports_count), 0) FROM {$postsTable}"),
            'posts_today' => (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$postsTable} WHERE DATE(created_at) = UTC_DATE()"),
            'enabled_communities' => $this->communities->countEnabled(),
        ];
    }

    private function settings(): array
    {
        $defaults = [
            'join_url' => (string) get_option('openscene_join_url', ''),
            'brand_text' => '',
            'observability_mode' => ObservabilityLogger::MODE_OFF,
            'feature_flags' => [
                'reporting' => true,
                'voting' => true,
                'delete' => true,
                'saved_posts' => false,
            ],
            'logo_attachment_id' => 0,
        ];
        $raw = get_option('openscene_admin_settings', []);
        if (! is_array($raw)) {
            $raw = [];
        }

        $flags = isset($raw['feature_flags']) && is_array($raw['feature_flags'])
            ? $raw['feature_flags']
            : [];

        return [
            'join_url' => isset($raw['join_url']) ? (string) $raw['join_url'] : $defaults['join_url'],
            'brand_text' => isset($raw['brand_text']) ? (string) $raw['brand_text'] : $defaults['brand_text'],
            'observability_mode' => in_array((string) ($raw['observability_mode'] ?? $defaults['observability_mode']), [ObservabilityLogger::MODE_OFF, ObservabilityLogger::MODE_BASIC], true)
                ? (string) ($raw['observability_mode'] ?? $defaults['observability_mode'])
                : $defaults['observability_mode'],
            'feature_flags' => [
                'reporting' => (bool) ($flags['reporting'] ?? $defaults['feature_flags']['reporting']),
                'voting' => (bool) ($flags['voting'] ?? $defaults['feature_flags']['voting']),
                'delete' => (bool) ($flags['delete'] ?? $defaults['feature_flags']['delete']),
                'saved_posts' => (bool) ($flags['saved_posts'] ?? $defaults['feature_flags']['saved_posts']),
            ],
            'logo_attachment_id' => max(0, (int) ($raw['logo_attachment_id'] ?? $defaults['logo_attachment_id'])),
        ];
    }

    private function summaryRow(string $label, string $value): void
    {
        echo '<tr><th scope="row" style="width:260px;">' . esc_html($label) . '</th><td>' . esc_html($value) . '</td></tr>';
    }

    private function checkbox(string $name, string $label, bool $checked): void
    {
        echo '<label style="display:block;margin-bottom:6px;">';
        echo '<input type="checkbox" name="' . esc_attr($name) . '" value="1" ' . checked($checked, true, false) . ' /> ';
        echo esc_html($label);
        echo '</label>';
    }

    private function sanitizeIconName(string $raw): string
    {
        $value = strtolower(trim(sanitize_text_field($raw)));
        if ($value === '') {
            return '';
        }

        return (string) preg_replace('/[^a-z0-9\-]/', '', $value);
    }
}
