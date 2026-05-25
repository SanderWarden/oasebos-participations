<?php
declare(strict_types=1);

namespace Oasebos\Participations\Services;

use Oasebos\Participations\Database\Repository;

final class EmailService
{
    public function __construct(private ?Repository $repo = null, private ?TemplateRenderer $renderer = null) { $this->repo ??= new Repository(); $this->renderer ??= new TemplateRenderer(); }

    public function send(string $entityType, int $entityId, string $to, string $subject, string $html, array $attachments = [], array $context = [], string $templateKey = ''): bool
    {
        if ($templateKey) {
            $template = $this->template($templateKey);
            if ($template) {
                $subject = (string) ($template['subject'] ?: $subject);
                $html = (string) $template['content'];
            }
        }
        if ($context) {
            $subject = $this->renderer->render($subject, $context, 'email');
            $html = $this->renderer->render($html, $context, 'email');
        }
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $fromName = get_option('oasebos_email_sender_name');
        $fromEmail = sanitize_email((string) get_option('oasebos_email_sender_address'));
        if ($fromName && is_email($fromEmail)) { $headers[] = sprintf('From: %s <%s>', sanitize_text_field((string) $fromName), $fromEmail); }
        $ok = wp_mail($to, $subject, $html, $headers, array_filter($attachments, 'file_exists'));
        $this->repo->insert('email_logs', ['entity_type' => $entityType, 'entity_id' => $entityId, 'recipient_email' => $to, 'subject' => $subject, 'status' => $ok ? 'sent' : 'failed', 'error_message' => $ok ? null : 'wp_mail returned false', 'created_at' => current_time('mysql')]);
        return $ok;
    }

    public function sendAdmin(string $entityType, int $entityId, string $subject, string $html, array $context = [], string $templateKey = ''): bool
    {
        $to = sanitize_email((string) get_option('admin_email'));
        return is_email($to) ? $this->send($entityType, $entityId, $to, $subject, $html, [], $context, $templateKey) : false;
    }

    public function template(string $key): ?array
    {
        $template = $this->repo->findBy('templates', 'name', $key);
        return $template && ($template['type'] ?? '') === 'email' && ($template['status'] ?? '') === 'active' ? $template : null;
    }

    public function ensureDefaultTemplates(): void
    {
        foreach ($this->defaultTemplates() as $key => $template) {
            if (! $this->repo->findBy('templates', 'name', $key)) {
                $this->repo->insert('templates', ['type' => 'email', 'name' => $key, 'subject' => $template['subject'], 'content' => $template['content'], 'css' => '', 'status' => 'active']);
            }
        }
    }

