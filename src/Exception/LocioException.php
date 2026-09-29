<?php

declare(strict_types=1);

namespace Locio\Exception;

/**
 * What the API said when it refused.
 *
 * The service answers RFC 7807 problem documents: a title and a detail written
 * for a person to read and act on, such as which key is wrong. Collapsing
 * that into "HTTP 403" throws away the only part of the answer that says what
 * to do about it.
 */
class LocioException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $title = '',
        public readonly string $detail = '',
        ?\Throwable $previous = null,
    ) {
        $message = $title !== '' ? $title : "HTTP $status";
        if ($detail !== '') {
            $message .= ": $detail";
        }
        parent::__construct($message, $status, $previous);
    }
}
