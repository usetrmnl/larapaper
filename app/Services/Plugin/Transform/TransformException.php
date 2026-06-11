<?php

declare(strict_types=1);

namespace App\Services\Plugin\Transform;

use RuntimeException;

final class TransformException extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $diagnostics = null)
    {
        parent::__construct($message);
    }
}
