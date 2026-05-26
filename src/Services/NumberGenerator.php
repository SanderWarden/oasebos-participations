<?php
declare(strict_types=1);

namespace Oasebos\Participations\Services;

use Oasebos\Participations\Database\Repository;

final class NumberGenerator
{
    public function __construct(private ?Repository $repo = null) { $this->repo ??= new Repository(); }

    public function generate(string $type): string
    {
        if ($type === 'participation') {
            return $this->generateParticipation();
        }

        $map = ['participation' => ['P','participations','participation_number'], 'donation' => ['D','donations','donation_number'], 'recurring' => ['R','recurring_donations','subscription_number'], 'land_unit' => ['L','participation_land_units','land_unit_number']];
        [$prefix, $table, $column] = $map[$type] ?? $map['participation'];
        $year = gmdate('Y');
        for ($i = 1; $i < 20; $i++) {
            $number = sprintf('OASEBOS-%s-%s-%05d', $prefix, $year, time() % 100000 + $i);
            if (! $this->repo->findBy($table, $column, $number)) { return $number; }
        }
        return sprintf('OASEBOS-%s-%s-%s', $prefix, $year, wp_generate_password(8, false, false));
    }

    private function generateParticipation(): string
    {
        $start = max(1, absint(get_option('oasebos_participation_start_number', '1')));
        $next = max($start, $this->repo->maxNumericSuffix('participations', 'participation_number') + 1);

        for ($i = 0; $i < 100; $i++) {
            $number = sprintf('%05d', $next + $i);
            if (! $this->repo->findBy('participations', 'participation_number', $number)) { return $number; }
        }

        return (string) ($next + 100);
    }

    public function generateLandUnit(array $project, int $sequence): string
    {
        $base = strtoupper((string) ($project['slug'] ?: $project['name'] ?: 'LAND'));
        $base = trim((string) preg_replace('/[^A-Z0-9]+/', '-', $base), '-');
        $prefix = $base !== '' ? $base : 'LAND';
        $year = gmdate('Y');

        for ($i = 0; $i < 20; $i++) {
            $number = sprintf('%s-%s-%06d', $prefix, $year, $sequence + $i);
            if (! $this->repo->findBy('participation_land_units', 'land_unit_number', $number)) { return $number; }
        }

        return sprintf('%s-%s-%s', $prefix, $year, wp_generate_password(8, false, false));
    }
}
