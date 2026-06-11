<?php

declare(strict_types=1);

namespace App\Services\Plugin\Transform;

interface TransformProvider
{
    /**
     * Execute the user's transform code against the prepared input.
     *
     * @param  array<int|string, mixed>  $input  Parsed polling payload plus the `trmnl` context.
     */
    public function execute(string $language, string $code, array $input): TransformResult;

    /**
     * Language keys this provider can execute.
     *
     * @return list<string>
     */
    public function languages(): array;
}
