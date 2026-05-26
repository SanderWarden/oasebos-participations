<form class="oasebos-form oasebos-donation-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <input type="hidden" name="action" value="oasebos_donation">
    <?php wp_nonce_field(\Oasebos\Participations\Security\Nonces::FRONTEND_ACTION, 'oasebos_nonce'); ?>
    <p><label><?php esc_html_e('Bedrag', 'oasebos-participations'); ?><select name="amount" data-oasebos-donation-amount><?php foreach($amounts as $amount): ?><option value="<?php echo esc_attr((string) $amount); ?>">€<?php echo esc_html(number_format_i18n((float) $amount, (float) $amount === floor((float) $amount) ? 0 : 2)); ?></option><?php endforeach; ?><option value="custom"><?php esc_html_e('Ander bedrag', 'oasebos-participations'); ?></option></select></label></p>
    <p data-oasebos-custom-donation style="display:none"><label><?php esc_html_e('Eigen bedrag', 'oasebos-participations'); ?><input type="number" min="2.50" step="0.01" name="custom_amount" placeholder="€" disabled></label></p>
    <p><label class="oasebos-checkbox-label"><input type="checkbox" name="is_monthly" value="1"> <?php esc_html_e('Maandelijkse donatie', 'oasebos-participations'); ?></label></p>
    <p><label><?php esc_html_e('Voornaam', 'oasebos-participations'); ?><input name="first_name"></label></p>
    <p><label><?php esc_html_e('Achternaam', 'oasebos-participations'); ?><input name="last_name"></label></p>
    <p><label><?php esc_html_e('E-mail', 'oasebos-participations'); ?><input type="email" name="email" required></label></p>
    <p><label class="oasebos-checkbox-label"><input type="checkbox" name="newsletter_opt_in" value="1"> <?php esc_html_e('Blijf op de hoogte met de nieuwsbrief', 'oasebos-participations'); ?></label></p>
    <p><button type="submit"><?php esc_html_e('Doneren', 'oasebos-participations'); ?></button></p>
</form>
