<?php
declare(strict_types=1);

namespace Oasebos\Participations\Services;

use Oasebos\Participations\Database\Repository;

final class ParticipationService
{
    public function __construct(private ?Repository $repo = null) { $this->repo ??= new Repository(); }

    public function createPending(array $data): int
    {
        $project = $this->repo->get('projects', (int) $data['project_id']);
        if (! $project || $project['status'] !== 'active') { throw new \RuntimeException(__('Het geselecteerde project is niet beschikbaar.', 'oasebos-participations')); }
        $units = max(1, (int) $data['units']);
        $hectares = $units * (float) $project['unit_size'];
        if ($hectares > (float) $project['available_hectares']) { throw new \RuntimeException(__('Er zijn niet genoeg hectares beschikbaar.', 'oasebos-participations')); }
        if (! empty($data['is_gift']) && (empty($data['gift_first_name']) || empty($data['gift_last_name']))) {
            throw new \RuntimeException(__('Vul de naam van de ontvanger van het cadeau in.', 'oasebos-participations'));
        }
        $templates = $this->snapshots($project);
        $projectSnapshot = $project;
        if (! empty($data['is_gift'])) {
            $projectSnapshot['_gift'] = [
                'is_gift' => true,
                'first_name' => $data['gift_first_name'] ?? '',
                'last_name' => $data['gift_last_name'] ?? '',
                'email' => $data['gift_email'] ?? '',
                'message' => $data['gift_message'] ?? '',
            ];
        }
        return $this->repo->insert('participations', [
            'participation_number' => (new NumberGenerator($this->repo))->generate('participation'), 'project_id' => (int) $project['id'], 'project_snapshot' => wp_json_encode($projectSnapshot),
            'participant_first_name' => $data['first_name'], 'participant_last_name' => $data['last_name'], 'participant_email' => $data['email'], 'participant_phone' => $data['phone'] ?? '',
            'participant_address' => $data['address'] ?? '', 'participant_postcode' => $data['postcode'] ?? '', 'participant_city' => $data['city'] ?? '', 'participant_country' => $data['country'] ?? '',
            'units' => $units, 'unit_size' => $project['unit_size'], 'total_hectares' => $hectares, 'price_per_unit' => $project['price_per_unit'], 'total_amount' => $units * (float) $project['price_per_unit'], 'currency' => $project['currency'],
            'status' => 'pending', 'agreement_template_id' => $templates['agreement_id'], 'certificate_template_id' => $templates['certificate_id'], 'agreement_template_snapshot' => $templates['agreement'], 'certificate_template_snapshot' => $templates['certificate'],
        ]);
    }

    public function markPaid(int $id, string $paymentId = ''): void
    {
        $p = $this->repo->get('participations', $id);
        if (! $p || $p['status'] === 'paid') { return; }
        $project = $this->repo->get('projects', (int) $p['project_id']);
        $landUnits = $project ? $this->repo->landUnitsForParticipation((int) $p['id']) : [];
        $this->repo->beginTransaction();
        try {
            if ($project && ! $landUnits && ! $this->repo->decrementProjectAvailability((int) $project['id'], (float) $p['total_hectares'])) {
                throw new \RuntimeException(__('Dit project heeft niet meer genoeg hectares beschikbaar.', 'oasebos-participations'));
            }
            if ($project) { $landUnits = $this->allocateLandUnits($p, $project); }
            $this->repo->commit();
        } catch (\Throwable $e) {
            $this->repo->rollBack();
            throw $e;
        }
        $context = $this->context($p, $project ?: [], $landUnits);
        $pdf = $this->generatePdfForParticipation($p, $context);
        $this->repo->update('participations', $id, ['status' => 'paid', 'mollie_payment_id' => $paymentId ?: $p['mollie_payment_id'], 'paid_at' => current_time('mysql'), 'pdf_path' => $pdf]);
        $email = new EmailService($this->repo);
        $subject = ! empty($context['is_gift']) && $context['is_gift'] === 'yes' ? __('Je cadeauparticipatie is bevestigd', 'oasebos-participations') : __('Je Oasebos-participatie is bevestigd', 'oasebos-participations');
        $email->send('participation', $id, $p['participant_email'], $subject, $this->participationEmail($context, ! empty($context['is_gift']) && $context['is_gift'] === 'yes'), $pdf ? [$pdf] : [], $context, (! empty($context['is_gift']) && $context['is_gift'] === 'yes') ? 'participation_gift_buyer' : 'participation_paid');
        if (! empty($context['gift_email']) && is_email((string) $context['gift_email'])) {
            $email->send('participation_gift', $id, (string) $context['gift_email'], __('Je hebt een Oasebos-participatie cadeau gekregen', 'oasebos-participations'), $this->giftRecipientEmail($context), $pdf ? [$pdf] : [], $context, 'participation_gift_recipient');
        }
        $email->sendAdmin('participation', $id, __('Nieuwe betaalde participatie ontvangen', 'oasebos-participations'), $this->adminParticipationEmail($context, $p), $context, 'admin_participation_paid');
    }

