<?php
declare(strict_types=1);

namespace Oasebos\Participations\Admin;

use Oasebos\Participations\Formatting;
use Oasebos\Participations\Security\Nonces;
use Oasebos\Participations\Security\Sanitizer;

final class ProjectsPage extends BasePage
{
    private const STATUSES = ['draft', 'active', 'paused', 'completed', 'archived'];

    public function render(): void
    {
        $this->guard();

        $errors = [];
        $saved = false;
        $edit = isset($_GET['edit']) ? $this->repo->get('projects', absint($_GET['edit'])) : null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Nonces::verifyAdmin();
            [$data, $errors] = $this->prepareAndValidateProject($_POST, $edit ? (int) $edit['id'] : 0);

            if (! $errors) {
                $id = Sanitizer::int('id', $_POST);
                $id ? $this->repo->update('projects', $id, $data) : $this->repo->insert('projects', $data);
                $this->notice($id ? 'Project bijgewerkt.' : 'Project aangemaakt.');
                $saved = true;
                $edit = $id ? $this->repo->get('projects', $id) : null;
            } else {
                $edit = array_merge($edit ?: [], $data);
                $this->errorNotice($errors);
            }
        }

        $templates = $this->repo->list('templates', ['limit' => 200]);
        $projects = $this->repo->list('projects', ['limit' => 100]);
        $values = $this->projectDefaults($edit ?: []);
        $tab = isset($_GET['tab']) ? sanitize_key((string) wp_unslash($_GET['tab'])) : 'setup';

        echo '<div class="wrap oasebos-admin-page oasebos-projects-page">';
        echo '<div class="oasebos-page-header"><div><h1>' . esc_html($tab === 'preview' ? 'Projectpagina preview' : ($edit ? 'Project bewerken' : 'Project aanmaken')) . '</h1><p>Richt een participatieproject in met duidelijke beschikbaarheid, prijzen, templates en publicatiestatus.</p></div>';
        echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=oasebos-projects')) . '">Nieuw project</a></div>';
        $this->renderTabs($tab);

