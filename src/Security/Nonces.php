<?php
declare(strict_types=1);

namespace Oasebos\Participations\Security;

final class Nonces
{
    public const ADMIN_ACTION = 'oasebos_admin_action';
    public const FRONTEND_ACTION = 'oasebos_frontend_action';

    public static function verifyAdmin(string $field = '_wpnonce'): void
    {
        if (! isset($_REQUEST[$field]) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST[$field])), self::ADMIN_ACTION)) {
            wp_die(esc_html__('Beveiligingscontrole mislukt.', 'oasebos-participations'));
        }
    }

    public static function verifyFrontend(string $field = 'oasebos_nonce'): bool
    {
        return isset($_POST[$field]) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[$field])), self::FRONTEND_ACTION);
    }
}
