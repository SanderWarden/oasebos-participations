<?php
declare(strict_types=1);

namespace Oasebos\Participations\Admin;

use Oasebos\Participations\Services\EmailService;

final class RecurringDonationsPage extends BasePage
{
    public function render(): void
    {
        $this->guard();

        $subscriptions = $this->repo->list('recurring_donations', ['limit' => 100]);
        $selected = isset($_GET['view']) ? $this->repo->get('recurring_donations', absint($_GET['view'])) : null;

        echo '<div class="wrap oasebos-admin-page oasebos-recurring-donations-page">';
        echo '<div class="oasebos-page-header"><div><h1>Periodieke donaties</h1><p>Bekijk periodieke donaties, machtigingsstatussen, Mollie-referenties en maandelijkse steunwaarde.</p></div></div>';

        $this->renderFormPreview();
        $this->renderStats($subscriptions);

        if ($selected) {
            $this->renderDetail($selected);
        }

        $this->renderList($subscriptions);
        echo '</div>';
    }

    private function renderStats(array $subscriptions): void
    {
        $total = count($subscriptions);
        $active = 0;
        $pending = 0;
        $cancelled = 0;
        $monthlyBedrag = 0.0;

        foreach ($subscriptions as $subscription) {
            $status = (string) ($subscription['status'] ?? '');
            if ($status === 'active') {
                $active++;
                $monthlyBedrag += $this->monthlyEquivalent((float) $subscription['amount'], (string) $subscription['interval_value']);
            } elseif ($status === 'pending_mandate') {
                $pending++;
            } elseif (in_array($status, ['cancelled', 'failed', 'expired'], true)) {
                $cancelled++;
            }
        }

        echo '<div class="oasebos-stat-grid">';
        $this->statCard('Totaal periodiek', (string) $total, 'Alle periodieke donatie-abonnementen.');
        $this->statCard('Actief', (string) $active, 'Momenteel actieve periodieke donateurs.');
        $this->statCard('Machtiging in afwachting', (string) $pending, 'Wacht op machtiging of betalingsbevestiging.');
        $this->statCard('Inactief', (string) $cancelled, 'Geannuleerde, mislukte of verlopen records.');
        $this->statCard('Maandwaarde', '€ ' . number_format_i18n($monthlyBedrag, 2), 'Geschatte maandwaarde van actieve abonnementen.');
        echo '</div>';
    }

    private function renderFormPreview(): void
    {
        (new EmailService($this->repo))->ensureDefaultTemplates();

        echo '<section class="oasebos-card oasebos-shortcode-preview"><h2>Voorbeeld donatieformulier</h2><div class="oasebos-card__body">';
        echo '<p class="oasebos-muted">Maandelijkse donaties lopen via het gewone donatieformulier met de optie <strong>Maandelijkse donatie</strong>. Plaats <code>[oasebos_donation_form]</code> op een publieke pagina.</p>';
        echo '<div class="oasebos-donation-preview-layout"><div class="oasebos-preview-frame">' . do_shortcode('[oasebos_donation_form]') . '</div>';
        $this->renderEmailTemplateList('Bijbehorende e-mailtemplates', 'Deze templates worden gebruikt bij statussen van periodieke donaties.', $this->recurringEmailTemplates());
        echo '</aside></div>';
        echo '</div></section>';
    }

    private function renderEmailTemplateList(string $title, string $description, array $templates): void
    {
        echo '<aside class="oasebos-donation-email-list"><h3>' . esc_html($title) . '</h3><p class="oasebos-muted">' . esc_html($description) . '</p><ul>';
        foreach ($templates as $templateKey => $statusLabel) {
            $template = $this->repo->findBy('templates', 'name', $templateKey);
            $editUrl = $template ? admin_url('admin.php?page=oasebos-templates&edit=' . absint($template['id'])) : admin_url('admin.php?page=oasebos-templates');
            echo '<li><span>' . esc_html($statusLabel) . '</span><code>[' . esc_html($templateKey) . ']</code><a class="button button-small" href="' . esc_url($editUrl) . '">Bewerken</a></li>';
        }
        echo '</ul>';
    }

    private function recurringEmailTemplates(): array
    {
        return [
            'recurring_started' => 'Periodieke donatie gestart — naar donateur',
            'recurring_cancelled' => 'Periodieke donatie stopgezet — naar donateur',
            'recurring_failed' => 'Periodieke donatie mislukt — naar donateur',
            'admin_recurring_started' => 'Periodieke donatie gestart — naar beheerder',
        ];
    }

    private function statCard(string $label, string $value, string $help): void
    {
        echo '<section class="oasebos-card oasebos-stat-card"><strong>' . esc_html($value) . '</strong><span>' . esc_html($label) . '</span><p>' . esc_html($help) . '</p></section>';
    }

