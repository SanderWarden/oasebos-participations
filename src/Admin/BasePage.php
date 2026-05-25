<?php
declare(strict_types=1);

namespace Oasebos\Participations\Admin;

use Oasebos\Participations\Database\Repository;
use Oasebos\Participations\Security\Capabilities;

abstract class BasePage
{
    protected Repository $repo;

    public function __construct() { $this->repo = new Repository(); }

    protected function guard(): void
    {
        Capabilities::requireManage();
        if (isset($_GET['oasebos_notice'])) {
            $this->notice($_GET['oasebos_notice'] === 'done' ? __('Actie voltooid.', 'oasebos-participations') : __('Actie mislukt. Controleer betalings-/e-maillogs voor details.', 'oasebos-participations'));
        }
    }

    protected function header(string $title, string $description, array $actions = []): void
    {
        echo '<div class="oasebos-page-header"><div><h1>' . esc_html($title) . '</h1><p>' . esc_html($description) . '</p></div>';
        if ($actions) {
            echo '<div class="oasebos-header-actions">';
            foreach ($actions as $label => $url) {
                echo '<a class="button" href="' . esc_url((string) $url) . '">' . esc_html((string) $label) . '</a>';
            }
            echo '</div>';
        }
        echo '</div>';
    }

    protected function card(string $title, callable $content, string $class = ''): void
    {
        echo '<section class="oasebos-card ' . esc_attr($class) . '"><h2>' . esc_html($title) . '</h2><div class="oasebos-card__body">';
        $content();
        echo '</div></section>';
    }

    protected function table(array $rows): void
    {
        if (! $rows) {
            echo '<p class="oasebos-empty-state">Geen records gevonden.</p>';
            return;
        }
        echo '<div class="oasebos-table-wrap"><table class="widefat striped oasebos-table"><thead><tr>';
        foreach (array_keys($rows[0]) as $header) {
            echo '<th>' . esc_html($this->label((string) $header)) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr>';
            foreach ($row as $value) {
                echo '<td>' . esc_html(is_scalar($value) ? (string) $value : wp_json_encode($value)) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    protected function notice(string $msg): void { echo '<div class="notice notice-success"><p>' . esc_html($msg) . '</p></div>'; }

    protected function label(string $key): string
    {
        return [
            'id' => 'ID', 'created_at' => 'Aangemaakt', 'updated_at' => 'Bijgewerkt', 'status' => 'Status', 'type' => 'Type',
            'entity_type' => 'Type record', 'entity_id' => 'Record-ID', 'mollie_payment_id' => 'Mollie-betaling', 'mollie_customer_id' => 'Mollie-klant',
            'event_type' => 'Gebeurtenis', 'payload' => 'Payload', 'message' => 'Bericht', 'recipient_email' => 'Ontvanger', 'subject' => 'Onderwerp', 'error_message' => 'Foutmelding',
        ][$key] ?? ucwords(str_replace('_', ' ', $key));
    }
}
