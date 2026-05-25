<?php
declare(strict_types=1);

namespace Oasebos\Participations\Controllers;

use Oasebos\Participations\Database\Repository;
use Oasebos\Participations\Services\DonationService;
use Oasebos\Participations\Services\MollieService;
use Oasebos\Participations\Services\ParticipationService;
use Oasebos\Participations\Services\RecurringDonationService;
use WP_REST_Request;
use WP_REST_Response;

final class WebhookController
{
    public function registerRoutes(): void
    {
        register_rest_route('oasebos/v1', '/mollie-webhook', ['methods' => 'POST', 'callback' => [$this, 'handle'], 'permission_callback' => '__return_true']);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $paymentId = sanitize_text_field((string) $request->get_param('id'));
        $repo = new Repository();
        $mollie = new MollieService($repo);
        $mollie->log('unknown', null, $paymentId, 'webhook_received', null, $request->get_params());

        if (! $paymentId) {
            return new WP_REST_Response(['ok' => false, 'message' => 'missing id'], 200);
        }

        try {
            $payment = $mollie->fetchPayment($paymentId);
            $status = (string) ($payment->status ?? 'unknown');
            $meta = (array) ($payment->metadata ?? []);
            [$type, $entity] = $this->resolveEntity($repo, $paymentId, $meta);
            $mollie->log($type ?: 'unknown', $entity ?: null, $paymentId, 'payment_fetched', $status, $meta);

            if ($entity > 0) {
                if ($type === 'participation' && $status === 'paid') {
                    $participationIds = array_filter(array_map('absint', (array) ($meta['participation_ids'] ?? [$entity])));
                    foreach (array_unique($participationIds) as $participationId) {
                        (new ParticipationService($repo))->markPaid((int) $participationId, $participationId === $entity ? $paymentId : '');
                    }
                } elseif ($type === 'donation' && $status === 'paid') {
                    (new DonationService($repo))->markPaid($entity, $paymentId);
                } elseif (str_starts_with($type, 'recurring_donation')) {
                    $service = new RecurringDonationService($repo);
                    if ($status === 'paid' && $type === 'recurring_donation_initial') {
                        $service->markActive($entity, $paymentId);
                    } else {
                        $service->recordPayment($entity, $paymentId, $status);
                    }
                }
            }

            return new WP_REST_Response(['ok' => true], 200);
        } catch (\Throwable $e) {
            $mollie->log('unknown', null, $paymentId, 'webhook_error', 'error', [], $e->getMessage());
            return new WP_REST_Response(['ok' => false], 500);
        }
    }

    private function resolveEntity(Repository $repo, string $paymentId, array $meta): array
    {
        $type = (string) ($meta['plugin_entity_type'] ?? '');
        $entity = (int) ($meta['plugin_entity_id'] ?? 0);
        if ($entity > 0) {
            return [$type, $entity];
        }

        foreach (['participations' => 'mollie_payment_id', 'donations' => 'mollie_payment_id', 'recurring_donations' => 'initial_payment_id'] as $table => $column) {
            $row = $repo->findBy($table, $column, $paymentId);
            if ($row) {
                return [$table === 'participations' ? 'participation' : ($table === 'donations' ? 'donation' : 'recurring_donation_initial'), (int) $row['id']];
            }
        }
        $row = $repo->findBy('recurring_donations', 'last_payment_id', $paymentId);
        if ($row) {
            return ['recurring_donation_subscription', (int) $row['id']];
        }

        return ['', 0];
    }
}
