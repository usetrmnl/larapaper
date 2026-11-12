<?php

declare(strict_types=1);

namespace App\Services\Plugin\Transform;

final class TransformResult
{
    /**
     * @param  array<int|string, mixed>|null  $output
     */
    public function __construct(
        public readonly ?array $output,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly int $exitCode,
        public readonly int $durationMs,
        public readonly ?string $error = null,
    ) {}

    public function succeeded(): bool
    {
        return $this->error === null && $this->exitCode === 0 && $this->output !== null;
    }
}