    public function resendConfirmation(int $id): bool
    {
        $p = $this->repo->get('participations', $id);
        if (! $p) { return false; }
        $attachments = ! empty($p['pdf_path']) && file_exists((string) $p['pdf_path']) ? [(string) $p['pdf_path']] : [];
        return (new EmailService($this->repo))->send('participation', $id, $p['participant_email'], __('Je Oasebos-participatie', 'oasebos-participations'), '<p>' . esc_html__('Hierbij ontvang je de bevestiging van je Oasebos-participatie.', 'oasebos-participations') . '</p>', $attachments);
    }

    public function updateTemplates(int $id, int $agreementTemplateId, int $certificateTemplateId): bool
    {
        $p = $this->repo->get('participations', $id);
        if (! $p) { return false; }

        $agreement = $this->templateSnapshot($agreementTemplateId, 'agreement') ?: $this->defaultAgreementTemplate();
        $certificate = $this->templateSnapshot($certificateTemplateId, 'certificate') ?: $this->defaultCertificateTemplate();
        $data = [
            'agreement_template_id' => $agreement['id'],
            'certificate_template_id' => $certificate['id'],
            'agreement_template_snapshot' => wp_json_encode($agreement['template']),
            'certificate_template_snapshot' => wp_json_encode($certificate['template']),
        ];

        if (($p['status'] ?? '') === 'paid') {
            $project = $this->repo->get('projects', (int) $p['project_id']) ?: [];
            $updated = array_merge($p, $data);
            $pdf = $this->generatePdfForParticipation($updated, $this->context($updated, $project));
            if ($pdf) {
                $data['pdf_path'] = $pdf;
            }
        }

        return $this->repo->update('participations', $id, $data);
    }

    public function context(array $p, array $project, ?array $landUnits = null): array
    {
        $landUnits ??= $this->repo->landUnitsForParticipation((int) $p['id']);
        $numbers = array_map(static fn (array $unit): string => (string) $unit['land_unit_number'], $landUnits);
        $units = (int) ($p['units'] ?? 0);
        $forestPieceLabel = 1 === $units ? 'één stukje' : $units . ' stukjes';
        $snapshot = json_decode((string) ($p['project_snapshot'] ?? ''), true) ?: [];
        $gift = is_array($snapshot['_gift'] ?? null) ? $snapshot['_gift'] : [];
        $giftName = trim((string) ($gift['first_name'] ?? '') . ' ' . (string) ($gift['last_name'] ?? ''));
        return ['participant_first_name'=>$p['participant_first_name'],'participant_last_name'=>$p['participant_last_name'],'participant_email'=>$p['participant_email'],'participant_address'=>$p['participant_address'],'participant_postcode'=>$p['participant_postcode'],'participant_city'=>$p['participant_city'],'participant_country'=>$p['participant_country'],'is_gift'=>!empty($gift['is_gift'])?'yes':'no','gift_first_name'=>$gift['first_name']??'','gift_last_name'=>$gift['last_name']??'','gift_full_name'=>$giftName,'gift_email'=>$gift['email']??'','gift_message'=>$gift['message']??'','project_name'=>$project['name'] ?? '', 'project_location'=>$project['location'] ?? '', 'units'=>$p['units'],'forest_piece_label'=>$forestPieceLabel,'unit_size'=>$p['unit_size'],'total_hectares'=>$p['total_hectares'],'price_per_unit'=>$p['price_per_unit'],'total_amount'=>$p['total_amount'],'currency'=>$p['currency'],'participation_number'=>$p['participation_number'],'land_unit_numbers'=>implode(', ', $numbers),'land_unit_count'=>(string) count($numbers),'land_unit_table'=>$this->landUnitTable($landUnits),'agreement_date'=>date_i18n(get_option('date_format')),'payment_date'=>date_i18n(get_option('date_format')),'organization_name'=>get_option('oasebos_organization_name','Stichting Oasebos'),'organization_address'=>get_option('oasebos_organization_address','')];
    }

