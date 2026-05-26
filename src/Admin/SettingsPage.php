<?php
declare(strict_types=1);

namespace Oasebos\Participations\Admin;

use Oasebos\Participations\Security\Nonces;

final class SettingsPage extends BasePage
{
    public function render(): void
    {
        if (! current_user_can('manage_options')) { wp_die('Geen toegang'); }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Nonces::verifyAdmin();
            foreach (['oasebos_mollie_api_key', 'oasebos_organization_name', 'oasebos_organization_address', 'oasebos_email_sender_name', 'oasebos_email_sender_address'] as $key) {
                update_option($key, sanitize_text_field(wp_unslash($_POST[$key] ?? '')));
            }
            foreach (['oasebos_participation_success_page_id', 'oasebos_donation_success_page_id', 'oasebos_recurring_donation_success_page_id'] as $key) {
                update_option($key, (string) absint($_POST[$key] ?? 0));
            }
            update_option('oasebos_participation_start_number', (string) max(1, absint($_POST['oasebos_participation_start_number'] ?? 1)));
            update_option('oasebos_mollie_test_mode', isset($_POST['oasebos_mollie_test_mode']) ? '1' : '0');
            $this->notice('Instellingen opgeslagen.');
        }

        $sdk = class_exists('\\Mollie\\Api\\MollieApiClient');
        $dompdf = class_exists('\\Dompdf\\Dompdf');
        echo '<div class="wrap oasebos-admin-page oasebos-settings-page">';
        $this->header('Oasebos-instellingen', 'Configureer betalingen, organisatiegegevens, afzendergegevens en publicatie-informatie.');
        echo '<form method="post" class="oasebos-edit-layout">';
        wp_nonce_field(Nonces::ADMIN_ACTION);
        echo '<main class="oasebos-edit-main">';
        $this->card('Mollie betalingen', function (): void {
            $this->field('oasebos_mollie_api_key', 'Mollie API-sleutel', 'Plak hier je test- of live API-sleutel uit het Mollie-dashboard.');
            echo '<label class="oasebos-field oasebos-checkbox"><input type="checkbox" name="oasebos_mollie_test_mode" value="1" ' . checked(get_option('oasebos_mollie_test_mode'), '1', false) . '> <span>Testmodus</span></label>';
            echo '<p class="description">Webhook-URL: <code>' . esc_html(rest_url('oasebos/v1/mollie-webhook')) . '</code></p>';
        });
        $this->card('Organisatie', function (): void {
            $this->field('oasebos_organization_name', 'Organisatienaam', 'Naam die in e-mails en documenten wordt gebruikt.');
            $this->field('oasebos_organization_address', 'Organisatieadres', 'Adresgegevens voor overeenkomsten en correspondentie.');
        });
        $this->card('E-mailafzender', function (): void {
            $this->field('oasebos_email_sender_name', 'Naam afzender e-mail', 'Bijvoorbeeld Stichting Oasebos.');
            $this->field('oasebos_email_sender_address', 'E-mailadres afzender', 'Gebruik een afzender op je eigen domein.');
        });
        $this->card('Participatienummers', function (): void {
            $this->numberField('oasebos_participation_start_number', 'Startnummer participaties', 'Nieuwe participatienummers worden als vijf cijfers getoond, bijvoorbeeld 00001 of 12345. Bestaande hogere nummers blijven leidend.', 1);
        });
        $this->card('Redirect na succesvolle betaling', function (): void {
            $this->pageDropdown('oasebos_participation_success_page_id', 'Participaties', 'Pagina waar kopers na een participatiebetaling naartoe gaan.');
            $this->pageDropdown('oasebos_donation_success_page_id', 'Donaties', 'Pagina waar donateurs na een eenmalige donatiebetaling naartoe gaan.');
            $this->pageDropdown('oasebos_recurring_donation_success_page_id', 'Periodieke donaties', 'Pagina waar donateurs na de eerste periodieke donatiebetaling naartoe gaan.');
        });
        echo '</main><aside class="oasebos-edit-sidebar">';
        $this->card('Status', function () use ($sdk, $dompdf): void {
            echo '<ul class="oasebos-checklist compact"><li class="' . ($sdk ? 'is-ok' : 'is-warning') . '"><strong>' . ($sdk ? '✓' : '⚠') . ' Mollie SDK</strong><span>' . esc_html($sdk ? 'Beschikbaar' : 'Ontbreekt') . '</span></li><li class="' . ($dompdf ? 'is-ok' : 'is-warning') . '"><strong>' . ($dompdf ? '✓' : '⚠') . ' Dompdf</strong><span>' . esc_html($dompdf ? 'Beschikbaar' : 'Ontbreekt') . '</span></li></ul>';
            submit_button('Instellingen opslaan', 'primary large', 'submit', false);
        }, 'oasebos-sticky-card');
        $this->card('Shortcodes', function (): void {
            echo '<p>Plaats deze shortcodes op publieke pagina’s:</p><code>[oasebos_participation_form]</code><code>[oasebos_donation_form]</code><code>[oasebos_payment_return]</code>';
        });
        echo '</aside></form></div>';
    }

    private function field(string $key, string $label, string $help): void
    {
        echo '<label class="oasebos-field"><span>' . esc_html($label) . '</span><input class="regular-text" name="' . esc_attr($key) . '" value="' . esc_attr((string) get_option($key)) . '"><em>' . esc_html($help) . '</em></label>';
    }

    private function numberField(string $key, string $label, string $help, int $default = 1): void
    {
        $value = max(1, absint(get_option($key, (string) $default)));
        echo '<label class="oasebos-field"><span>' . esc_html($label) . '</span><input class="regular-text" type="number" min="1" step="1" name="' . esc_attr($key) . '" value="' . esc_attr((string) $value) . '"><em>' . esc_html($help) . '</em></label>';
    }

    private function pageDropdown(string $key, string $label, string $help): void
    {
        echo '<label class="oasebos-field"><span>' . esc_html($label) . '</span>';
        wp_dropdown_pages([
            'name' => $key,
            'selected' => absint(get_option($key)),
            'show_option_none' => __('Standaard bedankpagina', 'oasebos-participations'),
            'option_none_value' => '0',
        ]);
        echo '<em>' . esc_html($help) . '</em></label>';
    }
}
