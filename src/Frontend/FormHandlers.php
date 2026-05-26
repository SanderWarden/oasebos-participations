<?php
declare(strict_types=1);

namespace Oasebos\Participations\Frontend;

use Oasebos\Participations\Database\Repository;
use Oasebos\Participations\Security\Nonces;
use Oasebos\Participations\Security\Sanitizer;
use Oasebos\Participations\Services\DonationService;
use Oasebos\Participations\Services\MollieService;
use Oasebos\Participations\Services\ParticipationService;
use Oasebos\Participations\Services\RecurringDonationService;

final class FormHandlers
{
    public function register(): void
    {
        add_action('admin_post_nopriv_oasebos_participation', [$this, 'participation']);
        add_action('admin_post_oasebos_participation', [$this, 'participation']);
        add_action('admin_post_nopriv_oasebos_donation', [$this, 'donation']);
        add_action('admin_post_oasebos_donation', [$this, 'donation']);
        add_action('admin_post_nopriv_oasebos_recurring_donation', [$this, 'recurring']);
        add_action('admin_post_oasebos_recurring_donation', [$this, 'recurring']);
    }

    private function verify(): void
    {
        if (! Nonces::verifyFrontend()) {
            wp_die(esc_html__('Beveiligingscontrole mislukt.', 'oasebos-participations'));
        }
    }

    private function redirectToCheckout(string $checkoutUrl): void
    {
        $host = wp_parse_url($checkoutUrl, PHP_URL_HOST);
        $allowedHosts = [wp_parse_url(home_url('/'), PHP_URL_HOST), 'www.mollie.com', 'mollie.com'];

        if (! is_string($host) || ! in_array(strtolower($host), array_filter($allowedHosts), true)) {
            wp_die(esc_html__('De betaalprovider gaf een onverwachte betaal-URL terug.', 'oasebos-participations'));
        }

        wp_redirect($checkoutUrl);
        exit;
    }