    private function allocateLandUnits(array $participation, array $project): array
    {
        $existing = $this->repo->landUnitsForParticipation((int) $participation['id']);
        if ($existing) { return $existing; }

        $units = max(1, (int) $participation['units']);
        $unitSize = (float) $participation['unit_size'];
        $generator = new NumberGenerator($this->repo);
        $sequence = $this->repo->nextLandUnitSequence((int) $project['id']);
        $created = [];

        for ($i = 1; $i <= $units; $i++) {
            $landUnitNumber = $generator->generateLandUnit($project, $sequence + $i - 1);
            $created[] = [
                'id' => $this->repo->insert('participation_land_units', [
                    'participation_id' => (int) $participation['id'],
                    'project_id' => (int) $project['id'],
                    'land_unit_number' => $landUnitNumber,
                    'unit_index' => $i,
                    'hectares' => $unitSize,
                    'status' => 'paid',
                ]),
                'participation_id' => (int) $participation['id'],
                'project_id' => (int) $project['id'],
                'land_unit_number' => $landUnitNumber,
                'unit_index' => $i,
                'hectares' => $unitSize,
                'status' => 'paid',
            ];
        }

        return $this->repo->landUnitsForParticipation((int) $participation['id']) ?: $created;
    }

    private function participationEmail(array $c, bool $gift): string
    {
        return '<p>Beste ' . esc_html((string) $c['participant_first_name']) . ',</p><p>' . esc_html($gift ? __('Bedankt voor je cadeauparticipatie in Oasebos.', 'oasebos-participations') : __('Bedankt voor je participatie in Oasebos. Je betaling is ontvangen en je participatie is bevestigd.', 'oasebos-participations')) . '</p>' . ($gift ? '<p>Je hebt deze participatie cadeau gegeven aan <strong>' . esc_html((string) $c['gift_full_name']) . '</strong>.</p>' : '') . '<p><strong>Participatienummer:</strong> ' . esc_html((string) $c['participation_number']) . '<br><strong>Project:</strong> ' . esc_html((string) $c['project_name']) . '<br><strong>Aantal eenheden:</strong> ' . esc_html((string) $c['units']) . '<br><strong>Totaal hectare:</strong> ' . esc_html((string) $c['total_hectares']) . ' ha<br><strong>Bedrag:</strong> ' . esc_html((string) $c['currency']) . ' ' . esc_html(number_format_i18n((float) $c['total_amount'], 2)) . '</p><p>In de bijlage vind je je participatiecertificaat en overeenkomst.</p><p>Met groene groet,<br>' . esc_html((string) $c['organization_name']) . '</p>';
    }

    private function giftRecipientEmail(array $c): string
    {
        return '<p>Beste ' . esc_html((string) $c['gift_first_name']) . ',</p><p>Je hebt een Oasebos-participatie cadeau gekregen van ' . esc_html(trim((string) $c['participant_first_name'] . ' ' . (string) $c['participant_last_name'])) . '.</p><p><strong>Project:</strong> ' . esc_html((string) $c['project_name']) . '<br><strong>Hectares:</strong> ' . esc_html((string) $c['total_hectares']) . ' ha</p>' . (! empty($c['gift_message']) ? '<p>' . nl2br(esc_html((string) $c['gift_message'])) . '</p>' : '') . '<p>Met deze participatie draag je bij aan bescherming en herstel van natuur via Stichting Oasebos.</p><p>Met groene groet,<br>' . esc_html((string) $c['organization_name']) . '</p>';
    }

