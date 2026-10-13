<?php

declare(strict_types=1);

namespace Filagora\Cms\Exceptions;

use RuntimeException;

/**
 * A draft operation that cannot be done: there is no such draft or variant (404), or nothing
 * to publish (409). The message is safe to show an editor; `status` is the HTTP status a
 * frontend would answer with.
 */
class DraftOperationFailed extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 409)
    {
        parent::__construct($message);
    }

    public static function notFound(string $message): self
    {
        return new self($message, 404);
    }

    public static function conflict(string $message): self
    {
        return new self($message, 409);
    }
}