    private function successReturnUrl(string $optionKey): string
    {
        $pageId = absint(get_option($optionKey));
        if ($pageId > 0) {
            $url = get_permalink($pageId);
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        return add_query_arg(['oasebos_payment_return' => 1], home_url('/'));
    }

    public function participation(): void
    {
        $this->verify();

        try {
            $repo = new Repository();
            $isGift = ! empty($_POST['is_gift']);
            $isTest = ! empty($_POST['is_test']) && (current_user_can('manage_oasebos') || current_user_can('manage_options'));
            $giftData = $isGift ? [
                'is_gift' => true,
                'gift_first_name' => Sanitizer::text('gift_first_name', $_POST),
                'gift_last_name' => Sanitizer::text('gift_last_name', $_POST),
                'gift_email' => sanitize_email(wp_unslash($_POST['gift_email'] ?? '')),
                'gift_message' => Sanitizer::textarea('gift_message', $_POST),
            ] : ['is_gift' => false];

            $participationService = new ParticipationService($repo);
            $basketIds = array_map('absint', (array) ($_POST['project_ids'] ?? []));
            $basketUnits = array_map('absint', (array) ($_POST['project_units'] ?? []));
            $participationIds = [];
            if ($basketIds) {
                foreach ($basketIds as $index => $projectId) {
                    if ($projectId <= 0) { continue; }
                    $participationIds[] = $participationService->createPending(array_merge([
                        'project_id' => $projectId,
                        'units' => max(1, (int) ($basketUnits[$index] ?? 1)),
                        'first_name' => Sanitizer::text('first_name', $_POST),
                        'last_name' => Sanitizer::text('last_name', $_POST),
                        'email' => sanitize_email(wp_unslash($_POST['email'] ?? '')),
                        'phone' => Sanitizer::text('phone', $_POST),
                        'address' => Sanitizer::text('address', $_POST),
                        'postcode' => Sanitizer::text('postcode', $_POST),
                        'city' => Sanitizer::text('city', $_POST),
                        'country' => Sanitizer::text('country', $_POST, 'NL'),
                        'is_test' => $isTest,
                    ], $giftData));
                }
                if (! $participationIds) { throw new \RuntimeException(__('Je mandje is leeg.', 'oasebos-participations')); }
            } else {
                $participationIds[] = $participationService->createPending(array_merge([
                'project_id' => Sanitizer::int('project_id', $_POST),
                'units' => Sanitizer::int('units', $_POST, 1),
                'first_name' => Sanitizer::text('first_name', $_POST),
                'last_name' => Sanitizer::text('last_name', $_POST),
                'email' => sanitize_email(wp_unslash($_POST['email'] ?? '')),
                'phone' => Sanitizer::text('phone', $_POST),
                'address' => Sanitizer::text('address', $_POST),
                'postcode' => Sanitizer::text('postcode', $_POST),
                'city' => Sanitizer::text('city', $_POST),
                'country' => Sanitizer::text('country', $_POST, 'NL'),
                'is_test' => $isTest,
                ], $giftData));
            }

            $id = (int) $participationIds[0];
            $p = $repo->get('participations', $id);
            $totalAmount = 0.0;
            $numbers = [];
            foreach ($participationIds as $participationId) { $row = $repo->get('participations', (int) $participationId); if ($row) { $totalAmount += (float) $row['total_amount']; $numbers[] = (string) $row['participation_number']; } }
            $payment = (new MollieService($repo))->createPayment('participation', $id, (string) $totalAmount, $p['currency'], 'Oasebos participatie ' . implode(', ', $numbers), [
                'plugin_entity_type' => 'participation',
                'plugin_entity_id' => $id,
                'participation_ids' => $participationIds,
                'participation_number' => $p['participation_number'],
                'project_id' => $p['project_id'],
                'is_test' => $isTest ? 'yes' : 'no',
            ], $this->successReturnUrl('oasebos_participation_success_page_id'));
            $repo->update('participations', $id, ['mollie_payment_id' => $payment['id']]);
            $this->redirectToCheckout((string) $payment['checkout_url']);
        } catch (\Throwable $e) {
            wp_die(esc_html($e->getMessage()));
        }
    }

    public function donation(): void
    {
        $this->verify();
        $repo = new Repository();
        $amount = Sanitizer::text('amount', $_POST) === 'custom' ? Sanitizer::money('custom_amount', $_POST) : Sanitizer::money('amount', $_POST);
        if (! empty($_POST['is_monthly'])) {
            $id = (new RecurringDonationService($repo))->createPending([
                'first_name' => Sanitizer::text('first_name', $_POST),
                'last_name' => Sanitizer::text('last_name', $_POST),
                'email' => sanitize_email(wp_unslash($_POST['email'] ?? '')),
                'amount' => $amount,
                'interval' => '1 month',
            ]);
            $r = $repo->get('recurring_donations', $id);
            $payment = (new MollieService($repo))->createCustomerAndFirstPayment($id, $r['donor_email'], trim($r['donor_first_name'] . ' ' . $r['donor_last_name']), (string) $r['amount'], $r['currency'], $r['interval_value'], $this->successReturnUrl('oasebos_recurring_donation_success_page_id'));
            $repo->update('recurring_donations', $id, ['initial_payment_id' => $payment['id']]);
            $this->redirectToCheckout((string) $payment['checkout_url']);
        }
        $id = (new DonationService($repo))->createPending([
            'first_name' => Sanitizer::text('first_name', $_POST),
            'last_name' => Sanitizer::text('last_name', $_POST),
            'email' => sanitize_email(wp_unslash($_POST['email'] ?? '')),
            'amount' => $amount,
            'message' => Sanitizer::textarea('message', $_POST),
        ]);
        $d = $repo->get('donations', $id);
        $payment = (new MollieService($repo))->createPayment('donation', $id, (string) $d['amount'], $d['currency'], 'Oasebos donatie ' . $d['donation_number'], [
            'plugin_entity_type' => 'donation',
            'plugin_entity_id' => $id,
        ], $this->successReturnUrl('oasebos_donation_success_page_id'));
        $repo->update('donations', $id, ['mollie_payment_id' => $payment['id']]);
        $this->redirectToCheckout((string) $payment['checkout_url']);
    }

    public function recurring(): void
    {
        $this->verify();
        $repo = new Repository();
        $id = (new RecurringDonationService($repo))->createPending([
            'first_name' => Sanitizer::text('first_name', $_POST),
            'last_name' => Sanitizer::text('last_name', $_POST),
            'email' => sanitize_email(wp_unslash($_POST['email'] ?? '')),
            'amount' => Sanitizer::money('amount', $_POST),
            'interval' => Sanitizer::text('interval', $_POST, '1 month'),
        ]);
        $r = $repo->get('recurring_donations', $id);
        $payment = (new MollieService($repo))->createCustomerAndFirstPayment($id, $r['donor_email'], trim($r['donor_first_name'] . ' ' . $r['donor_last_name']), (string) $r['amount'], $r['currency'], $r['interval_value'], $this->successReturnUrl('oasebos_recurring_donation_success_page_id'));
        $repo->update('recurring_donations', $id, ['initial_payment_id' => $payment['id']]);
        $this->redirectToCheckout((string) $payment['checkout_url']);
    }
}
