<?php
declare(strict_types=1);

namespace Oasebos\Participations\Admin;

final class EmailsPage extends BasePage
{
    public function render(): void
    {
        $this->guard();
        $rows = $this->repo->list('email_logs', ['limit' => 100]);
        echo '<div class="wrap oasebos-admin-page oasebos-emails-page">';
        $this->header('E-mails', 'Controleer verzonden bevestigingen en eventuele mailfouten. Gebruik de detailacties bij donaties en participaties om bevestigingen opnieuw te versturen.');
        $this->card('Laatste e-mailactiviteiten', fn() => $this->table($rows));
        echo '</div>';
    }
}
