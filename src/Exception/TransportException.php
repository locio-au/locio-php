<?php

declare(strict_types=1);

namespace Locio\Exception;

/** No answer arrived: the connection failed, timed out, or was cut off. */
final class TransportException extends LocioException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct(0, $message, '', $previous);
    }
}
