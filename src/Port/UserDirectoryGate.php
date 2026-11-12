<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Port;

use Nowo\AuthKitBundle\DependencyInjection\Compiler\AuthKitPortsPass;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\User\UserDirectoryInterface;
use Nowo\AuthKitBundle\User\UserLookupInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Serves the deprecated {@see UserLookupInterface} alias with the host's {@see UserDirectoryInterface} (1.46.0, wired by
 * {@see AuthKitPortsPass} when the application aliases the 1.46
 * interface). A returned reference must carry a Symfony security user (`LogicException` otherwise).
 *
 * @internal
 */
final readonly class UserDirectoryGate implements UserLookupInterface
{
    private const PORT = UserDirectoryInterface::class;

    public function __construct(private UserDirectoryInterface $directory)
    {
    }

    public function findByIdentifier(ProfileSettings $profile, string $identifier): ?UserInterface
    {
        $ref = $this->directory->findByIdentifier($profile, $identifier);

        return $ref === null ? null : AuthUserRefs::securityUser($ref, self::PORT . '::findByIdentifier()');
    }

    public function findById(ProfileSettings $profile, string $id): ?UserInterface
    {
        $ref = $this->directory->findById($profile, $id);

        return $ref === null ? null : AuthUserRefs::securityUser($ref, self::PORT . '::findById()');
    }

    public function countUsers(ProfileSettings $profile): int
    {
        return $this->directory->countUsers($profile);
    }

    public function hasCaseVariant(ProfileSettings $profile, string $property, string $value): bool
    {
        return $this->directory->hasCaseVariant($profile, $property, $value);
    }
}
