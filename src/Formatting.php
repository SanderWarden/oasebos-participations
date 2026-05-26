<?php
declare(strict_types=1);

namespace Oasebos\Participations;

final class Formatting
{
    public static function hectares(float $value, int $maxDecimals = 4): string
    {
        $formatted = number_format_i18n($value, $maxDecimals);
        $decimalSeparator = (string) (number_format_i18n(1.1, 1)[1] ?? '.');
        $thousandsSeparator = $decimalSeparator === ',' ? '.' : ',';

        if (str_contains($formatted, $decimalSeparator)) {
            $formatted = rtrim(rtrim($formatted, '0'), $decimalSeparator);
        }

        return str_replace($thousandsSeparator, '', $formatted);
    }
}
