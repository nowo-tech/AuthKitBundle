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

    /**
     * The owner, plus the owner under the identifier captured when the erasure started when the id is the identifier
     * (see {@see MfaManager::eraseUser()} for the trust rule).
     *
     * @return list<MfaOwner>
     */
    public function erasureOwners(UserInterface $user, ?string $originalIdentifier): array
    {
        $owner = $this->resolve($user);
        if (!$owner instanceof MfaOwner) {
            return [];
        }
        $owners = [$owner];
        if ($originalIdentifier !== null && $originalIdentifier !== '' && $originalIdentifier !== $owner->id
            && $owner->id === $user->getUserIdentifier() && mb_strlen($originalIdentifier) <= MfaOwner::MAX_LENGTH) {
            $owners[] = new MfaOwner($owner->type, $originalIdentifier);
        }

        return $owners;
    }

    private function reference(string $type, string $id): ?MfaOwner
    {
        if ($id === '' || mb_strlen($id) > MfaOwner::MAX_LENGTH || mb_strlen($type) > MfaOwner::MAX_LENGTH) {
            return null;
        }

        return new MfaOwner($type, $id);
    }
}
