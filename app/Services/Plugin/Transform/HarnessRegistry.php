<?php

declare(strict_types=1);

namespace App\Services\Plugin\Transform;

use App\Services\Plugin\Transform\LanguageHarness\HarnessInterface;
use App\Services\Plugin\Transform\LanguageHarness\PhpHarness;
use InvalidArgumentException;

final class HarnessRegistry
{
    /**
     * Resolve a language key to its harness. Future languages (node, ruby, python)
     * register here without any change to the orchestration in TransformService.
     */
    public function for(string $language): HarnessInterface
    {
        return match (mb_strtolower(mb_trim($language))) {
            'php' => new PhpHarness,
            default => throw new InvalidArgumentException("Unsupported transform language [{$language}]"),
        };
    }
}
