<?php
declare(strict_types=1);

namespace Oasebos\Participations\Services;

use Oasebos\Participations\Database\Repository;

final class MollieService
{
    public function __construct(private ?Repository $repo = null) { $this->repo ??= new Repository(); }

    public function isConfigured(): bool { return (bool) get_option('oasebos_mollie_api_key') && class_exists('\Mollie\Api\MollieApiClient'); }

    public function status(): array
    {
        return [
            'api_key' => (bool) get_option('oasebos_mollie_api_key'),
            'sdk' => class_exists('\Mollie\Api\MollieApiClient'),
            'dompdf' => class_exists('\Dompdf\Dompdf'),
            'webhook_url' => rest_url('oasebos/v1/mollie-webhook'),
        ];
    }

    private function client(): ?\Mollie\Api\MollieApiClient
    {
        if (! $this->isConfigured()) {
            return null;
        }
        $client = new \Mollie\Api\MollieApiClient();
        $client->setApiKey((string) get_option('oasebos_mollie_api_key'));
        return $client;
    }

    public function createPayment(string $entityType, int $entityId, string $amount, string $currency, string $description, array $metadata, string $returnUrl = ''): array
    {
        $webhook = rest_url('oasebos/v1/mollie-webhook');
        $return = $returnUrl ?: add_query_arg(['oasebos_payment_return' => 1], home_url('/'));
        $client = $this->client();
        if (! $client) {
            $paymentId = 'stub_' . wp_generate_uuid4();
            $this->log($entityType, $entityId, $paymentId, 'payment_stub_created', 'open', ['amount' => $amount, 'metadata' => $metadata], 'Mollie SDK/API-sleutel ontbreekt; lokale testbetaling aangemaakt.');
            return ['id' => $paymentId, 'checkout_url' => $return, 'status' => 'open', 'stub' => true];
        }
        $payment = $client->payments->create([
            'amount' => ['currency' => $currency, 'value' => number_format((float) $amount, 2, '.', '')],
            'description' => $description,
            'redirectUrl' => $return,
            'webhookUrl' => $webhook,
            'metadata' => $metadata,
        ]);
        $this->log($entityType, $entityId, $payment->id, 'payment_created', $payment->status, ['metadata' => $metadata]);
        return ['id' => $payment->id, 'checkout_url' => $payment->getCheckoutUrl(), 'status' => $payment->status, 'stub' => false];
    }

    public function fetchPayment(string $paymentId): ?object
    {
        $client = $this->client();
        if (! $client || str_starts_with($paymentId, 'stub_')) {
            return (object) ['id' => $paymentId, 'status' => 'paid', 'metadata' => (object) []];
        }
        return $client->payments->get($paymentId);
    }

    public function createCustomerAndFirstPayment(int $entityId, string $email, string $name, string $amount, string $currency, string $interval, string $returnUrl = ''): array
    {
        $client = $this->client();
        if (! $client) {
            return $this->createPayment('recurring_donation', $entityId, $amount, $currency, sprintf(__('Machtiging periodieke donatie %s', 'oasebos-participations'), $interval), ['plugin_entity_type' => 'recurring_donation_initial', 'plugin_entity_id' => $entityId, 'interval' => $interval], $returnUrl);
        }

        $customer = $client->customers->create(['name' => $name ?: $email, 'email' => $email, 'metadata' => ['plugin_entity_type' => 'recurring_donation', 'plugin_entity_id' => $entityId]]);
        $this->repo->update('recurring_donations', $entityId, ['mollie_customer_id' => $customer->id]);
        $payment = $customer->createPayment([
            'amount' => ['currency' => $currency, 'value' => number_format((float) $amount, 2, '.', '')],
            'description' => sprintf(__('Oasebos machtiging periodieke donatie %s', 'oasebos-participations'), $interval),
            'sequenceType' => 'first',
            'redirectUrl' => $returnUrl ?: add_query_arg(['oasebos_payment_return' => 1], home_url('/')),
            'webhookUrl' => rest_url('oasebos/v1/mollie-webhook'),
            'metadata' => ['plugin_entity_type' => 'recurring_donation_initial', 'plugin_entity_id' => $entityId, 'interval' => $interval, 'customer_id' => $customer->id],
        ]);
        $this->log('recurring_donation', $entityId, $payment->id, 'mandate_payment_created', $payment->status, ['customer_id' => $customer->id, 'interval' => $interval]);
        return ['id' => $payment->id, 'checkout_url' => $payment->getCheckoutUrl(), 'status' => $payment->status, 'stub' => false, 'customer_id' => $customer->id];
    }

