<?php
declare(strict_types=1);

namespace Oasebos\Participations\Admin;

use Oasebos\Participations\Formatting;
use Oasebos\Participations\Security\Nonces;
use Oasebos\Participations\Services\EmailService;

final class ParticipationsPage extends BasePage
{
    public function render(): void
    {
        $this->guard();

        $participations = $this->repo->list('participations', ['limit' => 100]);
        $projects = $this->indexedProjects();
        $templates = $this->repo->list('templates', ['limit' => 200]);
        $selected = isset($_GET['view']) ? $this->repo->get('participations', absint($_GET['view'])) : null;

        echo '<div class="wrap oasebos-admin-page oasebos-participations-page">';
        echo '<div class="oasebos-page-header"><div><h1>Participaties</h1><p>Bekijk deelnemers, betaalstatus, hectares, documenten en projecttoewijzing in één duidelijk overzicht.</p></div></div>';

        $this->renderFormPreview();
        $this->renderStats($participations);

        if ($selected) {
            $this->renderDetail($selected, $projects[(int) $selected['project_id']] ?? [], $templates);
        }

        $this->renderList($participations, $projects);
        echo '</div>';
    }

    private function indexedProjects(): array
    {
        $indexed = [];
        foreach ($this->repo->list('projects', ['limit' => 500]) as $project) {
            $indexed[(int) $project['id']] = $project;
        }
        return $indexed;
    }

    private function renderStats(array $participations): void
    {
        $total = 0;
        $test = 0;
        $paid = 0;
        $pending = 0;
        $hectares = 0.0;
        $amount = 0.0;

        foreach ($participations as $participation) {
            if ($this->isTestParticipation($participation)) {
                $test++;
                continue;
            }
            $total++;
            if (($participation['status'] ?? '') === 'paid') {
                $paid++;
                $hectares += (float) $participation['total_hectares'];
                $amount += (float) $participation['total_amount'];
            } elseif (($participation['status'] ?? '') === 'pending') {
                $pending++;
            }
        }

        echo '<div class="oasebos-stat-grid">';
        $this->statCard('Totaal participaties', (string) $total, 'Alle echte participatieaanvragen. Testen zijn uitgesloten.');
        $this->statCard('Betaald', (string) $paid, 'Bevestigde en verwerkte participaties.');
        $this->statCard('In afwachting', (string) $pending, 'Wacht op betalingsbevestiging.');
        $this->statCard('Betaald hectares', Formatting::hectares($hectares), 'Totaal beschermde hectares uit betaalde participaties.');
        $this->statCard('Betaald revenue', '€ ' . number_format_i18n($amount, 2), 'Brutobedrag uit betaalde participaties.');
        $this->statCard('Testen', (string) $test, 'Testparticipaties zichtbaar in de lijst, maar uitgesloten van registry en totals.');
        echo '</div>';
    }

