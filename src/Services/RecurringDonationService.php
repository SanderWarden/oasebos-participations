<?php
declare(strict_types=1);

namespace Oasebos\Participations\Services;

use Oasebos\Participations\Database\Repository;

final class RecurringDonationService
{
    public function __construct(private ?Repository $repo = null) { $this->repo ??= new Repository(); }

    public function createPending(array $d): int
    {
        return $this->repo->insert('recurring_donations', ['subscription_number' => (new NumberGenerator($this->repo))->generate('recurring'), 'donor_first_name' => $d['first_name'] ?? '', 'donor_last_name' => $d['last_name'] ?? '', 'donor_email' => $d['email'], 'amount' => $d['amount'], 'currency' => 'EUR', 'interval_value' => $d['interval'] ?: '1 month', 'status' => 'pending_mandate']);
    }

    public function markActive(int $id, string $paymentId = ''): void
    {
        $r = $this->repo->get('recurring_donations', $id);
        if (! $r) { return; }
        $data = ['status' => 'active', 'initial_payment_id' => $paymentId ?: $r['initial_payment_id']];
        if (empty($r['started_at'])) { $data['started_at'] = current_time('mysql'); }
        $this->repo->update('recurring_donations', $id, $data);
        (new MollieService($this->repo))->createSubscriptionForRecurringDonation($id);
        if ($r['status'] !== 'active') {
            $email = new EmailService($this->repo);
            $email->send('recurring_donation', $id, $r['donor_email'], __('Je maandelijkse donatie aan Oasebos is gestart', 'oasebos-participations'), $this->startedEmail($r), [], $this->context($r), 'recurring_started');
            $email->sendAdmin('recurring_donation', $id, __('Nieuwe maandelijkse donatie ontvangen', 'oasebos-participations'), $this->adminEmail($r), $this->context($r), 'admin_recurring_started');
        }
    }

    public function recordPayment(int $id, string $paymentId, string $status): void
    {
        $data = ['last_payment_id' => $paymentId, 'last_payment_status' => $status];
        if (in_array($status, ['failed', 'expired', 'cancelled'], true)) { $data['status'] = 'payment_' . $status; }
        if ($status === 'paid') { $data['status'] = 'active'; }
        $this->repo->update('recurring_donations', $id, $data);
        if (in_array($status, ['failed', 'expired', 'cancelled'], true)) { $r=$this->repo->get('recurring_donations',$id); if($r){(new EmailService($this->repo))->send('recurring_donation',$id,$r['donor_email'],__('Er is iets misgegaan met je maandelijkse donatie','oasebos-participations'),$this->failedEmail($r),[],$this->context($r),'recurring_failed');} }
    }

    public function resendConfirmation(int $id): bool
    {
        $r = $this->repo->get('recurring_donations', $id);
        if (! $r) { return false; }
        return (new EmailService($this->repo))->send('recurring_donation', $id, $r['donor_email'], __('Je maandelijkse donatie aan Oasebos is gestart', 'oasebos-participations'), $this->startedEmail($r), [], $this->context($r), 'recurring_started');
    }

    public function sendCancellationEmail(array $r): void { (new EmailService($this->repo))->send('recurring_donation',(int)$r['id'],$r['donor_email'],__('Je maandelijkse donatie is stopgezet','oasebos-participations'),'<p>Beste '.esc_html((string)$r['donor_first_name']).',</p><p>Hierbij bevestigen we dat je maandelijkse donatie aan Stichting Oasebos is stopgezet.</p><p><strong>Abonnementsnummer:</strong> '.esc_html((string)$r['subscription_number']).'<br><strong>Stopgezet op:</strong> '.esc_html(date_i18n(get_option('date_format'))).'</p><p>Hartelijk dank voor je steun.</p><p>Met groene groet,<br>Stichting Oasebos</p>',[],$this->context($r)+['cancelled_date'=>date_i18n(get_option('date_format'))],'recurring_cancelled'); }
    private function context(array $r): array { return ['subscription_number'=>$r['subscription_number']??'','donor_first_name'=>$r['donor_first_name']??'','donor_last_name'=>$r['donor_last_name']??'','donor_email'=>$r['donor_email']??'','currency'=>$r['currency']??'EUR','amount'=>number_format_i18n((float)($r['amount']??0),2)]; } private function startedEmail(array $r): string { return '<p>Beste '.esc_html((string)$r['donor_first_name']).',</p><p>Bedankt voor je maandelijkse donatie aan Stichting Oasebos. Je maandelijkse donatie is gestart.</p><p><strong>Abonnementsnummer:</strong> '.esc_html((string)$r['subscription_number']).'<br><strong>Bedrag:</strong> '.esc_html((string)$r['currency']).' '.esc_html(number_format_i18n((float)$r['amount'],2)).' per maand</p><p>Je donatie wordt maandelijks geïncasseerd via Mollie. Wil je iets wijzigen of stopzetten? Neem dan contact met ons op.</p><p>Met groene groet,<br>Stichting Oasebos</p>'; }
    private function failedEmail(array $r): string { return '<p>Beste '.esc_html((string)$r['donor_first_name']).',</p><p>We konden je maandelijkse donatie aan Stichting Oasebos helaas niet verwerken.</p><p><strong>Abonnementsnummer:</strong> '.esc_html((string)$r['subscription_number']).'<br><strong>Bedrag:</strong> '.esc_html((string)$r['currency']).' '.esc_html(number_format_i18n((float)$r['amount'],2)).'</p><p>Wil je je betaalgegevens controleren of contact met ons opnemen? Dan helpen we je graag verder.</p><p>Met groene groet,<br>Stichting Oasebos</p>'; }
    private function adminEmail(array $r): string { return '<p>Er is een nieuwe maandelijkse donatie gestart.</p><p><strong>Abonnementsnummer:</strong> '.esc_html((string)$r['subscription_number']).'<br><strong>Naam:</strong> '.esc_html(trim((string)$r['donor_first_name'].' '.(string)$r['donor_last_name'])).'<br><strong>E-mail:</strong> '.esc_html((string)$r['donor_email']).'<br><strong>Bedrag:</strong> '.esc_html((string)$r['currency']).' '.esc_html(number_format_i18n((float)$r['amount'],2)).' per maand</p>'; }
}
