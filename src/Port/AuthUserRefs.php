<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Port;

use LogicException;
use Nowo\AuthKitBundle\User\UserIds;
use Nowo\AuthKitBundle\ValueObject\AuthUserRef;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function sprintf;
use function trim;

/**
 * Converts between the Symfony users the AuthKit services work on and the framework-free {@see AuthUserRef} of the
 * 1.46 ports and event accessors (REQ-ARCH-001 rule 3). Stateless.
 *
 * @internal
 */
final class AuthUserRefs
{
    /**
     * The reference of a user object: its class (no Doctrine proxy), `UserIds::of()` (null when none or blank), its
     * identifier and role names when it is a Symfony user ('' and none otherwise), and the object itself (hidden).
     */
    public static function fromUser(object $user): AuthUserRef
    {
        $id = UserIds::of($user);
        if ($id !== null && trim($id) === '') {
            $id = null; // a blank id (e.g. getId() returning ' ') counts as none
        }

        return $user instanceof UserInterface
            ? new AuthUserRef(UserIds::className($user), $id, $user->getUserIdentifier(), $user->getRoles(), $user)
            : new AuthUserRef(UserIds::className($user), $id, '', [], $user);
    }

    /** Same as {@see self::fromUser()}, null for null. */
    public static function fromNullableUser(?object $user): ?AuthUserRef
    {
        return $user === null ? null : self::fromUser($user);
    }

    /**
     * The Symfony user a reference must carry where AuthKit hands it to Symfony Security.
     *
     * @param string $port the port named in the error
     *
     * @throws LogicException when the reference carries no Symfony user object
     */
    public static function securityUser(AuthUserRef $ref, string $port): UserInterface
    {
        $user = $ref->user();
        if (!$user instanceof UserInterface) {
            throw new LogicException(sprintf('%s returned a user reference without a Symfony security user object (AuthUserRef::of() with the object your user provider returns); AuthKit needs it to sign the user in.', $port));
        }

        return $user;
    }

    /**
     * The password-authenticated Symfony user a reference must carry (user creation: AuthKit hashes the password).
     *
     * @throws LogicException when the reference carries no such user object
     */
    public static function passwordUser(AuthUserRef $ref, string $port): UserInterface&PasswordAuthenticatedUserInterface
    {
        $user = self::securityUser($ref, $port);
        if (!$user instanceof PasswordAuthenticatedUserInterface) {
            throw new LogicException(sprintf('%s returned a user that does not implement PasswordAuthenticatedUserInterface; AuthKit hashes and stores its password.', $port));
        }

        return $user;
    }
}
