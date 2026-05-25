<?php
declare(strict_types=1);

namespace Oasebos\Participations\Admin;

use Oasebos\Participations\Services\EmailService;

final class DonationsPage extends BasePage
{
    public function render(): void
    {
        $this->guard();

        $donations = $this->repo->list('donations', ['limit' => 100]);
        $selected = isset($_GET['view']) ? $this->repo->get('donations', absint($_GET['view'])) : null;

        echo '<div class="wrap oasebos-admin-page oasebos-donations-page">';
        echo '<div class="oasebos-page-header"><div><h1>Donaties</h1><p>Bekijk eenmalige donaties, betaalstatussen, donateurberichten en Mollie-referenties in een duidelijk overzicht.</p></div></div>';

        $this->renderFormPreview();
        $this->renderStats($donations);

        if ($selected) {
            $this->renderDetail($selected);
        }

        $this->renderList($donations);
        echo '</div>';
    }

    private function renderStats(array $donations): void
    {
        $total = count($donations);
        $paid = 0;
        $pending = 0;
        $failed = 0;
        $amount = 0.0;

        foreach ($donations as $donation) {
            $status = (string) ($donation['status'] ?? '');
            if ($status === 'paid') {
                $paid++;
                $amount += (float) $donation['amount'];
            } elseif ($status === 'pending') {
                $pending++;
            } elseif (in_array($status, ['failed', 'cancelled', 'expired'], true)) {
                $failed++;
            }
        }

        echo '<div class="oasebos-stat-grid">';
        $this->statCard('Totaal donaties', (string) $total, 'Alle eenmalige donaties.');
        $this->statCard('Betaald', (string) $paid, 'Bevestigde donaties.');
        $this->statCard('In afwachting', (string) $pending, 'Wacht op betalingsbevestiging.');
        $this->statCard('Aandacht vereist', (string) $failed, 'Mislukte, geannuleerde of verlopen betalingen.');
        $this->statCard('Betaald revenue', '€ ' . number_format_i18n($amount, 2), 'Brutobedrag van betaalde donaties.');
        echo '</div>';
    }

