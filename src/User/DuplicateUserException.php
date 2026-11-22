<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\User;

use RuntimeException;

/**
 * Thrown by a {@see UserStoreInterface} implementation when a unique value of the new user is already taken.
 */
class DuplicateUserException extends RuntimeException
{
}
