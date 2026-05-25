<?php
declare(strict_types=1);
namespace Oasebos\Participations\Frontend;

final class Assets
{
    public function register(): void
    {
        add_action('wp_enqueue_scripts', function (): void {
            wp_enqueue_style('oasebos-frontend', OASEBOS_PARTICIPATIONS_URL . 'assets/frontend/css/frontend.css', [], OASEBOS_PARTICIPATIONS_VERSION);
            wp_enqueue_script('oasebos-frontend', OASEBOS_PARTICIPATIONS_URL . 'assets/frontend/js/frontend.js', [], OASEBOS_PARTICIPATIONS_VERSION, true);
        });

        add_action('admin_enqueue_scripts', function (string $hook): void {
            if (false === strpos($hook, 'oasebos')) {
                return;
            }
            wp_enqueue_media();
            wp_enqueue_style('oasebos-admin', OASEBOS_PARTICIPATIONS_URL . 'assets/admin/css/admin.css', [], OASEBOS_PARTICIPATIONS_VERSION);
            wp_enqueue_style('oasebos-frontend-preview', OASEBOS_PARTICIPATIONS_URL . 'assets/frontend/css/frontend.css', ['oasebos-admin'], OASEBOS_PARTICIPATIONS_VERSION);
            wp_enqueue_script('oasebos-admin', OASEBOS_PARTICIPATIONS_URL . 'assets/admin/js/admin.js', [], OASEBOS_PARTICIPATIONS_VERSION, true);
            wp_localize_script('oasebos-admin', 'oasebosAdmin', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce(\Oasebos\Participations\Security\Nonces::ADMIN_ACTION),
                'previewError' => __('Preview kon niet worden geladen.', 'oasebos-participations'),
            ]);
        });
    }
}
