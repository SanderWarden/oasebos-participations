<?php
declare(strict_types=1);
namespace Oasebos\Participations\Services;

use Oasebos\Participations\Database\Repository;

final class ExportService
{
    public function stream(string $table): void
    {
        $repo = new Repository();
        $rows = $repo->list($table, ['limit' => 500]);
        if ($table === 'participations') {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => (int) ($row['is_test'] ?? 0) !== 1));
            foreach ($rows as &$row) {
                $units = $repo->landUnitsForParticipation((int) $row['id']);
                $row['land_unit_numbers'] = implode(', ', array_map(static fn (array $unit): string => (string) $unit['land_unit_number'], $units));
            }
            unset($row);
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=oasebos-' . $table . '.csv');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        if ($rows) {
            fputcsv($out, array_keys($rows[0]));
            foreach ($rows as $row) { fputcsv($out, $row); }
        }
        exit;
    }
}
