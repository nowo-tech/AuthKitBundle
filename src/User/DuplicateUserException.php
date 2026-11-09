<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\User;

use RuntimeException;

/**
 * Thrown by a {@see UserWriterInterface::add()} implementation when a unique value of the new user is already taken.
 */
class DuplicateUserException extends RuntimeException
{
}