    private function renderFormPreview(): void
    {
        (new EmailService($this->repo))->ensureDefaultTemplates();

        echo '<section class="oasebos-card oasebos-shortcode-preview"><h2>Voorbeeld participatieformulier</h2><div class="oasebos-card__body">';
        echo '<p class="oasebos-muted">Dit is hetzelfde formulier dat bezoekers zien wanneer je <code>[oasebos_participation_form]</code> op een pagina plaatst.</p>';
        echo '<div class="oasebos-donation-preview-layout"><div class="oasebos-preview-frame">' . do_shortcode('[oasebos_participation_form]') . '</div>';
        $this->renderEmailTemplateList('Bijbehorende e-mailtemplates', 'Deze templates worden gebruikt bij statussen van participaties.', $this->participationEmailTemplates());
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

    private function participationEmailTemplates(): array
    {
        return [
            'participation_paid' => 'Participatie betaald — naar deelnemer',
            'participation_gift_buyer' => 'Cadeauparticipatie betaald — naar koper',
            'participation_gift_recipient' => 'Cadeauparticipatie ontvangen — naar ontvanger',
            'admin_participation_paid' => 'Participatie betaald — naar beheerder',
        ];
    }

    private function statCard(string $label, string $value, string $help): void
    {
        echo '<section class="oasebos-card oasebos-stat-card"><strong>' . esc_html($value) . '</strong><span>' . esc_html($label) . '</span><p>' . esc_html($help) . '</p></section>';
    }

    private function renderDetail(array $participation, array $project, array $templates): void
    {
        $pdf = (string) ($participation['pdf_path'] ?? '');
        $landUnits = $this->repo->landUnitsForParticipation((int) $participation['id']);
        echo '<section class="oasebos-card oasebos-participation-detail"><h2>Participatiegegevens</h2><div class="oasebos-card__body">';
        echo '<div class="oasebos-detail-header"><div><strong>' . esc_html($participation['participation_number']) . '</strong><span>' . esc_html($this->participantName($participation)) . '</span></div><div class="oasebos-badge-row">' . ($this->isTestParticipation($participation) ? $this->testBadge() : '') . $this->statusBadge((string) $participation['status']) . '</div></div>';
        if ($this->isTestParticipation($participation)) {
            echo '<p class="oasebos-test-notice">Testparticipatie: deze betaling is zichtbaar voor controle, maar telt niet mee voor registry, landnummers, projectbeschikbaarheid of totals.</p>';
        }
        echo '<div class="oasebos-detail-grid">';
        $this->detailItem('Project', (string) ($project['name'] ?? 'Onbekend project'));
        $this->detailItem('Email', (string) $participation['participant_email']);
        $this->detailItem('Telefoon', (string) ($participation['participant_phone'] ?: '—'));
        $this->detailItem('Adres', trim((string) $participation['participant_address'] . ', ' . (string) $participation['participant_postcode'] . ' ' . (string) $participation['participant_city'], ', '));
        $gift = $this->giftData($participation);
        if (! empty($gift['is_gift'])) {
            $this->detailItem('Cadeau voor', trim((string) ($gift['first_name'] ?? '') . ' ' . (string) ($gift['last_name'] ?? '')));
            $this->detailItem('E-mail ontvanger', (string) ($gift['email'] ?? '—'));
        }
        $this->detailItem('Eenheden', (string) $participation['units']);
        $this->detailItem('Hectares', Formatting::hectares((float) $participation['total_hectares']));
        $this->detailItem('Totaalbedrag', esc_html($participation['currency']) . ' ' . number_format_i18n((float) $participation['total_amount'], 2));
        $this->detailItem('Mollie-betaling', (string) ($participation['mollie_payment_id'] ?: '—'));
        $this->detailItem('Betaald at', (string) ($participation['paid_at'] ?: '—'));
        $this->detailItem('Aangemaakt', (string) $participation['created_at']);
        echo '</div>';
        $this->renderTemplateSelectors($participation, $templates);
        $this->renderLandUnits($landUnits, $this->isTestParticipation($participation));
        echo '<div class="oasebos-detail-actions"><a class="button" href="' . esc_url(admin_url('admin.php?page=oasebos-participations-list')) . '">Details sluiten</a>';
        if ($pdf !== '') {
            $downloadUrl = wp_nonce_url(admin_url('admin-post.php?action=oasebos_download_pdf&id=' . absint($participation['id'])), 'oasebos_download_pdf_' . absint($participation['id']));
            echo '<a class="button" href="' . esc_url($downloadUrl) . '">PDF downloaden</a><span class="oasebos-muted">PDF gegenereerd: ' . esc_html(basename($pdf)) . '</span>';
        } else {
            echo '<span class="oasebos-muted">Nog geen PDF gegenereerd.</span>';
        }
        $resendUrl = wp_nonce_url(admin_url('admin-post.php?action=oasebos_resend_participation&id=' . absint($participation['id'])), 'oasebos_resend_participation_' . absint($participation['id']));
        echo '<a class="button" href="' . esc_url($resendUrl) . '">Bevestiging opnieuw versturen</a>';
        echo '</div></div></section>';
    }

    private function renderTemplateSelectors(array $participation, array $templates): void
    {
        echo '<form class="oasebos-participation-template-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(Nonces::ADMIN_ACTION);
        echo '<input type="hidden" name="action" value="oasebos_update_participation_templates"><input type="hidden" name="id" value="' . esc_attr((string) $participation['id']) . '">';
        echo '<h3>Documenttemplates voor deze participatie</h3><p class="oasebos-muted">Kies welke overeenkomst en welk certificaat in de PDF-bijlage voor deze participatie worden gebruikt. Bij betaalde participaties wordt de PDF direct opnieuw gegenereerd.</p>';
        echo '<div class="oasebos-field-grid oasebos-field-grid--two">';
        $this->templateSelect('agreement_template_id', 'Participatieovereenkomst', (int) ($participation['agreement_template_id'] ?? 0), $templates, 'agreement');
        $this->templateSelect('certificate_template_id', 'Certificaat', (int) ($participation['certificate_template_id'] ?? 0), $templates, 'certificate');
        echo '</div><p><button type="submit" class="button button-primary">Documentkeuze opslaan</button></p></form>';
    }

    private function templateSelect(string $name, string $label, int $selected, array $templates, string $type): void
    {
        echo '<label class="oasebos-field"><span>' . esc_html($label) . '</span><select name="' . esc_attr($name) . '"><option value="0">— Standaard/fallback —</option>';
        foreach ($templates as $template) {
            if (($template['type'] ?? '') !== $type || ($template['status'] ?? '') !== 'active') {
                continue;
            }
            echo '<option value="' . esc_attr((string) $template['id']) . '" ' . selected($selected, (int) $template['id'], false) . '>' . esc_html($template['name']) . '</option>';
        }
        echo '</select></label>';
    }

    private function detailItem(string $label, string $value): void
    {
        echo '<div class="oasebos-detail-item"><span>' . esc_html($label) . '</span><strong>' . esc_html($value !== '' ? $value : '—') . '</strong></div>';
    }

    private function renderLandUnits(array $landUnits, bool $isTest = false): void
    {
        echo '<div class="oasebos-land-units"><h3>Gekoppelde landnummers</h3>';
        if (! $landUnits) {
            echo '<p class="oasebos-muted">' . esc_html($isTest ? 'Geen landnummers toegewezen: testparticipaties worden niet in de registry opgenomen.' : 'Nog geen landnummers toegewezen. Deze worden aangemaakt zodra de participatie betaald is.') . '</p></div>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr><th>#</th><th>Landnummer</th><th>Hectares</th><th>Status</th></tr></thead><tbody>';
        foreach ($landUnits as $unit) {
            echo '<tr><td>' . esc_html((string) $unit['unit_index']) . '</td><td><strong>' . esc_html((string) $unit['land_unit_number']) . '</strong></td><td>' . esc_html(Formatting::hectares((float) $unit['hectares'])) . '</td><td>' . $this->statusBadge((string) $unit['status']) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private function renderList(array $participations, array $projects): void
    {
        echo '<section class="oasebos-card oasebos-participations-list"><h2>Alle participaties</h2>';
        if (! $participations) {
            echo '<p class="oasebos-empty-state">Nog geen participaties. Nieuwe records verschijnen hier zodra een bezoeker een participatie-checkout start.</p></section>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr><th>Participatie</th><th>Deelnemer</th><th>Project</th><th>Status</th><th>Eenheden</th><th>Hectares</th><th>Bedrag</th><th>Aangemaakt</th><th></th></tr></thead><tbody>';
        foreach ($participations as $participation) {
            $project = $projects[(int) $participation['project_id']] ?? [];
            $viewUrl = admin_url('admin.php?page=oasebos-participations-list&view=' . absint($participation['id']));
            $isTest = $this->isTestParticipation($participation);
            echo '<tr class="' . esc_attr($isTest ? 'is-test-participation' : '') . '"><td><strong>' . esc_html($participation['participation_number']) . '</strong>' . ($isTest ? '<br>' . $this->testBadge() : '') . '<br><code>#' . esc_html((string) $participation['id']) . '</code></td>';
            echo '<td><strong>' . esc_html($this->participantName($participation)) . '</strong><br><a href="mailto:' . esc_attr($participation['participant_email']) . '">' . esc_html($participation['participant_email']) . '</a></td>';
            echo '<td>' . esc_html((string) ($project['name'] ?? 'Onbekend project')) . '</td>';
            echo '<td>' . $this->statusBadge((string) $participation['status']) . '</td>';
            echo '<td>' . esc_html((string) $participation['units']) . '</td>';
            echo '<td>' . esc_html(Formatting::hectares((float) $participation['total_hectares'])) . '</td>';
            echo '<td>' . esc_html($participation['currency'] . ' ' . number_format_i18n((float) $participation['total_amount'], 2)) . '</td>';
            echo '<td>' . esc_html(mysql2date(get_option('date_format'), (string) $participation['created_at'])) . '</td>';
            echo '<td><a class="button button-small" href="' . esc_url($viewUrl) . '">Bekijken</a></td></tr>';
        }
        echo '</tbody></table></section>';
    }

    private function participantName(array $participation): string
    {
        return trim((string) $participation['participant_first_name'] . ' ' . (string) $participation['participant_last_name']) ?: 'Onbekende deelnemer';
    }

    private function giftData(array $participation): array
    {
        $snapshot = json_decode((string) ($participation['project_snapshot'] ?? ''), true) ?: [];
        return is_array($snapshot['_gift'] ?? null) ? $snapshot['_gift'] : [];
    }

    private function statusBadge(string $status): string
    {
        return '<span class="oasebos-status oasebos-status--' . esc_attr($status) . '">' . esc_html(ucfirst(str_replace('_', ' ', $status))) . '</span>';
    }

    private function testBadge(): string
    {
        return '<span class="oasebos-status oasebos-status--test">Test</span>';
    }

    private function isTestParticipation(array $participation): bool
    {
        if (array_key_exists('is_test', $participation)) {
            return (int) $participation['is_test'] === 1;
        }

        $snapshot = json_decode((string) ($participation['project_snapshot'] ?? ''), true) ?: [];
        return ! empty($snapshot['_is_test']);
    }
}
