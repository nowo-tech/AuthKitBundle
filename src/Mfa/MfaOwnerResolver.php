<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Mfa;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Stringable;
use Symfony\Component\Security\Core\User\UserInterface;

use function count;
use function is_int;
use function is_string;
use function mb_strlen;
use function reset;

/**
 * Owner of a user (1.34): Doctrine-mapped users by their root entity class and single identifier (stable when the
 * e-mail changes); other security users by class and user identifier. Users without a usable reference (composite or
 * empty id, not persisted yet, too long) cannot have MFA.
 */
final readonly class MfaOwnerResolver
{
    public function __construct(
        private ?ManagerRegistry $doctrine = null,
    ) {
    }

    public function resolve(object $user): ?MfaOwner
    {
        $manager = $this->doctrine?->getManagerForClass($user::class);
        if ($manager instanceof EntityManagerInterface && !$manager->getMetadataFactory()->isTransient($user::class)) {
            $metadata = $manager->getClassMetadata($user::class);
            $ids      = $metadata->getIdentifierValues($user);
            if (count($ids) !== 1) {
                return null;
            }
            $id = reset($ids);

            return $this->reference($metadata->rootEntityName, is_int($id) || is_string($id) || $id instanceof Stringable ? (string) $id : '');
        }

        return $user instanceof UserInterface ? $this->reference($user::class, $user->getUserIdentifier()) : null;
    }

    private function reference(string $type, string $id): ?MfaOwner
    {
        if ($id === '' || mb_strlen($id) > MfaOwner::MAX_LENGTH || mb_strlen($type) > MfaOwner::MAX_LENGTH) {
            return null;
        }

        return new MfaOwner($type, $id);
    }
}
