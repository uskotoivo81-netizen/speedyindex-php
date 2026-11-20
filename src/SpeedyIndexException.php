<?php

declare(strict_types=1);

namespace SpeedyIndex;

/** Error returned by the SpeedyIndex API or the HTTP layer. */
class SpeedyIndexException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int|string|null $apiCode = null,
        public readonly ?int $status = null,
        public readonly ?string $error = null,
        public readonly mixed $payload = null,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }
}
