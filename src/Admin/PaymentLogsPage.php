<?php
declare(strict_types=1);

namespace Oasebos\Participations\Admin;

final class PaymentLogsPage extends BasePage
{
    public function render(): void
    {
        $this->guard();
        $rows = $this->repo->list('payment_logs', ['limit' => 100]);
        echo '<div class="wrap oasebos-admin-page oasebos-logs-page">';
        $this->header('Betalingslogs', 'Bekijk Mollie-webhooks, betaalstatussen, foutmeldingen en technische payloads voor support en controle.');
        $this->card('Laatste betalingsgebeurtenissen', fn() => $this->table($rows));
        echo '</div>';
    }
}