    public function defaultTemplates(): array
    {
        return [
            'participation_paid' => ['subject' => 'Je Oasebos-participatie is bevestigd', 'content' => '<p>Beste [participant_first_name],</p><p>Bedankt voor je participatie in Oasebos. Je betaling is ontvangen en je participatie is bevestigd.</p><p><strong>Participatienummer:</strong> [participation_number]<br><strong>Project:</strong> [project_name]<br><strong>Aantal eenheden:</strong> [units]<br><strong>Totaal hectare:</strong> [total_hectares] ha<br><strong>Bedrag:</strong> [currency] [total_amount]</p><p>In de bijlage vind je je participatiecertificaat en overeenkomst.</p><p>Met groene groet,<br>[organization_name]</p>'],
            'participation_gift_buyer' => ['subject' => 'Je cadeauparticipatie is bevestigd', 'content' => '<p>Beste [participant_first_name],</p><p>Bedankt voor je cadeauparticipatie in Oasebos.</p><p>Je hebt deze participatie cadeau gegeven aan <strong>[gift_full_name]</strong>.</p><p><strong>Project:</strong> [project_name]<br><strong>Totaal hectare:</strong> [total_hectares] ha<br><strong>Bedrag:</strong> [currency] [total_amount]</p><p>In de bijlage vind je de documenten bij deze participatie.</p><p>Met groene groet,<br>[organization_name]</p>'],
            'participation_gift_recipient' => ['subject' => 'Je hebt een Oasebos-participatie cadeau gekregen', 'content' => '<p>Beste [gift_first_name],</p><p>Je hebt een Oasebos-participatie cadeau gekregen van [participant_first_name] [participant_last_name].</p><p><strong>Project:</strong> [project_name]<br><strong>Hectares:</strong> [total_hectares] ha</p><p>[gift_message]</p><p>Met deze participatie draag je bij aan bescherming en herstel van natuur via Stichting Oasebos.</p><p>Met groene groet,<br>[organization_name]</p>'],
            'donation_paid' => ['subject' => 'Bedankt voor je donatie aan Oasebos', 'content' => '<p>Beste [donor_first_name],</p><p>Hartelijk dank voor je donatie aan Stichting Oasebos.</p><p><strong>Donatienummer:</strong> [donation_number]<br><strong>Bedrag:</strong> [currency] [amount]</p><p>Met jouw steun kunnen we natuur beschermen en herstellen.</p><p>Met groene groet,<br>Stichting Oasebos</p>'],
            'recurring_started' => ['subject' => 'Je maandelijkse donatie aan Oasebos is gestart', 'content' => '<p>Beste [donor_first_name],</p><p>Bedankt voor je maandelijkse donatie aan Stichting Oasebos. Je maandelijkse donatie is gestart.</p><p><strong>Abonnementsnummer:</strong> [subscription_number]<br><strong>Bedrag:</strong> [currency] [amount] per maand</p><p>Je donatie wordt maandelijks geïncasseerd via Mollie. Wil je iets wijzigen of stopzetten? Neem dan contact met ons op.</p><p>Met groene groet,<br>Stichting Oasebos</p>'],
            'recurring_cancelled' => ['subject' => 'Je maandelijkse donatie is stopgezet', 'content' => '<p>Beste [donor_first_name],</p><p>Hierbij bevestigen we dat je maandelijkse donatie aan Stichting Oasebos is stopgezet.</p><p><strong>Abonnementsnummer:</strong> [subscription_number]<br><strong>Stopgezet op:</strong> [cancelled_date]</p><p>Hartelijk dank voor je steun.</p><p>Met groene groet,<br>Stichting Oasebos</p>'],
            'recurring_failed' => ['subject' => 'Er is iets misgegaan met je maandelijkse donatie', 'content' => '<p>Beste [donor_first_name],</p><p>We konden je maandelijkse donatie aan Stichting Oasebos helaas niet verwerken.</p><p><strong>Abonnementsnummer:</strong> [subscription_number]<br><strong>Bedrag:</strong> [currency] [amount]</p><p>Wil je je betaalgegevens controleren of contact met ons opnemen? Dan helpen we je graag verder.</p><p>Met groene groet,<br>Stichting Oasebos</p>'],
            'admin_participation_paid' => ['subject' => 'Nieuwe betaalde participatie ontvangen', 'content' => '<p>Er is een nieuwe betaalde participatie ontvangen.</p><p><strong>Participatienummer:</strong> [participation_number]<br><strong>Naam:</strong> [participant_first_name] [participant_last_name]<br><strong>E-mail:</strong> [participant_email]<br><strong>Project:</strong> [project_name]<br><strong>Hectares:</strong> [total_hectares] ha<br><strong>Bedrag:</strong> [currency] [total_amount]</p>'],
            'admin_donation_paid' => ['subject' => 'Nieuwe donatie ontvangen', 'content' => '<p>Er is een nieuwe donatie ontvangen.</p><p><strong>Donatienummer:</strong> [donation_number]<br><strong>Naam:</strong> [donor_first_name] [donor_last_name]<br><strong>E-mail:</strong> [donor_email]<br><strong>Bedrag:</strong> [currency] [amount]</p>'],
            'admin_recurring_started' => ['subject' => 'Nieuwe maandelijkse donatie ontvangen', 'content' => '<p>Er is een nieuwe maandelijkse donatie gestart.</p><p><strong>Abonnementsnummer:</strong> [subscription_number]<br><strong>Naam:</strong> [donor_first_name] [donor_last_name]<br><strong>E-mail:</strong> [donor_email]<br><strong>Bedrag:</strong> [currency] [amount] per maand</p>'],
        ];
    }
}
