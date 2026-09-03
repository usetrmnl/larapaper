<?php

namespace App\Liquid\Filters;

use Illuminate\Support\Number;
use Keepsuit\Liquid\Filters\FiltersProvider;

class Numbers extends FiltersProvider
{
    /**
     * Format a number with delimiters (default: comma)
     *
     * @param  mixed  $value  The number to format
     * @param  string  $delimiter  The delimiter to use (default: comma)
     * @param  string  $separator  The separator for decimal part (default: period)
     */
    public function number_with_delimiter(mixed $value, string $delimiter = ',', string $separator = '.'): string
    {
        // 2 decimal places for floats, 0 for integers
        $decimal = is_float($value + 0) ? 2 : 0;

        return number_format($value, decimals: $decimal, decimal_separator: $separator, thousands_separator: $delimiter);
    }

    /**
     * Format a number as currency
     *
     * @param  mixed  $value  The number to format
     * @param  string  $currency  Currency symbol or locale code
     * @param  string  $delimiter  The delimiter to use (default: comma)
     * @param  string  $separator  The separator for decimal part (default: period)
     */
    /**
     * Generate a random integer within an inclusive range
     *
     * @param  mixed  $_value  Piped value (ignored)
     * @param  mixed  $min  When alone, treated as upper bound (0–$min). With $max, treated as lower bound.
     * @param  mixed  $max  Upper bound of the range
     */
    public function random_number(mixed $_value, mixed $min = null, mixed $max = null): int
    {
        [$lower, $upper] = $this->randomBounds($min, $max);

        return random_int($lower, $upper);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function randomBounds(mixed $min, mixed $max): array
    {
        if ($min === null) {
            return [0, 100];
        }

        $lower = (int) $min;

        if ($max === null) {
            return [0, $lower];
        }

        return [min($lower, (int) $max), max($lower, (int) $max)];
    }

    /**
     * Format a number as currency
     *
     * @param  mixed  $value  The number to format
     * @param  string  $currency  Currency symbol or locale code
     * @param  string  $delimiter  The delimiter to use (default: comma)
     * @param  string  $separator  The separator for decimal part (default: period)
     */
    public function number_to_currency(mixed $value, string $currency = 'USD', string $delimiter = ',', string $separator = '.'): string
    {
        if ($currency === '$') {
            $currency = 'USD';
        } elseif ($currency === '€') {
            $currency = 'EUR';
        } elseif ($currency === '£') {
            $currency = 'GBP';
        }

        $locale = $delimiter === '.' && $separator === ',' ? 'de' : 'en';

        // 2 decimal places for floats, 0 for integers
        $decimal = is_float($value + 0) ? 2 : 0;

        return Number::currency($value, in: $currency, locale: $locale, precision: $decimal);
    }
}
