<?php
declare(strict_types=1);

namespace Oasebos\Participations\Database;

final class Repository
{
    public function table(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . 'oasebos_' . $name;
    }

    public function now(): string
    {
        return current_time('mysql');
    }

    public function get(string $table, int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->table($table) . ' WHERE id = %d', $id), ARRAY_A);
        return $row ?: null;
    }

    public function findBy(string $table, string $column, string $value): ?array
    {
        global $wpdb;
        $allowed = preg_replace('/[^a-z0-9_]/', '', $column);
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->table($table) . " WHERE {$allowed} = %s LIMIT 1", $value), ARRAY_A);
        return $row ?: null;
    }

    public function findAllBy(string $table, string $column, string $value, int $limit = 100): array
    {
        global $wpdb;
        $allowed = preg_replace('/[^a-z0-9_]/', '', $column);
        $limit = max(1, min(500, absint($limit)));
        return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . $this->table($table) . " WHERE {$allowed} = %s ORDER BY id DESC LIMIT %d", $value, $limit), ARRAY_A) ?: [];
    }

    public function list(string $table, array $args = []): array
    {
        global $wpdb;
        $limit = isset($args['limit']) ? max(1, min(500, absint($args['limit']))) : 50;
        $offset = isset($args['offset']) ? max(0, absint($args['offset'])) : 0;
        $where = '1=1';
        $params = [];
        if (! empty($args['status'])) { $where .= ' AND status = %s'; $params[] = $args['status']; }
        if (! empty($args['type'])) { $where .= ' AND type = %s'; $params[] = $args['type']; }
        if (! empty($args['search'])) {
            $where .= ' AND (donor_email LIKE %s OR participant_email LIKE %s OR subscription_number LIKE %s OR donation_number LIKE %s OR participation_number LIKE %s)';
            $like = '%' . $wpdb->esc_like((string) $args['search']) . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }
        $sql = 'SELECT * FROM ' . $this->table($table) . " WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
        $params[] = $limit; $params[] = $offset;
        return $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) ?: [];
    }

    public function insert(string $table, array $data): int
    {
        global $wpdb;
        $data['created_at'] = $data['created_at'] ?? $this->now();
        if (! in_array($table, ['payment_logs', 'email_logs'], true)) {
            $data['updated_at'] = $data['updated_at'] ?? $this->now();
        }
        $wpdb->insert($this->table($table), $data);
        return (int) $wpdb->insert_id;
    }

    public function update(string $table, int $id, array $data): bool
    {
        global $wpdb;
        $data['updated_at'] = $data['updated_at'] ?? $this->now();
        return false !== $wpdb->update($this->table($table), $data, ['id' => $id]);
    }

    public function updateWhere(string $table, array $data, array $where): bool
    {
        global $wpdb;
        $data['updated_at'] = $data['updated_at'] ?? $this->now();
        return false !== $wpdb->update($this->table($table), $data, $where);
    }

    public function beginTransaction(): void
    {
        global $wpdb;
        $wpdb->query('START TRANSACTION');
    }

    public function commit(): void
    {
        global $wpdb;
        $wpdb->query('COMMIT');
    }

    public function rollBack(): void
    {
        global $wpdb;
        $wpdb->query('ROLLBACK');
    }

    public function decrementProjectAvailability(int $projectId, float $hectares): bool
    {
        global $wpdb;
        $table = $this->table('projects');
        $now = $this->now();
        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET available_hectares = available_hectares - %f, updated_at = %s WHERE id = %d AND available_hectares >= %f",
            $hectares,
            $now,
            $projectId,
            $hectares
        ));
        return $affected === 1;
    }

    public function nextLandUnitSequence(int $projectId): int
    {
        global $wpdb;
        $table = $this->table('participation_land_units');
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(unit_index), 0) FROM {$table} WHERE project_id = %d FOR UPDATE", $projectId)) + 1;
    }

    public function landUnitsForParticipation(int $participationId): array
    {
        global $wpdb;
        $table = $this->table('participation_land_units');
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE participation_id = %d ORDER BY unit_index ASC", $participationId), ARRAY_A) ?: [];
    }

    public function delete(string $table, int $id): bool
    {
        global $wpdb;
        return false !== $wpdb->delete($this->table($table), ['id' => $id]);
    }

    public function count(string $table, string $where = '1=1'): int
    {
        global $wpdb;
        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $this->table($table) . " WHERE {$where}");
    }
}
