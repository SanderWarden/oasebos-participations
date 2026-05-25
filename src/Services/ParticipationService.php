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
        return ['id' => 0, 'template' => ['content' => $this->defaultAgreementContent(), 'css' => $this->defaultAgreementCss()]];
    }

    private function defaultAgreementContent(): string
    {
        return '<div class="agreement-template">
    <h1>Participatieovereenkomst</h1>
    <p class="agreement-meta"><strong>Participatienummer:</strong> [participation_number]<br><strong>Datum overeenkomst:</strong> [agreement_date]</p>

    <h2>De ondergetekenden</h2>
    <p>De stichting, <strong>[organization_name]</strong>, gevestigd te <strong>[organization_address]</strong>, ten deze rechtsgeldig vertegenwoordigd door de heer P.C.J. Mols (voorzitter), hierna te noemen: <strong>“[organization_name]”</strong>;</p>
    <p>en</p>
    <p><strong>[participant_full_name]</strong>, wonende/gevestigd aan <strong>[participant_address]</strong>, <strong>[participant_postcode] [participant_city]</strong>, <strong>[participant_country]</strong>, hierna te noemen: <strong>“Participant”</strong>.</p>

    <h2>In aanmerking nemende</h2>
    <ol class="considerations">
        <li>dat [organization_name] onder meer ten doel heeft de bescherming van natuur in Latijns Amerika, in het bijzonder door aankoop van gronden in Latijns Amerika om deze te behouden en te beheren als permanente natuurreservaten. [organization_name] zal de bij haar in bezit zijnde gronden niet uit eigen beweging verkopen en zet zich in om natuurbeschermingsplannen te steunen en te (gaan) ontwikkelen;</li>
        <li>dat [organization_name] de aankoop van de gebieden mede financiert door het uitgeven van participaties aan zowel bedrijven als particulieren;</li>
        <li>dat Participant heeft aangegeven te willen participeren en partijen hun afspraken terzake middels deze overeenkomst vast willen leggen.</li>
    </ol>

    <h2>Zijn het volgende overeengekomen</h2>

    <h3>1. Uitgifte participatie</h3>
    <p>1.1 Binnen 2 weken na ontvangst van de betaling ontvangt Participant van [organization_name] een certificaat op naam ter bevestiging van de participatie.</p>

    <h3>2. Aangewezen gebied</h3>
    <p>2.1 Met de bijdrage van de Participant financiert en beschermt [organization_name] het aangewezen gebied waarvoor de participatie wordt uitgegeven. Iedere hectare van de gebieden in eigendom van [organization_name] wordt slechts eenmaal uitgegeven.</p>
    <p>2.2 Terzake de bijdrage van Participant is <strong>[total_hectares] hectare</strong> van het gebied <strong>[project_name]</strong> in de regio <strong>[project_location]</strong> aangewezen. Dit gebied is aangewezen aan Participant en gekoppeld aan het nummer van de onderhavige participatieovereenkomst: <strong>[participation_number]</strong>.</p>
    <p>2.3 De participatie bestaat uit <strong>[units]</strong> eenheid/eenheden van <strong>[unit_size] hectare</strong> per eenheid. De aan deze participatie gekoppelde landnummers zijn: <strong>[land_unit_numbers]</strong>.</p>
    <div class="land-unit-table">[land_unit_table]</div>

    <h3>3. Overdracht participatie</h3>
    <p>3.1 Participant kan zijn participatie beëindigen door middel van overdracht van de participatie aan een derde.</p>
    <p>3.2 Indien Participant zijn participatie wenst over te dragen, dient Participant de participatie ter overdracht aan Stichting Oasebos aan te bieden. Het is Participant niet toegestaan de participatie zonder tussenkomst van Stichting Oasebos aan een derde over te dragen.</p>
    <p>3.3 Wanneer Participant de participatie ter overdracht aanbiedt aan Stichting Oasebos zal Stichting Oasebos zich inspannen voor zo spoedig mogelijke overdracht van de participatie aan een andere/nieuwe Participant. De door Participant aangeboden participatie wordt daarbij met voorrang behandeld boven de niet vergeven participaties van Stichting Oasebos. Participant kan ook de participatie om niet overdragen aan Stichting Oasebos; in dat geval vervallen artikelen 3.4, 3.5 en 3.6.</p>
    <p>3.4 Voor de bemiddeling van de overdracht van de participatie brengt Stichting Oasebos een bedrag van 8% van de bijdrage van Participant in rekening.</p>
    <p>3.5 Wanneer Stichting Oasebos een andere/nieuwe Participant heeft gevonden, of de voordracht van de nieuwe Participant van de bestaande participatiehouders heeft geaccepteerd waardoor de participatie kan worden overgenomen, wordt de participatie aan deze nieuwe Participant overgedragen.</p>
    <p>3.6 Stichting Oasebos betaalt aan Participant binnen 4 weken nadat de participatieovereenkomst met de nieuwe Participant is getekend en de betaling van de nieuwe Participant is ontvangen, het ontvangen bedrag minus de 8% administratiekosten.</p>

    <h3>4. Toegang tot de gebieden van [organization_name]</h3>
    <p>4.1 De gebieden in eigendom van [organization_name] zijn slechts zeer beperkt toegankelijk.</p>
    <p>4.2 Participant heeft uit hoofde van de participatie het exclusieve recht om, onder begeleiding van een boswachter/beheerder, alle gebieden die in eigendom zijn van [organization_name] te bezoeken en betreden.</p>

    <h3>5. Informatie over de activiteiten van [organization_name]</h3>
    <p>5.1 Participant zal door [organization_name] op de hoogte worden gehouden van de activiteiten van [organization_name] en recente ontwikkelingen door middel van een digitale nieuwsbrief die in principe tweemaal per jaar gratis wordt verstuurd.</p>
    <p>5.2 Participant kan zich op ieder moment uitschrijven voor de digitale nieuwsbrief door middel van een e-mail aan het secretariaat van [organization_name] onder vermelding van “afmelding nieuwsbrief”.</p>

    <h3>6. Inspraak over de activiteiten van [organization_name]</h3>
    <p>6.1 Participant kan opmerkingen ten aanzien van het beleid van [organization_name] en de uitvoering van activiteiten door [organization_name], schriftelijk, per e-mail of per post, indienen bij het secretariaat van [organization_name].</p>
    <p>6.2 [organization_name] streeft ernaar een schriftelijke reactie te geven op binnengekomen opmerkingen van Participant.</p>
    <p>6.3 Indien mogelijk en wenselijk zal [organization_name] wijzigingen doorvoeren in haar beleid en/of de uitvoering van activiteiten naar aanleiding van opmerkingen van Participant. Zulks volledig ter beoordeling van [organization_name].</p>

    <h3>7. Beheerkosten</h3>
    <p>7.1 De beheerkosten voor de gebieden in eigendom van [organization_name] worden door [organization_name] gefinancierd.</p>

    <h3>8. Overige bepalingen</h3>
    <p>8.1 Op deze overeenkomst is Nederlands recht van toepassing.</p>
    <p>8.2 Alle geschillen, die mochten ontstaan naar aanleiding van onderhavige overeenkomst, dan wel nadere overeenkomsten die daarvan het gevolg mochten zijn, zullen worden voorgelegd aan de rechtbank te Rotterdam.</p>

    <div class="signature-grid">
        <div>
            <p>Aldus overeengekomen namens [organization_name],</p>
            <p class="signature-line"></p>
            <p>P.C.J. Mols<br>Voorzitter [organization_name]</p>
        </div>
        <div>
            <p>Aldus overeengekomen door Participant,</p>
            <p class="signature-line"></p>
            <p>[participant_full_name]</p>
        </div>
    </div>
</div>';
    }

    private function defaultAgreementCss(): string
    {
        return '.agreement-template{font-family:DejaVu Sans,Arial,sans-serif;font-size:10.5pt;line-height:1.45;color:#222}.agreement-template h1{font-size:20pt;margin:0 0 8mm;color:#2f5f2f}.agreement-template h2{font-size:13pt;margin:7mm 0 3mm;color:#2f5f2f}.agreement-template h3{font-size:11.5pt;margin:5mm 0 2mm;color:#333}.agreement-template p{margin:0 0 3mm}.agreement-meta{padding:4mm;background:#f3f7ef;border-left:1.5mm solid #8cc31b}.considerations{margin:0 0 4mm 6mm;padding:0}.considerations li{margin-bottom:2.5mm}.land-unit-table table{width:100%;border-collapse:collapse;margin:3mm 0 5mm}.land-unit-table th,.land-unit-table td{border:0.2mm solid #bbb;padding:2mm;text-align:left}.signature-grid{display:table;width:100%;margin-top:12mm;page-break-inside:avoid}.signature-grid>div{display:table-cell;width:50%;padding-right:8mm;vertical-align:top}.signature-line{height:18mm;border-bottom:0.3mm solid #555;margin:8mm 0 3mm}';
    }

    private function defaultCertificateTemplate(): array
    {
        return ['id' => 0, 'template' => ['content' => '<h1>Certificaat</h1><p>[participant_full_name]</p><p>[total_hectares] ha in [project_name]</p>', 'css' => '']];
    }
}
