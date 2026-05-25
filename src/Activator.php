<?php
declare(strict_types=1);

namespace Oasebos\Participations;

use Oasebos\Participations\Database\Schema;
use Oasebos\Participations\Security\Capabilities;

final class Activator
{
    public static function activate(): void
    {
        (new Schema())->create();
        Capabilities::add();
        update_option('oasebos_participations_version', OASEBOS_PARTICIPATIONS_VERSION);
    }
}
