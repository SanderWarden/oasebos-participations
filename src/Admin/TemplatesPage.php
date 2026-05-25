<?php
declare(strict_types=1);

namespace Oasebos\Participations\Admin;

use Oasebos\Participations\Security\Nonces;
use Oasebos\Participations\Security\Sanitizer;
use Oasebos\Participations\Services\EmailService;
use Oasebos\Participations\Services\TemplateRenderer;

final class TemplatesPage extends BasePage
{
    private const OASEBOS_LOGO_URL = '/wp-content/uploads/2026/05/Oasebos-logo-01.jpg';
    private const ANBI_LOGO_URL = '/wp-content/uploads/2026/05/anbi-algemeen-nut-beogende-instelling-01-scaled.jpg';
    private const CBF_LOGO_URL = '/wp-content/uploads/2026/05/CBF22000_Erkend_GoedDoel_RGB-1-01.jpg';

    public function render(): void
    {
        $this->guard();
        (new EmailService($this->repo))->ensureDefaultTemplates();
        $renderer = new TemplateRenderer();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Nonces::verifyAdmin();
            $action = Sanitizer::text('template_action', $_POST, 'save');
            $id = Sanitizer::int('id', $_POST);

            if ('delete' === $action && $id) {
                $this->repo->delete('templates', $id);
                $this->notice('Template verwijderd.');
            } else {
                $data = ['type' => Sanitizer::text('type', $_POST, 'agreement'), 'name' => Sanitizer::text('name', $_POST), 'subject' => Sanitizer::text('subject', $_POST), 'content' => Sanitizer::html('content', $_POST), 'css' => Sanitizer::textarea('css', $_POST), 'status' => Sanitizer::text('status', $_POST, 'active')];
                $id ? $this->repo->update('templates', $id, $data) : $this->repo->insert('templates', $data);
                $this->notice('Template opgeslagen.');
            }
        }
        $edit = isset($_GET['edit']) ? $this->repo->get('templates', absint($_GET['edit'])) : [];
        $defaultCertificateContent = $this->defaultCertificateContent();
        $defaultCertificateCss = $this->defaultCertificateCss();
        $templates = $this->repo->list('templates', ['limit' => 100]);
        echo '<div class="wrap oasebos-admin-page oasebos-templates-page">';
        $this->header($edit ? 'Template bewerken' : 'Template maken', 'Beheer teksten voor overeenkomsten, certificaten en e-mails met dynamische tags.', ['Nieuwe template' => admin_url('admin.php?page=oasebos-templates')]);
        echo '<form method="post" class="oasebos-edit-layout" data-oasebos-template-form data-oasebos-default-certificate-content="' . esc_attr($defaultCertificateContent) . '" data-oasebos-default-certificate-css="' . esc_attr($defaultCertificateCss) . '" action="' . esc_url(admin_url('admin.php?page=oasebos-templates')) . '">';
        wp_nonce_field(Nonces::ADMIN_ACTION);
        if ($edit) { echo '<input type="hidden" name="id" value="' . esc_attr((string) $edit['id']) . '">'; }
        echo '<main class="oasebos-edit-main">';
        $this->card('Template-inhoud', function () use ($edit, $defaultCertificateContent, $defaultCertificateCss): void {
            $isNew = ! $edit;
            $contentValue = (string) ($edit['content'] ?? $defaultCertificateContent);
            $cssValue = (string) ($edit['css'] ?? $defaultCertificateCss);
            echo '<div class="oasebos-field-grid oasebos-field-grid--two"><label class="oasebos-field"><span>Type</span><select name="type">';
            foreach (['agreement' => 'Overeenkomst', 'certificate' => 'Certificaat', 'email' => 'E-mail'] as $type => $label) { echo '<option value="' . esc_attr($type) . '" ' . selected($edit['type'] ?? 'certificate', $type, false) . '>' . esc_html($label) . '</option>'; }
            echo '</select></label><label class="oasebos-field"><span>Status</span><select name="status"><option value="active" ' . selected($edit['status'] ?? 'active', 'active', false) . '>Actief</option><option value="draft" ' . selected($edit['status'] ?? '', 'draft', false) . '>Concept</option></select></label></div>';
            echo '<label class="oasebos-field"><span>Naam</span><input class="regular-text" name="name" value="' . esc_attr($edit['name'] ?? ($isNew ? 'Standaard certificaat' : '')) . '" required></label>';
            echo '<label class="oasebos-field"><span>Onderwerp</span><input class="regular-text" name="subject" value="' . esc_attr($edit['subject'] ?? '') . '"><em>Alleen nodig voor e-mailtemplates.</em></label>';
            echo '<p><button type="button" class="button" data-oasebos-reset-certificate-template>Reset naar basis certificaattemplate</button></p>';
            echo '<label class="oasebos-field"><span>Inhoud</span><textarea class="large-text code" rows="14" name="content">' . esc_textarea($contentValue) . '</textarea></label>';
            echo '<label class="oasebos-field"><span>CSS</span><textarea class="large-text code" rows="5" name="css">' . esc_textarea($cssValue) . '</textarea><em>Optioneel, vooral nuttig voor PDF-opmaak.</em></label>';
        });
        $this->card('Handtekening toevoegen', function (): void {
            echo '<p class="oasebos-muted">De Oasebos-, ANBI- en CBF-logo’s staan vast in de certificaattemplate. Kies hier alleen een handtekening of plak handmatig een URL. Klik daarna op “Toepassen” om de handtekening in de template-inhoud te plaatsen.</p>';
            echo '<div class="oasebos-template-image-fields">';
            foreach ([
                'signature' => 'Handtekening',
            ] as $key => $label) {
                echo '<label class="oasebos-field oasebos-template-image-field"><span>' . esc_html($label) . '</span><div class="oasebos-template-image-input"><input type="url" data-oasebos-template-image-url="' . esc_attr($key) . '" placeholder="https://..."><button type="button" class="button" data-oasebos-template-image-pick="' . esc_attr($key) . '">+ Kies</button><button type="button" class="button" data-oasebos-template-image-apply="' . esc_attr($key) . '">Toepassen</button></div></label>';
            }
            echo '</div>';
        });
        $this->card(($edit['type'] ?? '') === 'email' ? 'E-mailpreview' : 'Live PDF-preview', function () use ($edit, $renderer): void {
            $templateType = (string) ($edit['type'] ?? 'certificate');
            $initialContent = (string) ($edit['content'] ?? $this->defaultCertificateContent());
            $initialCss = (string) ($edit['css'] ?? $this->defaultCertificateCss());
            echo '<div class="oasebos-template-preview-toolbar"><p class="oasebos-muted">' . esc_html('email' === $templateType ? 'Preview met voorbeeldgegevens voor deze e-mailtemplate.' : 'Preview met voorbeeldgegevens. De weergave gebruikt A4-verhoudingen; gebruik “Test-PDF openen” voor de exacte Dompdf-uitvoer.') . '</p>';
            if ('email' !== $templateType) {
                echo '<div class="oasebos-template-preview-actions"><button type="submit" class="button" formtarget="_blank" formaction="' . esc_url(admin_url('admin-post.php?action=oasebos_template_pdf_preview')) . '">Test-PDF openen</button>';
                if ('certificate' === $templateType) {
                    echo '<button type="submit" class="button button-primary" formtarget="_blank" formaction="' . esc_url(admin_url('admin-post.php?action=oasebos_certificate_pdf_export')) . '">Certificaat exporteren naar PDF</button>';
                }
                echo '</div>';
            }
            echo '</div>';
            echo '<div class="oasebos-template-preview-status" data-oasebos-template-preview-status aria-live="polite"></div>';
            echo '<div class="oasebos-pdf-preview-shell"><div class="oasebos-pdf-preview-page" data-oasebos-template-preview-page><style data-oasebos-template-preview-style>' . esc_html($initialCss) . '</style><div data-oasebos-template-preview-title class="oasebos-pdf-preview-title">Voorbeeld</div><div data-oasebos-template-preview-content>' . $renderer->render($initialContent, $renderer->sampleContext(), 'pdf') . '</div></div></div>';
        });
        echo '</main><aside class="oasebos-edit-sidebar">';
        $this->card('Opslaan', function (): void { submit_button('Template opslaan', 'primary large', 'submit', false); }, 'oasebos-sticky-card');
        $this->card('Beschikbare tags', function (): void {
            echo '<div class="oasebos-tag-cloud"><code>[participant_full_name]</code><code>[project_name]</code><code>[units]</code><code>[forest_piece_label]</code><code>[total_hectares]</code><code>[total_amount]</code><code>[donor_full_name]</code><code>[donation_amount]</code><code>[organization_name]</code></div>';
        });
        $this->card('E-mailtemplates', function (): void {
            echo '<div class="oasebos-template-purpose-list">';
            foreach ($this->emailTemplateGroups() as $groupLabel => $templates) {
                echo '<h3>' . esc_html($groupLabel) . '</h3><ul>';
                foreach ($templates as $templateKey => $purpose) {
                    $template = $this->repo->findBy('templates', 'name', $templateKey);
                    $editUrl = $template ? admin_url('admin.php?page=oasebos-templates&edit=' . absint($template['id'])) : admin_url('admin.php?page=oasebos-templates');
                    echo '<li><span>' . esc_html($purpose) . '</span><code>[' . esc_html($templateKey) . ']</code><a class="button button-small" href="' . esc_url($editUrl) . '">Bewerken</a></li>';
                }
                echo '</ul>';
            }
            echo '</div>';
        });
        echo '</aside></form>';
        $this->renderList($templates);
        echo '</div>';
    }

    private function emailTemplateGroups(): array
    {
        return [
            'Participaties' => [
                'participation_paid' => 'Participatie betaald — naar deelnemer',
                'participation_gift_buyer' => 'Cadeauparticipatie betaald — naar koper',
                'participation_gift_recipient' => 'Cadeauparticipatie ontvangen — naar ontvanger',
                'admin_participation_paid' => 'Participatie betaald — naar beheerder',
            ],
            'Eenmalige donatie' => [
                'donation_paid' => 'Donatie betaald — naar donateur',
                'admin_donation_paid' => 'Donatie betaald — naar beheerder',
            ],
            'Periodieke donatie' => [
                'recurring_started' => 'Periodieke donatie gestart — naar donateur',
                'recurring_cancelled' => 'Periodieke donatie stopgezet — naar donateur',
                'recurring_failed' => 'Periodieke donatie mislukt — naar donateur',
                'admin_recurring_started' => 'Periodieke donatie gestart — naar beheerder',
            ],
        ];
    }

    private function renderList(array $templates): void
    {
        $this->card('Bestaande templates', function () use ($templates): void {
            if (! $templates) { echo '<p class="oasebos-empty-state">Nog geen templates. Maak hierboven je eerste template.</p>'; return; }
            echo '<div class="oasebos-table-wrap"><table class="widefat striped oasebos-table"><thead><tr><th>Naam</th><th>Type</th><th>Status</th><th>Bijgewerkt</th><th>Acties</th></tr></thead><tbody>';
            foreach ($templates as $template) {
                $url = admin_url('admin.php?page=oasebos-templates&edit=' . absint($template['id']));
                echo '<tr><td><strong>' . esc_html($template['name']) . '</strong><br><span class="oasebos-muted">' . esc_html($template['subject'] ?: 'Geen onderwerp') . '</span></td><td>' . esc_html($template['type']) . '</td><td>' . esc_html($template['status']) . '</td><td>' . esc_html(mysql2date(get_option('date_format'), (string) $template['updated_at'])) . '</td><td><div class="oasebos-row-actions"><a class="button button-small" href="' . esc_url($url) . '">Bewerken</a><form method="post" action="' . esc_url(admin_url('admin.php?page=oasebos-templates')) . '" onsubmit="return window.confirm(&quot;Weet je zeker dat je deze template wilt verwijderen?&quot;);">';
                wp_nonce_field(Nonces::ADMIN_ACTION);
                echo '<input type="hidden" name="template_action" value="delete"><input type="hidden" name="id" value="' . esc_attr((string) $template['id']) . '"><button type="submit" class="button button-small button-link-delete">Verwijderen</button></form></div></td></tr>';
            }
            echo '</tbody></table></div>';
        });
    }

    private function defaultCertificateContent(): string
    {
        return '<div class="certificate-page">
  <div class="circle circle-green-top"></div><div class="circle circle-green-right"></div><div class="circle circle-green-left"></div><div class="circle circle-green-bottom-left"></div><div class="circle circle-pink-bottom"></div>
    <div class="certificate-card">
    <div class="circle circle-orange"></div>
    <div class="logo-placeholder has-image"><img src="' . self::OASEBOS_LOGO_URL . '" alt="Oasebos" width="190" height="96"></div>
    <h1 class="certificate-title">Certificaat van<br>Regenwoudbescherming</h1>
    <div class="certificate-copy"><p>Met dit certificaat bedanken wij jou, [participant_full_name], voor je bijdrage aan de bescherming van het regenwoud via [organization_name].</p><p>Door jouw deelname aan [project_name] help je mee om waardevolle natuur duurzaam te beschermen. Jouw bijdrage ondersteunt biodiversiteit, klimaat en de toekomst van het regenwoud.</p><p>Dankzij jouw steun worden [forest_piece_label] regenwoud beschermd, samen goed voor [total_hectares] hectare duurzaam beschermd regenwoud.</p><p>Met je bijdrage van [total_amount] draag je direct bij aan blijvende bescherming van dit gebied. Namens het regenwoud, de bewoners en iedereen die zich inzet voor dit project: hartelijk dank.</p></div>
    <div class="thanks-line">Dankzij jouw bijdrage ben jij,</div><div class="recipient">[participant_full_name]</div>
    <p class="ownership">mede-beschermer van [total_hectares] ha duurzaam beschermd regenwoud<br>in project [project_name]. Dank!</p>
    <div class="signature-section"><p>Namens [organization_name] en alle inwoners van het regenwoud,</p><p>Peter Mols, Voorzitter</p><div class="signature-placeholder">Signature SVG<br>placeholder</div><div class="signature-line"></div></div>
    <div class="quality-marks"><div class="mark-placeholder anbi has-image"><img src="' . self::ANBI_LOGO_URL . '" alt="ANBI" width="83" height="64"></div><div class="mark-placeholder cbf has-image"><img src="' . self::CBF_LOGO_URL . '" alt="CBF" width="125" height="64"></div></div>
  </div>
</div>';
    }

    private function defaultCertificateCss(): string
    {
        return <<<'CSS'
@page {
  size: A4 portrait;
  margin: 0;
}

* {
  box-sizing: border-box;
}

html,
body {
  margin: 0;
  padding: 0;
  background: #ededed;
  color: #373334;
  font-family: DejaVu Sans, Arial, sans-serif;
}

body {
  font-size: 16px;
}

/* Admin preview reset: removes the plugin preview heading/line/inner whitespace */
.oasebos-pdf-preview-page {
  width: 794px !important;
  height: 1123px !important;
  min-height: 1123px !important;
  max-width: none !important;
  margin: 0 auto !important;
  padding: 0 !important;
  overflow: hidden !important;
  background: #ededed !important;
}

.oasebos-pdf-preview-title {
  display: none !important;
}

.oasebos-pdf-preview-page [data-oasebos-template-preview-content] {
  margin: 0 !important;
  padding: 0 !important;
  width: 794px !important;
  height: 1123px !important;
  overflow: hidden !important;
}

.certificate {
  margin: 0;
  padding: 0;
  background: #ededed;
}

.certificate-page {
  position: relative;
  width: 210mm;
  height: 297mm;
  margin: 0;
  padding: 20mm;
  overflow: hidden;
  background: #ededed;
}

.certificate-card {
  position: relative;
  z-index: 2;
  width: 170mm;
  height: 257mm;
  margin: 0;
  padding: 12mm 14mm 8mm;
  overflow: hidden;
  background: #ffffff;
  border-radius: 5mm;
  text-align: center;
}

.circle {
  position: absolute;
  z-index: 1;
  border-radius: 50%;
}

.circle-green-top {
  width: 34mm;
  height: 34mm;
  top: 6mm;
  right: -17mm;
  background: #8cc31b;
}

.circle-green-right {
  width: 42mm;
  height: 42mm;
  top: 56mm;
  right: -25mm;
  background: #8cc31b;
}

.circle-green-left {
  width: 43mm;
  height: 43mm;
  left: 8mm;
  bottom: 48mm;
  background: #8cc31b;
}

.circle-green-bottom-left {
  width: 25mm;
  height: 25mm;
  left: -17mm;
  bottom: 18mm;
  background: #8cc31b;
}

.circle-pink-bottom {
  width: 52mm;
  height: 52mm;
  left: 29mm;
  bottom: -16mm;
  background: #d90650;
}

.circle-orange {
  width: 21mm;
  height: 21mm;
  top: 14mm;
  right: 2mm;
  background: #f04a1a;
}

.logo-placeholder {
  width: 50mm;
  height: 25mm;
  margin: 0 auto 9mm;
  padding-top: 8mm;
  background: #d7d7d7;
  border: 0.5mm dashed #b7b7b7;
  border-radius: 2mm;
  color: #7d7d7d;
  font-size: 10px;
  line-height: 1.3;
  font-weight: bold;
  text-align: center;
  text-transform: uppercase;
}

.certificate-title {
  margin: 0 0 9mm;
  color: #373334;
  font-size: 29px;
  line-height: 1.2;
  font-weight: normal;
  letter-spacing: 0.2px;
  text-transform: uppercase;
}

.certificate-copy {
  width: 150mm;
  margin: 0 auto;
  font-size: 13px;
  line-height: 1.45;
  font-weight: normal;
}

.certificate-copy p {
  margin: 0 0 7mm;
}

.thanks-line {
  margin: 1mm 0 4mm;
  font-size: 13px;
  line-height: 1.4;
}

.recipient {
  width: 118mm;
  margin: 0 auto 7mm;
  padding-bottom: 1mm;
  border-bottom: 0.5mm dotted #656060;
  font-size: 25px;
  line-height: 1.18;
  font-weight: bold;
}

.ownership {
  width: 152mm;
  margin: 0 auto 7mm;
  font-size: 13px;
  line-height: 1.35;
}

.signature-section {
  margin-top: 2mm;
  font-size: 13px;
  line-height: 1.45;
}

.signature-section p {
  margin: 0 0 3mm;
}

.signature-placeholder {
  width: 72mm;
  height: 19mm;
  margin: 2mm auto 0;
  padding-top: 5mm;
  background: #d7d7d7;
  border: 0.5mm dashed #b7b7b7;
  border-radius: 2mm;
  color: #7d7d7d;
  font-size: 9px;
  line-height: 1.3;
  font-weight: bold;
  text-align: center;
  text-transform: uppercase;
}

.signature-line {
  width: 72mm;
  margin: 0 auto;
  border-bottom: 0.5mm dotted #656060;
}

.quality-marks {
  position: absolute;
  right: 8mm;
  bottom: 7mm;
  width: 58mm;
  height: 18mm;
}

.mark-placeholder {
  display: block;
  position: absolute;
  bottom: 0;
  padding-top: 5mm;
  background: #d7d7d7;
  border: 0.5mm dashed #b7b7b7;
  border-radius: 2mm;
  color: #7d7d7d;
  text-align: center;
  font-size: 8px;
  line-height: 1.2;
  font-weight: bold;
  text-transform: uppercase;
}

.quality-marks .mark-placeholder.anbi {
  left: 0;
  right: auto;
  width: 22mm;
  height: 17mm;
}

.quality-marks .mark-placeholder.cbf {
  left: auto;
  right: 0;
  width: 33mm;
  height: 17mm;
}

.logo-placeholder.has-image,
.signature-placeholder.has-image {
  padding: 0 !important;
  background: transparent !important;
  border: 0 !important;
  overflow: hidden !important;
  line-height: 0 !important;
  font-size: 0 !important;
  text-align: center !important;
}

.quality-marks .mark-placeholder.has-image {
  display: block !important;
  position: absolute !important;
  bottom: 0 !important;
  padding: 0 !important;
  background: transparent !important;
  border: 0 !important;
  overflow: hidden !important;
  line-height: 0 !important;
  font-size: 0 !important;
  text-align: center !important;
}

.quality-marks .mark-placeholder.anbi.has-image {
  left: 0 !important;
  right: auto !important;
  width: 22mm !important;
  height: 17mm !important;
}

.quality-marks .mark-placeholder.cbf.has-image {
  left: auto !important;
  right: 0 !important;
  width: 33mm !important;
  height: 17mm !important;
}

.logo-placeholder.has-image,
.signature-placeholder.has-image {
  display: table !important;
}

.logo-placeholder.has-image img,
.signature-placeholder.has-image img,
.quality-marks .mark-placeholder.has-image img {
  display: block !important;
  width: auto !important;
  height: auto !important;
  max-width: 100% !important;
  max-height: 100% !important;
  margin-left: auto !important;
  margin-right: auto !important;
  border: 0 !important;
}

.logo-placeholder.has-image img {
  max-width: 50mm !important;
  max-height: 25mm !important;
}

.signature-placeholder.has-image img {
  max-width: 72mm !important;
  max-height: 19mm !important;
}

.mark-placeholder.anbi.has-image img {
  max-width: 22mm !important;
  max-height: 17mm !important;
}

.mark-placeholder.cbf.has-image img {
  max-width: 33mm !important;
  max-height: 17mm !important;
}
CSS;
    }
}
