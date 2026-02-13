<?php

declare(strict_types=1);

/**
 * Plugin Name: OpenScene Engine
 * Description: Structured, high-density community discussion engine for WordPress.
 * Version: 0.1.0
 * Author: OpenScene
 * Requires PHP: 8.1
 * Text Domain: open-scene-engine
 */

if (! defined('ABSPATH')) {
    exit;
}

define('OPENSCENE_ENGINE_VERSION', '0.1.0');
define('OPENSCENE_ENGINE_FILE', __FILE__);
define('OPENSCENE_ENGINE_PATH', plugin_dir_path(__FILE__));
define('OPENSCENE_ENGINE_URL', plugin_dir_url(__FILE__));

require_once OPENSCENE_ENGINE_PATH . 'includes/Autoloader.php';

\OpenScene\Engine\Autoloader::register();

$plugin = new \OpenScene\Engine\Plugin();
$plugin->boot();

register_activation_hook(OPENSCENE_ENGINE_FILE, [\OpenScene\Engine\Plugin::class, 'activate']);
register_deactivation_hook(OPENSCENE_ENGINE_FILE, [\OpenScene\Engine\Plugin::class, 'deactivate']);
