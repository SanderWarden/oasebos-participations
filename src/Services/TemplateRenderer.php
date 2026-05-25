<?php
declare(strict_types=1);

namespace Oasebos\Participations\Services;

final class TemplateRenderer
{
    private const OASEBOS_LOGO_URL = '/wp-content/uploads/2026/05/Oasebos-logo-01.jpg';
    private const ANBI_LOGO_URL = '/wp-content/uploads/2026/05/anbi-algemeen-nut-beogende-instelling-01-scaled.jpg';
    private const CBF_LOGO_URL = '/wp-content/uploads/2026/05/CBF22000_Erkend_GoedDoel_RGB-1-01.jpg';

    public function render(string $content, array $context, string $target = 'html'): string
    {
        $values = $this->flattenContext($context);
        $rendered = preg_replace_callback('/\[([a-zA-Z0-9_]+)\]/', function (array $m) use ($values, $target): string {
            $key = $m[1];
            if (! array_key_exists($key, $values)) {
                do_action('oasebos_unknown_template_tag', $key, $target);
                return $m[0];
            }
            $value = (string) $values[$key];
            if (in_array($key, ['land_unit_table'], true)) {
                return wp_kses_post($value);
            }
            return match ($target) {
                'email', 'pdf', 'html' => esc_html($value),
                'raw' => $value,
                default => esc_html($value),
            };
        }, $content) ?? $content;

        return $this->injectFixedCertificateLogos($rendered);
    }

    public function sampleContext(): array
    {
        return [
            'participant_first_name' => 'Ada', 'participant_last_name' => 'Lovelace', 'participant_full_name' => 'Ada Lovelace', 'participant_email' => 'ada@example.org',
            'participant_address' => 'Bosstraat 1', 'participant_postcode' => '1234 AB', 'participant_city' => 'Amsterdam', 'participant_country' => 'NL',
            'project_name' => 'Oasebos Demo Bos', 'project_location' => 'Costa Rica', 'units' => '2', 'forest_piece_label' => '2 stukjes', 'unit_size' => '0.5000', 'total_hectares' => '1.0000',
            'price_per_unit' => '50.00', 'total_amount' => '100.00', 'currency' => 'EUR', 'participation_number' => 'OASEBOS-P-2026-00001',
            'land_unit_numbers' => 'OASEBOS-DEMO-BOS-2026-000001, OASEBOS-DEMO-BOS-2026-000002', 'land_unit_count' => '2', 'land_unit_table' => '<table><tbody><tr><td>1</td><td>OASEBOS-DEMO-BOS-2026-000001</td><td>0.5000</td></tr><tr><td>2</td><td>OASEBOS-DEMO-BOS-2026-000002</td><td>0.5000</td></tr></tbody></table>',
            'agreement_date' => date_i18n(get_option('date_format')), 'payment_date' => date_i18n(get_option('date_format')),
            'organization_name' => get_option('oasebos_organization_name', 'Stichting Oasebos'), 'organization_address' => get_option('oasebos_organization_address', ''),
            'donor_full_name' => 'Ada Lovelace', 'donor_email' => 'ada@example.org', 'donation_number' => 'OASEBOS-D-2026-00001', 'donation_amount' => '25.00', 'donation_date' => date_i18n(get_option('date_format')), 'recurring_interval' => '1 month'
        ];
    }

    private function flattenContext(array $context): array
    {
        if (isset($context['participant_first_name'], $context['participant_last_name']) && ! isset($context['participant_full_name'])) {
            $context['participant_full_name'] = trim($context['participant_first_name'] . ' ' . $context['participant_last_name']);
        }
        if (isset($context['donor_first_name'], $context['donor_last_name']) && ! isset($context['donor_full_name'])) {
            $context['donor_full_name'] = trim($context['donor_first_name'] . ' ' . $context['donor_last_name']);
        }
        return $context;
    }

    private function injectFixedCertificateLogos(string $content): string
    {
        $content = preg_replace('/<div class="logo-placeholder(?: has-image)?">\s*(?:<img[^>]*>|[\s\S]*?)\s*<\/div>/', $this->oasebosLogoHtml(), $content) ?? $content;

        $content = preg_replace('/<div class="mark-placeholder anbi(?: has-image)?">\s*(?:<img[^>]*>|[\s\S]*?)\s*<\/div>/', $this->anbiLogoHtml(), $content) ?? $content;
        $content = preg_replace('/<div class="mark-placeholder cbf(?: has-image)?">\s*(?:<img[^>]*>|[\s\S]*?)\s*<\/div>/', $this->cbfLogoHtml(), $content) ?? $content;

        return $content;
    }

    private function oasebosLogoHtml(): string
    {
        return '<div class="logo-placeholder has-image"><img src="' . esc_url(self::OASEBOS_LOGO_URL) . '" alt="Oasebos" width="190" height="96"></div>';
    }

    private function anbiLogoHtml(): string
    {
        return '<div class="mark-placeholder anbi has-image"><img src="' . esc_url(self::ANBI_LOGO_URL) . '" alt="ANBI" width="83" height="64"></div>';
    }

    private function cbfLogoHtml(): string
    {
        return '<div class="mark-placeholder cbf has-image"><img src="' . esc_url(self::CBF_LOGO_URL) . '" alt="CBF" width="125" height="64"></div>';
    }
}
