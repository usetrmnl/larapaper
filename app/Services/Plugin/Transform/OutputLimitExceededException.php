<?php

declare(strict_types=1);

namespace App\Services\Plugin\Transform;

use RuntimeException;

/**
 * Aborts a local transform whose stdout/stderr grows beyond the configured limit.
 */
final class OutputLimitExceededException extends RuntimeException {}
