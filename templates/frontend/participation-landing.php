<?php if (empty($projects)): ?>
    <p class="oasebos-message"><?php esc_html_e('Er zijn momenteel geen actieve projecten beschikbaar.', 'oasebos-participations'); ?></p>
    <?php return; ?>
<?php endif; ?>

<?php
$defaultFormUrl = get_permalink() ?: home_url('/');
$projectsById = [];
foreach ($projects as $candidateProject) { $projectsById[(int) $candidateProject['id']] = $candidateProject; }
$basketItems = [];
$basketParam = isset($_GET['basket']) ? sanitize_text_field(wp_unslash((string) $_GET['basket'])) : '';
foreach (array_filter(array_map('trim', explode(',', $basketParam))) as $basketPart) {
    [$rawProjectId, $rawUnits] = array_pad(explode(':', $basketPart, 2), 2, '1');
    $projectId = absint($rawProjectId);
    if ($projectId > 0 && isset($projectsById[$projectId])) {
        $basketItems[$projectId] = max(1, absint($rawUnits));
    }
}
$legacyProjectId = isset($_GET['basket_project_id']) ? absint($_GET['basket_project_id']) : 0;
if (! $basketItems && $legacyProjectId > 0 && isset($projectsById[$legacyProjectId])) {
    $basketItems[$legacyProjectId] = isset($_GET['units']) ? max(1, absint($_GET['units'])) : 1;
}
$proceedUrl = '#';
if ($basketItems) {
    $basketValue = implode(',', array_map(static fn (int $projectId, int $units): string => $projectId . ':' . $units, array_keys($basketItems), $basketItems));
    $proceedUrl = add_query_arg(['basket' => $basketValue], $formPageUrl ?: $defaultFormUrl);
}
?>
<div class="oasebos-participation-landing" data-oasebos-landing data-form-url="<?php echo esc_url($formPageUrl ?: $defaultFormUrl); ?>">
    <div class="oasebos-landing-layout">
        <aside class="oasebos-basket" data-oasebos-basket>
            <div class="oasebos-basket__header"><strong><?php esc_html_e('Het mandje', 'oasebos-participations'); ?></strong><span data-oasebos-basket-count aria-live="polite"><?php echo esc_html((string) array_sum($basketItems)); ?></span></div>
            <ul class="oasebos-basket__items" data-oasebos-basket-items>
                <?php if ($basketItems): ?>
                    <?php foreach ($basketItems as $projectId => $units): $itemProject = $projectsById[$projectId]; ?>
                        <?php $totalHectares = $units * (float) $itemProject['unit_size']; $itemTotal = $units * (float) $itemProject['price_per_unit']; $itemCost = trim((string) $itemProject['currency'] . ' ' . number_format_i18n($itemTotal, 2)); ?>
                        <li data-basket-project-id="<?php echo esc_attr((string) $projectId); ?>"><span><?php echo esc_html((string) $itemProject['name']); ?><small><span class="oasebos-unit-count"><?php echo esc_html(sprintf(_n('%d eenheid', '%d eenheden', (int) $units, 'oasebos-participations'), (int) $units)); ?></span> · <?php echo esc_html(\Oasebos\Participations\Formatting::hectares($totalHectares)); ?> ha</small></span><strong><?php echo esc_html($itemCost); ?></strong></li>
                    <?php endforeach; ?>
                <?php else: ?>
                    <li class="oasebos-basket__empty" data-oasebos-basket-empty><?php esc_html_e('Je mandje is nog leeg.', 'oasebos-participations'); ?></li>
                <?php endif; ?>
            </ul>
            <a class="button oasebos-proceed <?php echo $basketItems ? '' : 'is-disabled'; ?>" href="<?php echo esc_url($proceedUrl); ?>" data-oasebos-proceed aria-disabled="<?php echo $basketItems ? 'false' : 'true'; ?>"><?php echo esc_html((string) $atts['button_label']); ?></a>
        </aside>

        <div class="oasebos-project-grid">
        <?php foreach ($projects as $project): ?>
            <?php
            $availableUnits = (float) $project['unit_size'] > 0 ? floor((float) $project['available_hectares'] / (float) $project['unit_size']) : 0;
            $price = trim((string) $project['currency'] . ' ' . number_format_i18n((float) $project['price_per_unit'], 2));
            ?>
            <?php $addUrl = add_query_arg(['basket' => (int) $project['id'] . ':1'], $defaultFormUrl); ?>
            <article class="oasebos-project-card <?php echo isset($basketItems[(int) $project['id']]) ? 'is-selected' : ''; ?> <?php echo $availableUnits >= 1 ? '' : 'is-unavailable'; ?>" data-project-id="<?php echo esc_attr((string) $project['id']); ?>" data-project-name="<?php echo esc_attr((string) $project['name']); ?>" data-project-unit-size="<?php echo esc_attr((string) $project['unit_size']); ?>" data-project-price="<?php echo esc_attr((string) $project['price_per_unit']); ?>" data-project-currency="<?php echo esc_attr((string) $project['currency']); ?>" data-project-units="<?php echo esc_attr((string) ($basketItems[(int) $project['id']] ?? 0)); ?>">
                <div class="oasebos-project-card__body">
                    <div class="oasebos-project-card__header">
                        <h3><?php echo esc_html((string) $project['name']); ?></h3>
                        <?php if ($availableUnits < 1): ?><span class="oasebos-project-card__status"><?php esc_html_e('Uitverkocht', 'oasebos-participations'); ?></span><?php endif; ?>
                    </div>
                    <?php if (! empty($project['location'])): ?><p class="oasebos-project-location"><span aria-hidden="true">📍</span><?php echo esc_html((string) $project['location']); ?></p><?php endif; ?>
                    <?php if (! empty($project['description'])): ?><p class="oasebos-project-card__description"><?php echo esc_html(wp_trim_words((string) $project['description'], 28)); ?></p><?php endif; ?>
                    <div class="oasebos-project-card__price"><strong><?php echo esc_html($price); ?></strong><span><?php echo esc_html(sprintf(__('voor %s ha', 'oasebos-participations'), \Oasebos\Participations\Formatting::hectares((float) $project['unit_size']))); ?></span></div>
                    <dl class="oasebos-project-meta">
                        <div><dt><?php esc_html_e('Nog beschikbaar', 'oasebos-participations'); ?></dt><dd data-oasebos-project-available data-oasebos-project-available-total="<?php echo esc_attr((string) $project['available_hectares']); ?>"><?php echo esc_html(\Oasebos\Participations\Formatting::hectares((float) $project['available_hectares'])); ?> ha</dd></div>
                    </dl>
                </div>
                <div class="oasebos-project-card__actions">
                    <?php if ($availableUnits >= 1): ?>
                        <a class="oasebos-add-project" href="<?php echo esc_url($addUrl); ?>" data-oasebos-add-project aria-label="<?php echo esc_attr(sprintf(__('Voeg een eenheid van %s toe', 'oasebos-participations'), (string) $project['name'])); ?>"><?php esc_html_e('Toevoegen', 'oasebos-participations'); ?></a>
                        <div class="oasebos-project-quantity" data-oasebos-project-quantity <?php echo isset($basketItems[(int) $project['id']]) ? '' : 'hidden'; ?>>
                            <button type="button" data-oasebos-decrease-project aria-label="<?php echo esc_attr(sprintf(__('Verwijder een eenheid van %s', 'oasebos-participations'), (string) $project['name'])); ?>">−</button>
                            <span data-oasebos-project-quantity-count><?php echo esc_html((string) ($basketItems[(int) $project['id']] ?? 0)); ?></span>
                            <button type="button" data-oasebos-increase-project aria-label="<?php echo esc_attr(sprintf(__('Voeg nog een eenheid van %s toe', 'oasebos-participations'), (string) $project['name'])); ?>">+</button>
                        </div>
                    <?php else: ?>
                        <button type="button" class="oasebos-add-project is-disabled" disabled aria-label="<?php echo esc_attr(sprintf(__('Geen eenheden beschikbaar voor %s', 'oasebos-participations'), (string) $project['name'])); ?>"><?php esc_html_e('Niet beschikbaar', 'oasebos-participations'); ?></button>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
        </div>
    </div>
</div>
