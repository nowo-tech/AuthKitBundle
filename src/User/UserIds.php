<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\User;

use Stringable;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

use function is_int;
use function is_string;
use function method_exists;
use function strlen;
use function strrpos;
use function substr;

/**
 * The scalar id of a host user as AuthKit stores it (`(string) getId()`, else the readable `id` property), for events
 * and the social account link (1.41).
 */
final class UserIds
{
    /** Namespace marker of Doctrine's generated proxy classes (`Proxies\__CG__\App\Entity\User`). */
    private const PROXY_MARKER = '\\__CG__\\';

    /**
     * The user's class without a Doctrine proxy wrapper (1.42), e.g. for an event's `userClass`.
     *
     * @return class-string
     */
    public static function className(object $user): string
    {
        $class = $user::class;
        $pos   = strrpos($class, self::PROXY_MARKER);

        /** @var class-string $real */
        $real = $pos === false ? $class : substr($class, $pos + strlen(self::PROXY_MARKER));

        return $real;
    }

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