    public function createSubscriptionForRecurringDonation(int $entityId): ?array
    {
        $record = $this->repo->get('recurring_donations', $entityId);
        $client = $this->client();
        if (! $record || ! $client || empty($record['mollie_customer_id'])) {
            return null;
        }
        if (! empty($record['mollie_subscription_id'])) {
            return ['id' => $record['mollie_subscription_id']];
        }
        $customer = $client->customers->get((string) $record['mollie_customer_id']);
        $mandates = $customer->mandates();
        $validMandate = null;
        foreach ($mandates as $mandate) {
            if (($mandate->status ?? '') === 'valid') { $validMandate = $mandate; break; }
        }
        if (! $validMandate) {
            $this->log('recurring_donation', $entityId, (string) $record['initial_payment_id'], 'mandate_not_valid', 'pending_mandate', ['customer_id' => $record['mollie_customer_id']], 'Eerste betaling is voldaan, maar de Mollie-machtiging is nog niet geldig.');
            return null;
        }
        $subscription = $customer->createSubscription([
            'amount' => ['currency' => (string) $record['currency'], 'value' => number_format((float) $record['amount'], 2, '.', '')],
            'interval' => (string) $record['interval_value'],
            'description' => sprintf(__('Oasebos periodieke donatie %s', 'oasebos-participations'), $record['subscription_number']),
            'webhookUrl' => rest_url('oasebos/v1/mollie-webhook'),
            'metadata' => ['plugin_entity_type' => 'recurring_donation_subscription', 'plugin_entity_id' => $entityId],
        ]);
        $next = ! empty($subscription->nextPaymentDate) ? gmdate('Y-m-d H:i:s', strtotime((string) $subscription->nextPaymentDate)) : null;
        $this->repo->update('recurring_donations', $entityId, ['mollie_mandate_id' => $validMandate->id, 'mollie_subscription_id' => $subscription->id, 'next_payment_at' => $next]);
        $this->log('recurring_donation', $entityId, null, 'subscription_created', $subscription->status ?? 'active', ['mandate_id' => $validMandate->id, 'subscription_id' => $subscription->id]);
        return ['id' => $subscription->id, 'status' => $subscription->status ?? 'active', 'mandate_id' => $validMandate->id];
    }

    public function cancelSubscription(int $entityId): bool
    {
        $record = $this->repo->get('recurring_donations', $entityId);
        $client = $this->client();
        if (! $record) { return false; }
        if ($client && ! empty($record['mollie_customer_id']) && ! empty($record['mollie_subscription_id'])) {
            $customer = $client->customers->get((string) $record['mollie_customer_id']);
            $customer->cancelSubscription((string) $record['mollie_subscription_id']);
        }
        $this->repo->update('recurring_donations', $entityId, ['status' => 'cancelled', 'cancelled_at' => current_time('mysql')]);
        (new RecurringDonationService($this->repo))->sendCancellationEmail($record);
        $this->log('recurring_donation', $entityId, null, 'subscription_cancelled', 'cancelled', ['subscription_id' => $record['mollie_subscription_id'] ?? null]);
        return true;
    }

    public function syncSubscription(int $entityId): ?array
    {
        $record = $this->repo->get('recurring_donations', $entityId);
        $client = $this->client();
        if (! $record || ! $client || empty($record['mollie_customer_id']) || empty($record['mollie_subscription_id'])) { return null; }
        $subscription = $client->customers->get((string) $record['mollie_customer_id'])->getSubscription((string) $record['mollie_subscription_id']);
        $status = (string) ($subscription->status ?? 'active');
        $data = ['status' => $status === 'active' ? 'active' : $status];
        if (! empty($subscription->nextPaymentDate)) { $data['next_payment_at'] = gmdate('Y-m-d H:i:s', strtotime((string) $subscription->nextPaymentDate)); }
        if (in_array($status, ['cancelled', 'suspended', 'completed'], true)) { $data['cancelled_at'] = current_time('mysql'); }
        $this->repo->update('recurring_donations', $entityId, $data);
        $this->log('recurring_donation', $entityId, null, 'subscription_synced', $status, ['subscription_id' => $record['mollie_subscription_id']]);
        return ['status' => $status];
    }

    public function log(string $entityType, ?int $entityId, ?string $paymentId, string $event, ?string $status, array $payload = [], string $message = ''): void
    {
        $this->repo->insert('payment_logs', ['entity_type' => $entityType, 'entity_id' => $entityId, 'mollie_payment_id' => $paymentId, 'event_type' => $event, 'status' => $status, 'payload' => wp_json_encode($payload), 'message' => $message, 'created_at' => current_time('mysql')]);
    }
}
