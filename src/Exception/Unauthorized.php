<?php

declare(strict_types=1);

namespace Locio\Exception;

/** The key was refused: missing, revoked, or a public key used from an origin its allow list does not name. The detail says which. */
final class Unauthorized extends LocioException
{
}
