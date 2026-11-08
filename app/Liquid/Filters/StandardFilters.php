<?php

namespace App\Liquid\Filters;

class StandardFilters extends \Keepsuit\Liquid\Filters\StandardFilters
{
    /**
     * Converts any URL-unsafe characters in a string to the
     * [percent-encoded](https://developer.mozilla.org/en-US/docs/Glossary/percent-encoding) equivalent.
     */
    public function urlEncode(mixed $input): string
    {
        if (is_array($input)) {
            $encoded = json_encode($input);
            $input = $encoded === false ? '' : $encoded;
        }

        return parent::urlEncode($input);
    }
}
