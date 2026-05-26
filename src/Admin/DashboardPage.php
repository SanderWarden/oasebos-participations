<?php
declare(strict_types=1);

namespace Oasebos\Participations\Admin;

use Oasebos\Participations\Services\MollieService;

final class DashboardPage extends BasePage
{
    public function render(): void
    {
        $this->guard();
        $status = (new MollieService($this->repo))->status();
        $counts = [
            'Projecten' => ['projects', 'dashicons-palmtree', admin_url('admin.php?page=oasebos-projects')],
            'Participaties' => ['participations', 'dashicons-awards', admin_url('admin.php?page=oasebos-participations-list')],
            'Donaties' => ['donations', 'dashicons-heart', admin_url('admin.php?page=oasebos-donations')],
            'Periodiek' => ['recurring_donations', 'dashicons-update', admin_url('admin.php?page=oasebos-recurring-donations')],
            'Betalingslogs' => ['payment_logs', 'dashicons-list-view', admin_url('admin.php?page=oasebos-payment-logs')],
        ];

        echo '<div class="wrap oasebos-admin-page oasebos-dashboard-page">';
        $this->header('Oasebos dashboard', 'Beheer projecten, participaties, donaties, periodieke steun en operationele controle vanuit één overzicht.');
        echo '<p class="oasebos-version">Plugin versie: <strong>' . esc_html((string) OASEBOS_PARTICIPATIONS_VERSION) . '</strong></p>';
        echo '<div class="oasebos-dashboard-grid">';
        foreach ($counts as $label => [$table, $icon, $url]) {
            $count = $table === 'participations' ? $this->repo->count($table, 'is_test = 0') : $this->repo->count($table);
            echo '<a class="oasebos-dashboard-card" href="' . esc_url($url) . '"><span class="dashicons ' . esc_attr($icon) . '"></span><strong>' . esc_html((string) $count) . '</strong><em>' . esc_html($label) . '</em></a>';
        }
        echo '</div>';

        $this->card('Productiegereedheid', function () use ($status): void {
            echo '<ul class="oasebos-checklist">';
            $this->item((bool) $status['sdk'], 'Composer-afhankelijkheden geïnstalleerd', 'Mollie SDK en Dompdf zijn beschikbaar voor betalingen en PDF-generatie.');
            $this->item((bool) $status['api_key'], 'Mollie API-sleutel ingesteld', 'Voeg de sleutel toe via Oasebos → Instellingen.');
            $this->item((bool) $status['dompdf'], 'PDF-engine beschikbaar', 'Dompdf is vereist om echte PDF-bestanden te genereren.');
            $this->item(true, 'Webhookroute geregistreerd', 'Configureer deze URL in Mollie: ' . (string) $status['webhook_url']);
            echo '</ul><p class="oasebos-muted"><strong>Handmatige setup resterend:</strong> vul de Mollie-sleutel, afzendergegevens en organisatiegegevens in, maak actieve projecten/templates aan, plaats shortcodes op pagina’s en configureer de webhook-URL in Mollie.</p>';
        });
        echo '</div>';
    }

    private function item(bool $ok, string $label, string $help): void
    {
        echo '<li class="' . ($ok ? 'is-ok' : 'is-warning') . '"><strong>' . ($ok ? '✓' : '⚠') . ' ' . esc_html($label) . '</strong><span>' . esc_html($help) . '</span></li>';
    }
}
