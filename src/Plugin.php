<?php
declare(strict_types=1);

namespace Oasebos\Participations;

use Oasebos\Participations\Admin\AdminMenu;
use Oasebos\Participations\Admin\AdminActions;
use Oasebos\Participations\Admin\TemplatePreviewController;
use Oasebos\Participations\Controllers\PdfDownloadController;
use Oasebos\Participations\Controllers\ReturnController;
use Oasebos\Participations\Controllers\WebhookController;
use Oasebos\Participations\Database\Schema;
use Oasebos\Participations\Frontend\Assets;
use Oasebos\Participations\Frontend\FormHandlers;
use Oasebos\Participations\Frontend\Pages;
use Oasebos\Participations\Frontend\Shortcodes;

final class Plugin
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function boot(): void
    {
        $this->maybeUpgrade();
        add_action('init', [$this, 'registerHooks']);
        add_action('rest_api_init', [new WebhookController(), 'registerRoutes']);
        if (is_admin()) {
            (new AdminMenu())->register();
            (new AdminActions())->register();
            (new TemplatePreviewController())->register();
            (new PdfDownloadController())->register();
        }
        (new Assets())->register();
        (new Pages())->register();
        (new Shortcodes())->register();
        (new FormHandlers())->register();
        (new ReturnController())->register();
    }

    public function registerHooks(): void
    {
        do_action('oasebos_participations_init');
    }

    private function maybeUpgrade(): void
    {
        if (get_option('oasebos_participations_version') !== OASEBOS_PARTICIPATIONS_VERSION) {
            (new Schema())->create();
            update_option('oasebos_participations_version', OASEBOS_PARTICIPATIONS_VERSION);
        }
    }
}
