<?php
declare(strict_types=1);

namespace Oasebos\Participations\Security;

final class Capabilities
{
    public const MANAGE = 'manage_oasebos';

    public static function add(): void
    {
        foreach (['administrator'] as $roleName) {
            $role = get_role($roleName);
            if ($role) {
                $role->add_cap(self::MANAGE);
            }
        }
    }

    public static function requireManage(): void
    {
        if (! current_user_can(self::MANAGE) && ! current_user_can('manage_options')) {
            wp_die(esc_html__('Je hebt geen toestemming om Oasebos-records te beheren.', 'oasebos-participations'));
        }
    }
}