    private function renderDetail(array $subscription): void
    {
        echo '<section class="oasebos-card oasebos-participation-detail"><h2>Gegevens periodieke donatie</h2><div class="oasebos-card__body">';
        echo '<div class="oasebos-detail-header"><div><strong>' . esc_html($subscription['subscription_number']) . '</strong><span>' . esc_html($this->donorName($subscription)) . '</span></div>' . $this->statusBadge((string) $subscription['status']) . '</div>';
        echo '<div class="oasebos-detail-grid">';
        $this->detailItem('Donateur', $this->donorName($subscription));
        $this->detailItem('Email', (string) $subscription['donor_email']);
        $this->detailItem('Bedrag', (string) $subscription['currency'] . ' ' . number_format_i18n((float) $subscription['amount'], 2));
        $this->detailItem('Interval', (string) $subscription['interval_value']);
        $this->detailItem('Maandequivalent', (string) $subscription['currency'] . ' ' . number_format_i18n($this->monthlyEquivalent((float) $subscription['amount'], (string) $subscription['interval_value']), 2));
        $this->detailItem('Status', ucfirst(str_replace('_', ' ', (string) $subscription['status'])));
        $this->detailItem('Mollie-klant', (string) ($subscription['mollie_customer_id'] ?: '—'));
        $this->detailItem('Mollie-machtiging', (string) ($subscription['mollie_mandate_id'] ?: '—'));
        $this->detailItem('Mollie-abonnement', (string) ($subscription['mollie_subscription_id'] ?: '—'));
        $this->detailItem('Eerste betaling', (string) ($subscription['initial_payment_id'] ?: '—'));
        $this->detailItem('Gestart op', (string) ($subscription['started_at'] ?: '—'));
        $this->detailItem('Geannuleerd op', (string) ($subscription['cancelled_at'] ?: '—'));
        $this->detailItem('Aangemaakt', (string) $subscription['created_at']);
        $this->detailItem('Bijgewerkt', (string) $subscription['updated_at']);
        echo '</div>';
        $id = absint($subscription['id']);
        $resendUrl = wp_nonce_url(admin_url('admin-post.php?action=oasebos_resend_recurring&id=' . $id), 'oasebos_resend_recurring_' . $id);
        $syncUrl = wp_nonce_url(admin_url('admin-post.php?action=oasebos_sync_recurring&id=' . $id), 'oasebos_sync_recurring_' . $id);
        $cancelUrl = wp_nonce_url(admin_url('admin-post.php?action=oasebos_cancel_recurring&id=' . $id), 'oasebos_cancel_recurring_' . $id);
        echo '<div class="oasebos-detail-actions"><a class="button" href="' . esc_url(admin_url('admin.php?page=oasebos-recurring-donations')) . '">Details sluiten</a><a class="button" href="mailto:' . esc_attr($subscription['donor_email']) . '">Donateur e-mailen</a><a class="button" href="' . esc_url($resendUrl) . '">Bevestiging opnieuw versturen</a><a class="button" href="' . esc_url($syncUrl) . '">Mollie-status synchroniseren</a>';
        if (! in_array((string) $subscription['status'], ['cancelled', 'completed'], true)) {
            echo '<a class="button button-link-delete" href="' . esc_url($cancelUrl) . '">Abonnement annuleren</a>';
        }
        echo '</div>';
        echo '</div></section>';
    }

    private function detailItem(string $label, string $value): void
    {
        echo '<div class="oasebos-detail-item"><span>' . esc_html($label) . '</span><strong>' . esc_html($value !== '' ? $value : '—') . '</strong></div>';
    }

    private function renderList(array $subscriptions): void
    {
        echo '<section class="oasebos-card oasebos-participations-list"><h2>Alle periodieke donaties</h2>';
        if (! $subscriptions) {
            echo '<p class="oasebos-empty-state">Nog geen periodieke donaties. Abonnementen verschijnen hier zodra een donateur een periodieke checkout start.</p></section>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr><th>Abonnement</th><th>Donateur</th><th>Status</th><th>Bedrag</th><th>Interval</th><th>Maandwaarde</th><th>Gestart</th><th>Aangemaakt</th><th></th></tr></thead><tbody>';
        foreach ($subscriptions as $subscription) {
            $viewUrl = admin_url('admin.php?page=oasebos-recurring-donations&view=' . absint($subscription['id']));
            echo '<tr><td><strong>' . esc_html($subscription['subscription_number']) . '</strong><br><code>#' . esc_html((string) $subscription['id']) . '</code></td>';
            echo '<td><strong>' . esc_html($this->donorName($subscription)) . '</strong><br><a href="mailto:' . esc_attr($subscription['donor_email']) . '">' . esc_html($subscription['donor_email']) . '</a></td>';
            echo '<td>' . $this->statusBadge((string) $subscription['status']) . '</td>';
            echo '<td>' . esc_html($subscription['currency'] . ' ' . number_format_i18n((float) $subscription['amount'], 2)) . '</td>';
            echo '<td>' . esc_html((string) $subscription['interval_value']) . '</td>';
            echo '<td>' . esc_html($subscription['currency'] . ' ' . number_format_i18n($this->monthlyEquivalent((float) $subscription['amount'], (string) $subscription['interval_value']), 2)) . '</td>';
            echo '<td>' . esc_html($subscription['started_at'] ? mysql2date(get_option('date_format'), (string) $subscription['started_at']) : '—') . '</td>';
            echo '<td>' . esc_html(mysql2date(get_option('date_format'), (string) $subscription['created_at'])) . '</td>';
            echo '<td><a class="button button-small" href="' . esc_url($viewUrl) . '">Bekijken</a></td></tr>';
        }
        echo '</tbody></table></section>';
    }

    private function donorName(array $subscription): string
    {
        return trim((string) $subscription['donor_first_name'] . ' ' . (string) $subscription['donor_last_name']) ?: 'Anonieme donateur';
    }

    private function statusBadge(string $status): string
    {
        return '<span class="oasebos-status oasebos-status--' . esc_attr($status) . '">' . esc_html(ucfirst(str_replace('_', ' ', $status))) . '</span>';
    }

    private function monthlyEquivalent(float $amount, string $interval): float
    {
        $interval = strtolower(trim($interval));
        if (str_contains($interval, 'week')) {
            return $amount * 4.345;
        }
        if (str_contains($interval, 'year')) {
            return $amount / 12;
        }
        if (preg_match('/(\d+)\s*month/', $interval, $matches)) {
            return $amount / max(1, (int) $matches[1]);
        }
        return $amount;
    }
}
