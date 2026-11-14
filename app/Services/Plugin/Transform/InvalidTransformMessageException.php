<?php

declare(strict_types=1);

namespace App\Services\Plugin\Transform;

use RuntimeException;

/**
 * Aborts a local transform whose runner writes a line that is not a JSON message.
 */
final class InvalidTransformMessageException extends RuntimeException {}
