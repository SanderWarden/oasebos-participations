<?php
/**
 * Plugin Name: Oasebos Participations
 * Description: Project-based participations, donations, recurring donations, Mollie payments, templates and PDF generation for Stichting Oasebos.
 * Version: 0.1.1
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: Stichting Oasebos
 * Text Domain: oasebos-participations
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('OASEBOS_PARTICIPATIONS_VERSION', '0.1.1');
define('OASEBOS_PARTICIPATIONS_FILE', __FILE__);
define('OASEBOS_PARTICIPATIONS_DIR', plugin_dir_path(__FILE__));
define('OASEBOS_PARTICIPATIONS_URL', plugin_dir_url(__FILE__));

$composer = OASEBOS_PARTICIPATIONS_DIR . 'vendor/autoload.php';
if (file_exists($composer)) {
    require_once $composer;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'Oasebos\\Participations\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = OASEBOS_PARTICIPATIONS_DIR . 'src/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

register_activation_hook(__FILE__, ['Oasebos\\Participations\\Activator', 'activate']);
register_deactivation_hook(__FILE__, ['Oasebos\\Participations\\Deactivator', 'deactivate']);

add_action('plugins_loaded', static function (): void {
    load_plugin_textdomain('oasebos-participations', false, dirname(plugin_basename(__FILE__)) . '/languages');
    Oasebos\Participations\Plugin::instance()->boot();
});
