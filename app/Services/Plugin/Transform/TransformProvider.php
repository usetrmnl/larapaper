<?php

declare(strict_types=1);

namespace App\Services\Plugin\Transform;

interface TransformProvider
{
    /** @param array<int|string, mixed> $input */
    public function execute(string $language, string $code, array $input): TransformResult;

    /** @return list<string> */
    public function languages(): array;
}
