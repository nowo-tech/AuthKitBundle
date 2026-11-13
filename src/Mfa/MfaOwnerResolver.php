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
 * empty id, not persisted yet, too long, edge white space, control or format characters, invalid UTF-8) cannot have
 * MFA.
 */
final readonly class MfaOwnerResolver
{
    public function __construct(
        private ?ManagerRegistry $doctrine = null,
    ) {
    }

    public function resolve(object $user): ?MfaOwner
    {
        $reference = $this->rawReference($user);

        return $reference !== null && MfaOwner::usable($reference[0]) && MfaOwner::usable($reference[1]) ? new MfaOwner($reference[0], $reference[1]) : null;
    }

    /**
     * Whether the user has a single, non-empty reference that {@see MfaOwner} refuses although it fits the column (edge
     * white space, control or format characters, invalid UTF-8; REQ-SEC-006, 1.46.1). Such a user cannot have MFA, and
     * a credential stored under that id before 1.46.1 is unreachable: {@see MfaManager::mustEnrolNow()} fails closed.
     *
     * @return array{0: string, 1: string}|null the raw type and id when refused
     */
    public function refusedReference(object $user): ?array
    {
        $reference = $this->rawReference($user);
        if ($reference === null || $this->resolve($user) instanceof MfaOwner
            || mb_strlen($reference[0], 'UTF-8') > MfaOwner::MAX_LENGTH || mb_strlen($reference[1], 'UTF-8') > MfaOwner::MAX_LENGTH) {
            return null;
        }

        return $reference;
    }

    /**
     * The owner, plus the owner under the identifier captured when the erasure started when the id is the identifier
     * (see {@see MfaManager::eraseUser()} for the trust rule).
     *
     * @return list<MfaOwner>
     */
    public function erasureOwners(UserInterface $user, ?string $originalIdentifier): array
    {
        // 1.46.1: a reference refused since REQ-SEC-006 is still erased (byte for byte) when rows were stored under it.
        $refused = $this->refusedReference($user);
        $owner   = $this->resolve($user) ?? ($refused !== null ? MfaOwner::legacy($refused[0], $refused[1]) : null);
        if (!$owner instanceof MfaOwner) {
            return [];
        }
        $owners = [$owner];
        if ($originalIdentifier !== null && $originalIdentifier !== '' && $originalIdentifier !== $owner->id
            && $owner->id === $user->getUserIdentifier() && mb_strlen($originalIdentifier, 'UTF-8') <= MfaOwner::MAX_LENGTH) {
            $owners[] = MfaOwner::usable($originalIdentifier) ? new MfaOwner($owner->type, $originalIdentifier) : MfaOwner::legacy($owner->type, $originalIdentifier);
        }

        return $owners;
    }

    /**
     * The root class and the single identifier of the user, as strings; null without one (composite, empty, not
     * persisted yet).
     *
     * @return array{0: string, 1: string}|null
     */
    private function rawReference(object $user): ?array
    {
        $manager = $this->doctrine?->getManagerForClass($user::class);
        if ($manager instanceof EntityManagerInterface && !$manager->getMetadataFactory()->isTransient($user::class)) {
            $metadata = $manager->getClassMetadata($user::class);
            $ids      = $metadata->getIdentifierValues($user);
            if (count($ids) !== 1) {
                return null;
            }
            $id = reset($ids);
            $id = is_int($id) || is_string($id) || $id instanceof Stringable ? (string) $id : '';

            return $id !== '' ? [$metadata->rootEntityName, $id] : null;
        }
        if (!$user instanceof UserInterface) {
            return null;
        }
        $id = $user->getUserIdentifier();

        // @phpstan-ignore notIdentical.alwaysTrue (the PHPDoc promises non-empty; host users can still return '')
        return $id !== '' ? [$user::class, $id] : null;
    }
}
