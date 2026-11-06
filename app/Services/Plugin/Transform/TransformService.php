<?php

declare(strict_types=1);

namespace App\Services\Plugin\Transform;

use InvalidArgumentException;

final class TransformService
{
    public function execute(string $language, string $code, array $input): TransformResult
    {
        return $this->provider()->execute($language, $code, $input);
    }

    /** @return list<string> */
    public function languages(): array
    {
        return $this->provider()->languages();
    }

    private function provider(): TransformProvider
    {
        $provider = config('services.transform.provider', 'local');

        return match ($provider) {
            'local' => app(LocalTransformProvider::class),
            'http' => app(HttpTransformProvider::class),
            default => throw new InvalidArgumentException('Unknown transform provider'),
        };
    }
}