        if ($tab === 'preview') {
            $this->renderLandingPreview($projects);
        } else {
            $this->renderProjectForm($values, $templates, $errors, $saved);
            $this->renderProjectsTable($projects);
        }
        echo '</div>';
    }

    private function renderTabs(string $active): void
    {
        $tabs = [
            'setup' => 'Projecten beheren',
            'preview' => 'Landingspagina preview',
        ];

        echo '<nav class="nav-tab-wrapper oasebos-project-tabs">';
        foreach ($tabs as $tab => $label) {
            $url = admin_url('admin.php?page=oasebos-projects' . ($tab === 'setup' ? '' : '&tab=' . $tab));
            echo '<a class="nav-tab ' . esc_attr($active === $tab ? 'nav-tab-active' : '') . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';
    }

    private function renderLandingPreview(array $projects): void
    {
        $page = get_page_by_path('participeren');
        $pageUrl = $page instanceof \WP_Post ? get_permalink($page) : home_url('/participeren/');

        echo '<section class="oasebos-card oasebos-admin-landing-preview"><h2>Preview van de participatie landingspagina</h2><div class="oasebos-card__body">';
        echo '<p class="oasebos-muted">Deze preview gebruikt dezelfde actieve projecten als de publieke shortcode <code>[oasebos_participation_landing]</code>. Knoppen zijn hier visueel gemaakt; gebruik de publieke pagina om de echte flow te testen.</p>';
        echo '<p><a class="button button-primary" href="' . esc_url($pageUrl ?: home_url('/participeren/')) . '" target="_blank" rel="noopener">Open publieke landingspagina</a></p>';

        if (! $projects) {
            echo '<p class="oaseulk-empty-state">Nog geen projecten. Maak eerst een actief project om de landingspagina te vullen.</p></div></section>';
            return;
        }

        $activeProjects = array_values(array_filter($projects, static fn (array $project): bool => ($project['status'] ?? '') === 'active'));
        if (! $activeProjects) {
            echo '<p class="oasebos-empty-state">Er zijn projecten, maar nog geen actieve projecten. Alleen actieve projecten verschijnen op de publieke landingspagina.</p></div></section>';
            return;
        }

        echo '<div class="oasebos-admin-preview-shell">';
        echo '<div class="oasebos-admin-preview-header"><div><h3>Kies een project om in te participeren</h3><p>Voeg een eenheid toe aan je mandje en ga daarna door naar het participatieformulier.</p></div><aside><strong>Mandje</strong><span>Nog geen project gekozen.</span><a class="button disabled" href="#">Doorgaan naar formulier</a></aside></div>';
        echo '<div class="oasebos-admin-project-grid">';

        foreach ($activeProjects as $project) {
            $availableUnits = (float) $project['unit_size'] > 0 ? floor((float) $project['available_hectares'] / (float) $project['unit_size']) : 0;
            echo '<article class="oasebos-admin-project-card">';
            echo '<h3>' . esc_html((string) $project['name']) . '</h3>';
            if (! empty($project['location'])) {
                echo '<p class="oasebos-admin-project-location">' . esc_html((string) $project['location']) . '</p>';
            }
            if (! empty($project['description'])) {
                echo '<p>' . esc_html(wp_trim_words((string) $project['description'], 24)) . '</p>';
            }
            echo '<dl><div><dt>Beschikbaar</dt><dd>' . esc_html(Formatting::hectares((float) $project['available_hectares'])) . ' ha</dd></div>';
            echo '<div><dt>Eenheid</dt><dd>' . esc_html(Formatting::hectares((float) $project['unit_size'])) . ' ha</dd></div>';
            echo '<div><dt>Prijs</dt><dd>' . esc_html((string) $project['currency'] . ' ' . number_format_i18n((float) $project['price_per_unit'], 2)) . '</dd></div></dl>';
            echo '<span class="oasebos-admin-add-project ' . esc_attr($availableUnits < 1 ? 'is-disabled' : '') . '">+</span>';
            echo '</article>';
        }

        echo '</div></div></div></section>';
    }

    private function prepareAndValidateProject(array $source, int $currentId): array
    {
        $data = [
            'name' => Sanitizer::text('name', $source),
            'slug' => sanitize_title(Sanitizer::text('slug', $source) ?: Sanitizer::text('name', $source)),
            'location' => Sanitizer::text('location', $source),
            'description' => Sanitizer::textarea('description', $source),
            'total_hectares' => Sanitizer::decimal('total_hectares', $source),
            'available_hectares' => Sanitizer::decimal('available_hectares', $source),
            'unit_size' => Sanitizer::decimal('unit_size', $source, 1),
            'price_per_unit' => Sanitizer::money('price_per_unit', $source),
            'currency' => strtoupper(substr(Sanitizer::text('currency', $source, 'EUR'), 0, 3)),
            'status' => Sanitizer::text('status', $source, 'draft'),
            'agreement_template_id' => Sanitizer::int('agreement_template_id', $source),
            'certificate_template_id' => Sanitizer::int('certificate_template_id', $source),
            'sort_order' => Sanitizer::int('sort_order', $source),
        ];

        $errors = [];
        if ($data['name'] === '') {
            $errors['name'] = 'Projectnaam is verplicht.';
        }
        if ($data['slug'] === '') {
            $errors['slug'] = 'Slug is verplicht.';
        } else {
            $existing = $this->repo->findBy('projects', 'slug', $data['slug']);
            if ($existing && (int) $existing['id'] !== $currentId) {
                $errors['slug'] = 'Deze slug wordt al door een ander project gebruikt.';
            }
        }
        if ($data['total_hectares'] < 0 || $data['available_hectares'] < 0 || $data['unit_size'] <= 0 || $data['price_per_unit'] < 0) {
            $errors['numbers'] = 'Hectares en prijzen moeten positieve waarden zijn. Eenheidsgrootte moet groter zijn dan nul.';
        }
        if ($data['available_hectares'] > $data['total_hectares']) {
            $errors['available_hectares'] = 'Beschikbare hectares mogen niet groter zijn dan het totaal aantal hectares.';
        }
        if (! in_array($data['status'], self::STATUSES, true)) {
            $errors['status'] = 'Kies een geldige status.';
        }
        if (! preg_match('/^[A-Z]{3}$/', $data['currency'])) {
            $errors['currency'] = 'Valuta moet een code van 3 letters zijn, bijvoorbeeld EUR.';
        }

        return [$data, $errors];
    }

    private function projectDefaults(array $project): array
    {
        return array_merge([
            'id' => 0,
            'name' => '',
            'slug' => '',
            'location' => '',
            'description' => '',
            'total_hectares' => '',
            'available_hectares' => '',
            'unit_size' => '1',
            'price_per_unit' => '',
            'currency' => 'EUR',
            'status' => 'draft',
            'agreement_template_id' => 0,
            'certificate_template_id' => 0,
            'sort_order' => 0,
        ], $project);
    }

    private function renderProjectForm(array $values, array $templates, array $errors, bool $saved): void
    {
        echo '<form method="post" class="oasebos-project-form" data-oasebos-project-form>';
        wp_nonce_field(Nonces::ADMIN_ACTION);
        if (! empty($values['id'])) {
            echo '<input type="hidden" name="id" value="' . esc_attr((string) $values['id']) . '">';
        }

        echo '<div class="oasebos-edit-layout"><main class="oasebos-edit-main">';
        $this->renderCard('Projectgegevens', function () use ($values, $errors): void {
            $this->input('name', 'Projectnaam', $values, $errors, ['required' => true, 'placeholder' => 'Amazon rainforest restoration']);
            $this->input('slug', 'Slug', $values, $errors, ['required' => true, 'placeholder' => 'amazon-rainforest-restoration', 'class' => 'regular-text', 'attrs' => 'data-oasebos-slug']);
            $this->input('location', 'Locatie', $values, $errors, ['placeholder' => 'Brazil, Pará']);
            $this->input('sort_order', 'Sorteervolgorde', $values, $errors, ['type' => 'number', 'help' => 'Lagere nummers kunnen eerder worden getoond in projectlijsten.']);
        });

        $this->renderCard('Beschikbaarheid grond', function () use ($values, $errors): void {
            echo '<div class="oasebos-field-grid oasebos-field-grid--two">';
            $this->input('total_hectares', 'Totaal hectares', $values, $errors, ['type' => 'number', 'step' => '0.0001', 'min' => '0', 'required' => true]);
            $this->input('available_hectares', 'Beschikbare hectares', $values, $errors, ['type' => 'number', 'step' => '0.0001', 'min' => '0', 'required' => true]);
            echo '</div><div class="oasebos-meter"><span data-oasebos-availability-bar></span></div><p class="description" data-oasebos-availability-text>Beschikbaarheid wordt bijgewerkt tijdens het typen.</p>';
        });

        $this->renderCard('Prijzen', function () use ($values, $errors): void {
            echo '<div class="oasebos-field-grid oasebos-field-grid--three">';
            $this->input('unit_size', 'Eenheidsgrootte in hectares', $values, $errors, ['type' => 'number', 'step' => '0.0001', 'min' => '0.0001', 'required' => true]);
            $this->input('price_per_unit', 'Prijs per eenheid', $values, $errors, ['type' => 'number', 'step' => '0.01', 'min' => '0', 'required' => true]);
            $this->input('currency', 'Valuta', $values, $errors, ['maxlength' => '3', 'required' => true]);
            echo '</div><div class="oasebos-calculation-cards"><div><strong data-oasebos-units>—</strong><span>beschikbare eenheden</span></div><div><strong data-oasebos-price-hectare>—</strong><span>prijs per hectare</span></div></div>';
        });

        $this->renderCard('Beschrijving', function () use ($values): void {
            echo '<label class="oasebos-field"><span>Beschrijving</span><textarea class="large-text" rows="7" name="description" placeholder="Leg uit wat dit project beschermt of herstelt en waarom mensen zouden participeren.">' . esc_textarea((string) $values['description']) . '</textarea><em>Wordt getoond in projectinformatie en gebruikt als context in communicatie met deelnemers.</em></label>';
        });
        echo '</main><aside class="oasebos-edit-sidebar">';

        $this->renderCard('Publiceren', function () use ($values, $saved): void {
            echo '<label class="oasebos-field"><span>Status</span><select name="status">';
            foreach (self::STATUSES as $status) {
                echo '<option value="' . esc_attr($status) . '" ' . selected($values['status'], $status, false) . '>' . esc_html(ucfirst($status)) . '</option>';
            }
            echo '</select><em>Gebruik concept tijdens voorbereiding. Actieve projecten kunnen publiek worden getoond.</em></label>';
            submit_button(! empty($values['id']) ? 'Project bijwerken' : 'Project opslaan', 'primary large', 'submit', false);
            if ($saved) {
                echo '<p class="oasebos-save-state">Succesvol opgeslagen.</p>';
            }
        }, 'oasebos-sticky-card');

        $this->renderCard('Templates', function () use ($values, $templates): void {
            $this->templateSelect('agreement_template_id', 'Overeenkomsttemplate', (int) $values['agreement_template_id'], $templates, 'agreement');
            $this->templateSelect('certificate_template_id', 'Certificaattemplate', (int) $values['certificate_template_id'], $templates, 'certificate');
        });

        if ($errors) {
            $this->renderCard('Aandacht vereist', function () use ($errors): void {
                echo '<ul class="oasebos-error-list">';
                foreach ($errors as $message) {
                    echo '<li>' . esc_html($message) . '</li>';
                }
                echo '</ul>';
            });
        }
        echo '</aside></div></form>';
    }

    private function renderCard(string $title, callable $content, string $class = ''): void
    {
        echo '<section class="oasebos-card ' . esc_attr($class) . '"><h2>' . esc_html($title) . '</h2><div class="oasebos-card__body">';
        $content();
        echo '</div></section>';
    }

    private function input(string $name, string $label, array $values, array $errors, array $args = []): void
    {
        $type = $args['type'] ?? 'text';
        $class = 'oasebos-field ' . (isset($errors[$name]) || ($name === 'total_hectares' && isset($errors['numbers'])) ? 'has-error' : '');
        $attrs = $args['attrs'] ?? '';
        foreach (['placeholder', 'step', 'min', 'maxlength'] as $attr) {
            if (isset($args[$attr])) {
                $attrs .= ' ' . $attr . '="' . esc_attr((string) $args[$attr]) . '"';
            }
        }
        if (! empty($args['required'])) {
            $attrs .= ' required';
        }
        echo '<label class="' . esc_attr($class) . '"><span>' . esc_html($label) . (! empty($args['required']) ? ' <b>*</b>' : '') . '</span><input type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" value="' . esc_attr((string) ($values[$name] ?? '')) . '" ' . $attrs . '>';
        if (! empty($args['help'])) {
            echo '<em>' . esc_html($args['help']) . '</em>';
        }
        if (isset($errors[$name])) {
            echo '<strong>' . esc_html($errors[$name]) . '</strong>';
        }
        echo '</label>';
    }

    private function templateSelect(string $name, string $label, int $selected, array $templates, string $type): void
    {
        echo '<label class="oasebos-field"><span>' . esc_html($label) . '</span><select name="' . esc_attr($name) . '"><option value="0">— Selecteer template —</option>';
        foreach ($templates as $template) {
            if (($template['type'] ?? '') !== $type) {
                continue;
            }
            echo '<option value="' . esc_attr((string) $template['id']) . '" ' . selected($selected, (int) $template['id'], false) . '>' . esc_html($template['name']) . '</option>';
        }
        echo '</select><em>Optioneel, maar aanbevolen vóór publicatie.</em></label>';
    }

    private function renderProjectsTable(array $projects): void
    {
        echo '<section class="oasebos-card oasebos-project-list"><h2>Bestaande projecten</h2>';
        if (! $projects) {
            echo '<p class="oasebos-empty-state">Nog geen projecten. Maak hierboven je eerste project.</p></section>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>Project</th><th>Status</th><th>Beschikbaarheid</th><th>Prijs</th><th>Templates</th><th></th></tr></thead><tbody>';
        foreach ($projects as $project) {
            $total = (float) $project['total_hectares'];
            $available = (float) $project['available_hectares'];
            $percentage = $total > 0 ? round(($available / $total) * 100) : 0;
            $editUrl = admin_url('admin.php?page=oasebos-projects&edit=' . absint($project['id']));
            echo '<tr><td><strong>' . esc_html($project['name']) . '</strong><br><code>' . esc_html($project['slug']) . '</code></td>';
            echo '<td><span class="oasebos-status oasebos-status--' . esc_attr($project['status']) . '">' . esc_html(ucfirst($project['status'])) . '</span></td>';
            echo '<td>' . esc_html(Formatting::hectares($available) . ' / ' . Formatting::hectares($total) . ' ha') . '<div class="oasebos-mini-meter"><span style="width:' . esc_attr((string) min(100, $percentage)) . '%"></span></div></td>';
            echo '<td>' . esc_html($project['currency'] . ' ' . number_format_i18n((float) $project['price_per_unit'], 2)) . '</td>';
            echo '<td>' . esc_html(((int) $project['agreement_template_id'] ? 'Overeenkomst' : '—') . ' / ' . ((int) $project['certificate_template_id'] ? 'Certificaat' : '—')) . '</td>';
            echo '<td><a class="button button-small" href="' . esc_url($editUrl) . '">Bewerken</a></td></tr>';
        }
        echo '</tbody></table></section>';
    }

    private function errorNotice(array $errors): void
    {
        echo '<div class="notice notice-error"><p><strong>Project is niet opgeslagen.</strong> Controleer de gemarkeerde velden.</p><ul>';
        foreach ($errors as $message) {
            echo '<li>' . esc_html($message) . '</li>';
        }
        echo '</ul></div>';
    }
}
