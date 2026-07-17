<?php
declare(strict_types=1);

namespace DRESearch\Search\Exception;

use RuntimeException;

final class RequestValidationException extends RuntimeException
{
    public function __construct(
        private readonly string $publicCode,
        string $message,
        private readonly int $status = 400,
    ) {
        parent::__construct($message);
    }

    public function publicCode(): string
    {
        return $this->publicCode;
    }

    public function status(): int
    {
        return $this->status;
    }
}
