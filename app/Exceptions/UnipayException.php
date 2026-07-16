<?php

namespace App\Exceptions;

use RuntimeException;

class UnipayException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly ?array $body = null,
    ) {
        parent::__construct($message);
    }
}