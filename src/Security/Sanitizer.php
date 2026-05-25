<?php
declare(strict_types=1);

namespace Oasebos\Participations\Security;

final class Sanitizer
{
    public static function text(string $key, array $source, string $default = ''): string
    {
        return isset($source[$key]) ? sanitize_text_field(wp_unslash($source[$key])) : $default;
    }

    public static function textarea(string $key, array $source, string $default = ''): string
    {
        return isset($source[$key]) ? sanitize_textarea_field(wp_unslash($source[$key])) : $default;
    }

    public static function html(string $key, array $source, string $default = ''): string
    {
        return isset($source[$key]) ? wp_kses_post(wp_unslash($source[$key])) : $default;
    }

    public static function int(string $key, array $source, int $default = 0): int
    {
        return isset($source[$key]) ? max(0, absint($source[$key])) : $default;
    }

    public static function money(string $key, array $source, float $default = 0.0): float
    {
        if (! isset($source[$key])) {
            return $default;
        }
        return max(0.0, round((float) str_replace(',', '.', sanitize_text_field(wp_unslash($source[$key]))), 2));
    }

    public static function decimal(string $key, array $source, float $default = 0.0): float
    {
        if (! isset($source[$key])) {
            return $default;
        }
        return max(0.0, round((float) str_replace(',', '.', sanitize_text_field(wp_unslash($source[$key]))), 4));
    }
}
