<?php
declare(strict_types=1);

namespace Oasebos\Participations\Frontend;

final class Pages
{
    private const LANDING_PAGE_OPTION = 'oasebos_participation_landing_page_id';
    private const FORM_PAGE_OPTION = 'oasebos_participation_form_page_id';

    public function register(): void
    {
        add_action('init', [$this, 'ensureLandingPage'], 20);
    }

    public function ensureLandingPage(): void
    {
        $formPageId = $this->ensurePage(
            self::FORM_PAGE_OPTION,
            'participatie-formulier',
            __('Participatie formulier', 'oasebos-participations'),
            '[oasebos_participation_form]',
            'oasebos_participation_form'
        );
        $landingShortcode = '[oasebos_participation_landing button_label="Koop participatie"]';

        $pageId = (int) get_option(self::LANDING_PAGE_OPTION, 0);
        if ($pageId > 0 && get_post_status($pageId)) {
            if (get_page_template_slug($pageId) !== 'default') {
                update_post_meta($pageId, '_wp_page_template', 'default');
            }
            if (! has_shortcode((string) get_post_field('post_content', $pageId), 'oasebos_participation_landing')) {
                wp_update_post([
                    'ID' => $pageId,
                    'post_content' => $landingShortcode,
                ]);
            }
            return;
        }

        $existing = get_page_by_path('participeren');
        if ($existing instanceof \WP_Post) {
            update_option(self::LANDING_PAGE_OPTION, (int) $existing->ID);
            update_post_meta((int) $existing->ID, '_wp_page_template', 'default');
            if (! has_shortcode((string) $existing->post_content, 'oasebos_participation_landing')) {
                wp_update_post([
                    'ID' => (int) $existing->ID,
                    'post_content' => $landingShortcode,
                ]);
            }
            return;
        }

        $createdId = wp_insert_post([
            'post_title' => __('Participeren', 'oasebos-participations'),
            'post_name' => 'participeren',
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_content' => $landingShortcode,
            'comment_status' => 'closed',
            'ping_status' => 'closed',
        ], true);

        if (! is_wp_error($createdId) && $createdId) {
            update_option(self::LANDING_PAGE_OPTION, (int) $createdId);
            update_post_meta((int) $createdId, '_wp_page_template', 'default');
        }
    }

    private function ensurePage(string $option, string $slug, string $title, string $content, string $shortcode): int
    {
        $pageId = (int) get_option($option, 0);
        if ($pageId > 0 && get_post_status($pageId)) {
            if (! has_shortcode((string) get_post_field('post_content', $pageId), $shortcode)) {
                wp_update_post(['ID' => $pageId, 'post_content' => $content]);
            }
            update_post_meta($pageId, '_wp_page_template', 'default');
            return $pageId;
        }

        $existing = get_page_by_path($slug);
        if ($existing instanceof \WP_Post) {
            update_option($option, (int) $existing->ID);
            if (! has_shortcode((string) $existing->post_content, $shortcode)) {
                wp_update_post(['ID' => (int) $existing->ID, 'post_content' => $content]);
            }
            update_post_meta((int) $existing->ID, '_wp_page_template', 'default');
            return (int) $existing->ID;
        }

        $createdId = wp_insert_post([
            'post_title' => $title,
            'post_name' => $slug,
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_content' => $content,
            'comment_status' => 'closed',
            'ping_status' => 'closed',
        ], true);

        if (is_wp_error($createdId) || ! $createdId) {
            return 0;
        }

        update_option($option, (int) $createdId);
        update_post_meta((int) $createdId, '_wp_page_template', 'default');
        return (int) $createdId;
    }
}