    private function adminParticipationEmail(array $c, array $p): string
    {
        return '<p>Er is een nieuwe betaalde participatie ontvangen.</p><p><strong>Participatienummer:</strong> ' . esc_html((string) $c['participation_number']) . '<br><strong>Naam:</strong> ' . esc_html(trim((string) $c['participant_first_name'] . ' ' . (string) $c['participant_last_name'])) . '<br><strong>E-mail:</strong> ' . esc_html((string) $c['participant_email']) . '<br><strong>Project:</strong> ' . esc_html((string) $c['project_name']) . '<br><strong>Hectares:</strong> ' . esc_html((string) $c['total_hectares']) . ' ha<br><strong>Bedrag:</strong> ' . esc_html((string) $c['currency']) . ' ' . esc_html(number_format_i18n((float) $c['total_amount'], 2)) . '</p><p><a href="' . esc_url(admin_url('admin.php?page=oasebos-participations-list&view=' . absint((int) $p['id']))) . '">Bekijk participatie</a></p>';
    }

    private function landUnitTable(array $landUnits): string
    {
        if (! $landUnits) { return ''; }
        $rows = '';
        foreach ($landUnits as $unit) {
            $rows .= sprintf('<tr><td>%s</td><td>%s</td><td>%s</td></tr>', esc_html((string) $unit['unit_index']), esc_html((string) $unit['land_unit_number']), esc_html(number_format_i18n((float) $unit['hectares'], 4)));
        }
        return '<table><thead><tr><th>#</th><th>Land unit</th><th>Hectares</th></tr></thead><tbody>' . $rows . '</tbody></table>';
    }

    private function snapshots(array $project): array
    {
        $agreement = $this->templateSnapshot((int) ($project['agreement_template_id'] ?? 0), 'agreement') ?: $this->defaultAgreementTemplate();
        $certificate = $this->templateSnapshot((int) ($project['certificate_template_id'] ?? 0), 'certificate') ?: $this->defaultCertificateTemplate();
        return ['agreement_id' => $agreement['id'], 'certificate_id' => $certificate['id'], 'agreement' => wp_json_encode($agreement['template']), 'certificate' => wp_json_encode($certificate['template'])];
    }

    private function generatePdfForParticipation(array $p, array $context): ?string
    {
        $renderer = new TemplateRenderer();
        $cert = json_decode((string) $p['certificate_template_snapshot'], true) ?: $this->defaultCertificateTemplate()['template'];
        $agr = json_decode((string) $p['agreement_template_snapshot'], true) ?: $this->defaultAgreementTemplate()['template'];
        return (new PdfService())->generateParticipationPdf($p, $renderer->render($cert['content'], $context, 'pdf'), $renderer->render($agr['content'], $context, 'pdf'), ($cert['css'] ?? '') . "\n" . ($agr['css'] ?? ''));
    }

    private function templateSnapshot(int $templateId, string $type): ?array
    {
        $template = $templateId > 0 ? $this->repo->get('templates', $templateId) : null;
        if (! $template || ($template['type'] ?? '') !== $type) { return null; }
        return ['id' => (int) $template['id'], 'template' => $template];
    }

    private function defaultAgreementTemplate(): array
    {
        return ['id' => 0, 'template' => ['content' => '<h1>Participatieovereenkomst</h1><p>[participant_full_name] - [participation_number]</p>', 'css' => '']];
    }

    private function defaultCertificateTemplate(): array
    {
        return ['id' => 0, 'template' => ['content' => '<h1>Certificaat</h1><p>[participant_full_name]</p><p>[total_hectares] ha in [project_name]</p>', 'css' => '']];
    }
}