    private function renderFormPreview(): void
    {
        (new EmailService($this->repo))->ensureDefaultTemplates();

        echo '<section class="oasebos-card oasebos-shortcode-preview"><h2>Voorbeeld donatieformulier</h2><div class="oasebos-card__body">';
        echo '<p class="oasebos-muted">Dit is hetzelfde formulier dat bezoekers zien wanneer je <code>[oasebos_donation_form]</code> op een pagina plaatst.</p>';
        echo '<div class="oasebos-donation-preview-layout"><div class="oasebos-preview-frame">' . do_shortcode('[oasebos_donation_form]') . '</div>';
        $this->renderEmailTemplateList('Bijbehorende e-mailtemplates', 'Deze templates worden gebruikt bij statussen van eenmalige donaties.', $this->donationEmailTemplates());
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

    private function donationEmailTemplates(): array
    {
        return [
            'donation_paid' => 'Donatie betaald — naar donateur',
            'admin_donation_paid' => 'Donatie betaald — naar beheerder',
        ];
    }

    private function statCard(string $label, string $value, string $help): void
    {
        echo '<section class="oasebos-card oasebos-stat-card"><strong>' . esc_html($value) . '</strong><span>' . esc_html($label) . '</span><p>' . esc_html($help) . '</p></section>';
    }

    private function renderDetail(array $donation): void
    {
        echo '<section class="oasebos-card oasebos-participation-detail"><h2>Donatiegegevens</h2><div class="oasebos-card__body">';
        echo '<div class="oasebos-detail-header"><div><strong>' . esc_html($donation['donation_number']) . '</strong><span>' . esc_html($this->donorName($donation)) . '</span></div>' . $this->statusBadge((string) $donation['status']) . '</div>';
        echo '<div class="oasebos-detail-grid">';
        $this->detailItem('Donateur', $this->donorName($donation));
        $this->detailItem('Email', (string) $donation['donor_email']);
        $this->detailItem('Bedrag', (string) $donation['currency'] . ' ' . number_format_i18n((float) $donation['amount'], 2));
        $this->detailItem('Status', ucfirst((string) $donation['status']));
        $this->detailItem('Mollie-betaling', (string) ($donation['mollie_payment_id'] ?: '—'));
        $this->detailItem('Betaald at', (string) ($donation['paid_at'] ?: '—'));
        $this->detailItem('Aangemaakt', (string) $donation['created_at']);
        $this->detailItem('Bijgewerkt', (string) $donation['updated_at']);
        echo '</div>';

        $message = trim((string) ($donation['message'] ?? ''));
        echo '<div class="oasebos-message-box"><span>Donateur message</span><p>' . esc_html($message !== '' ? $message : 'Geen bericht opgegeven.') . '</p></div>';
        $resendUrl = wp_nonce_url(admin_url('admin-post.php?action=oasebos_resend_donation&id=' . absint($donation['id'])), 'oasebos_resend_donation_' . absint($donation['id']));
        echo '<div class="oasebos-detail-actions"><a class="button" href="' . esc_url(admin_url('admin.php?page=oasebos-donations')) . '">Details sluiten</a><a class="button" href="mailto:' . esc_attr($donation['donor_email']) . '">Donateur e-mailen</a><a class="button" href="' . esc_url($resendUrl) . '">Bevestiging opnieuw versturen</a></div>';
        echo '</div></section>';
    }

    private function detailItem(string $label, string $value): void
    {
        echo '<div class="oasebos-detail-item"><span>' . esc_html($label) . '</span><strong>' . esc_html($value !== '' ? $value : '—') . '</strong></div>';
    }

    private function renderList(array $donations): void
    {
        echo '<section class="oasebos-card oasebos-participations-list"><h2>Alle donaties</h2>';
        if (! $donations) {
            echo '<p class="oasebos-empty-state">Nog geen donaties. Eenmalige donaties verschijnen hier zodra een checkout is gestart.</p></section>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr><th>Donatie</th><th>Donateur</th><th>Status</th><th>Bedrag</th><th>Bericht</th><th>Betaald at</th><th>Aangemaakt</th><th></th></tr></thead><tbody>';
        foreach ($donations as $donation) {
            $viewUrl = admin_url('admin.php?page=oasebos-donations&view=' . absint($donation['id']));
            $message = trim((string) ($donation['message'] ?? ''));
            echo '<tr><td><strong>' . esc_html($donation['donation_number']) . '</strong><br><code>#' . esc_html((string) $donation['id']) . '</code></td>';
            echo '<td><strong>' . esc_html($this->donorName($donation)) . '</strong><br><a href="mailto:' . esc_attr($donation['donor_email']) . '">' . esc_html($donation['donor_email']) . '</a></td>';
            echo '<td>' . $this->statusBadge((string) $donation['status']) . '</td>';
            echo '<td>' . esc_html($donation['currency'] . ' ' . number_format_i18n((float) $donation['amount'], 2)) . '</td>';
            echo '<td>' . esc_html($message !== '' ? wp_html_excerpt($message, 80, '…') : '—') . '</td>';
            echo '<td>' . esc_html($donation['paid_at'] ? mysql2date(get_option('date_format'), (string) $donation['paid_at']) : '—') . '</td>';
            echo '<td>' . esc_html(mysql2date(get_option('date_format'), (string) $donation['created_at'])) . '</td>';
            echo '<td><a class="button button-small" href="' . esc_url($viewUrl) . '">Bekijken</a></td></tr>';
        }
        echo '</tbody></table></section>';
    }

    private function donorName(array $donation): string
    {
        return trim((string) $donation['donor_first_name'] . ' ' . (string) $donation['donor_last_name']) ?: 'Anonieme donateur';
    }

    private function statusBadge(string $status): string
    {
        return '<span class="oasebos-status oasebos-status--' . esc_attr($status) . '">' . esc_html(ucfirst(str_replace('_', ' ', $status))) . '</span>';
    }
}
