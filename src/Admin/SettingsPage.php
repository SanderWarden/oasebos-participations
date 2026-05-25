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
}
