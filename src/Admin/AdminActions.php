<?php
declare(strict_types=1);

namespace Oasebos\Participations\Admin;

use Oasebos\Participations\Database\Repository;
use Oasebos\Participations\Security\Capabilities;
use Oasebos\Participations\Security\Nonces;
use Oasebos\Participations\Services\DonationService;
use Oasebos\Participations\Services\MollieService;
use Oasebos\Participations\Services\ParticipationService;
use Oasebos\Participations\Services\RecurringDonationService;

final class AdminActions
{
    public function register(): void
    {
        add_action('admin_post_oasebos_resend_participation', [$this, 'resendParticipation']);
        add_action('admin_post_oasebos_resend_donation', [$this, 'resendDonation']);
        add_action('admin_post_oasebos_resend_recurring', [$this, 'resendRecurring']);
        add_action('admin_post_oasebos_cancel_recurring', [$this, 'cancelRecurring']);
        add_action('admin_post_oasebos_sync_recurring', [$this, 'syncRecurring']);
        add_action('admin_post_oasebos_update_participation_templates', [$this, 'updateParticipationTemplates']);
    }

    public function resendParticipation(): void { $this->handle(fn(int $id) => (new ParticipationService(new Repository()))->resendConfirmation($id)); }
    public function resendDonation(): void { $this->handle(fn(int $id) => (new DonationService(new Repository()))->resendConfirmation($id)); }
    public function resendRecurring(): void { $this->handle(fn(int $id) => (new RecurringDonationService(new Repository()))->resendConfirmation($id)); }
    public function cancelRecurring(): void { $this->handle(fn(int $id) => (new MollieService(new Repository()))->cancelSubscription($id)); }
    public function syncRecurring(): void { $this->handle(fn(int $id) => null !== (new MollieService(new Repository()))->syncSubscription($id)); }

    public function updateParticipationTemplates(): void
    {
        Capabilities::requireManage();
        Nonces::verifyAdmin();
        $id = absint($_POST['id'] ?? 0);
        $agreementTemplateId = absint($_POST['agreement_template_id'] ?? 0);
        $certificateTemplateId = absint($_POST['certificate_template_id'] ?? 0);
        $ok = $id > 0 && (new ParticipationService(new Repository()))->updateTemplates($id, $agreementTemplateId, $certificateTemplateId);
        wp_safe_redirect(add_query_arg(['view' => $id, 'oasebos_notice' => $ok ? 'done' : 'failed'], admin_url('admin.php?page=oasebos-participations-list')));
        exit;
    }

    private function handle(callable $callback): void
    {
        Capabilities::requireManage();
        $id = absint($_GET['id'] ?? 0);
        $action = str_replace('admin_post_', '', (string) current_action());
        check_admin_referer('oasebos_' . $action . '_' . $id);
        $ok = $id > 0 && (bool) $callback($id);
        wp_safe_redirect(add_query_arg(['oasebos_notice' => $ok ? 'done' : 'failed'], wp_get_referer() ?: admin_url('admin.php?page=oasebos')));
        exit;
    }
}
