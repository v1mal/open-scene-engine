<?php

declare(strict_types=1);

namespace OpenScene\Engine\Http;

final class AdminAssets
{
    public function hooks(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public function enqueue(string $hookSuffix): void
    {
        if ($hookSuffix !== 'toplevel_page_openscene-engine' && $hookSuffix !== 'openscene_page_openscene-engine-settings') {
            return;
        }

        $path = OPENSCENE_ENGINE_PATH . 'build/assets/admin.css';
        if (is_readable($path)) {
            wp_enqueue_style('openscene-admin', OPENSCENE_ENGINE_URL . 'build/assets/admin.css', [], (string) filemtime($path));
        }
    }
}
