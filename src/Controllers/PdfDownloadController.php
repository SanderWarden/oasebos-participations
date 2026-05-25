<?php
declare(strict_types=1);

namespace Oasebos\Participations\Controllers;

use Oasebos\Participations\Database\Repository;

final class PdfDownloadController
{
    public function register(): void
    {
        add_action('admin_post_oasebos_download_pdf', [$this, 'download']);
    }

    public function download(): void
    {
        if (! current_user_can('manage_oasebos') && ! current_user_can('manage_options')) {
            wp_die(esc_html__('Je hebt geen toestemming om dit bestand te downloaden.', 'oasebos-participations'));
        }
        $id = absint($_GET['id'] ?? 0);
        check_admin_referer('oasebos_download_pdf_' . $id);
        $participation = (new Repository())->get('participations', $id);
        $path = (string) ($participation['pdf_path'] ?? '');
        $upload = wp_upload_dir(null, false);
        $base = realpath((string) ($upload['basedir'] ?? ''));
        $real = $path !== '' ? realpath($path) : false;
        if (! $participation || ! $real || ! $base || ! str_starts_with($real, $base) || ! is_readable($real)) {
            wp_die(esc_html__('De gevraagde PDF is niet beschikbaar.', 'oasebos-participations'));
        }

        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . basename($real) . '"');
        header('Content-Length: ' . filesize($real));
        readfile($real);
        exit;
    }
}
