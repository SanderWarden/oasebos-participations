<?php
declare(strict_types=1);

namespace Oasebos\Participations;

final class Deactivator
{
    public static function deactivate(): void
    {
        flush_rewrite_rules();
    }
}
