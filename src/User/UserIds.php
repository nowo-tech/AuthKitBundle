<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\User;

use Stringable;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

use function is_int;
use function is_string;
use function method_exists;

/**
 * The scalar id of a host user as AuthKit stores it (`(string) getId()`, else the readable `id` property), for events
 * and the social account link (1.41).
 */
final class UserIds
{
    public static function of(object $user, ?PropertyAccessorInterface $propertyAccessor = null): ?string
    {
        if (method_exists($user, 'getId')) {
            $id = $user->getId();
            if (is_int($id) || $id instanceof Stringable || (is_string($id) && $id !== '')) {
                return (string) $id;
            }
        }

        $propertyAccessor ??= PropertyAccess::createPropertyAccessor();
        if ($propertyAccessor->isReadable($user, 'id')) {
            $id = $propertyAccessor->getValue($user, 'id');
            if (is_int($id) || $id instanceof Stringable || (is_string($id) && $id !== '')) {
                return (string) $id;
            }
        }

        return null;
    }
}
