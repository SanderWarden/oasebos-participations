<?php
declare(strict_types=1);

namespace Oasebos\Participations\Admin;

use Oasebos\Participations\Security\Nonces;
use Oasebos\Participations\Services\ExportService;

final class ExportsPage extends BasePage
{
    public function render(): void
    {
        $this->guard();
        if (isset($_GET['export'])) {
            Nonces::verifyAdmin();
            (new ExportService())->stream(sanitize_key($_GET['export']));
        }

        $exports = ['projects' => 'Projecten', 'participations' => 'Participaties', 'donations' => 'Donaties', 'recurring_donations' => 'Periodieke donaties', 'payment_logs' => 'Betalingslogs', 'email_logs' => 'E-maillogs'];
        echo '<div class="wrap oasebos-admin-page oasebos-exports-page">';
        $this->header('Exports', 'Download CSV-bestanden voor administratie, boekhouding en rapportages.');
        echo '<div class="oasebos-export-grid">';
        foreach ($exports as $table => $label) {
            $url = wp_nonce_url(admin_url('admin.php?page=oasebos-exports&export=' . $table), Nonces::ADMIN_ACTION);
            $count = $this->repo->count($table);
            echo '<section class="oasebos-card oasebos-export-card"><h2>' . esc_html($label) . '</h2><div class="oasebos-card__body"><p><strong>' . esc_html((string) $count) . '</strong> records beschikbaar.</p><a class="button button-primary" href="' . esc_url($url) . '">CSV downloaden</a></div></section>';
        }
        echo '</div></div>';
    }
}
