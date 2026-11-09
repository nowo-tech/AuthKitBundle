<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\User\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Security\UserIdentifierMatcher;
use Nowo\AuthKitBundle\User\UserLookupInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function is_numeric;
use function mb_strtolower;
use function sprintf;
use function trim;

/**
 * Default {@see UserLookupInterface} (the behaviour before 1.41): the Doctrine repository of `user_class` on the
 * bundle's entity manager; identifier matches checked by {@see UserIdentifierMatcher}; managed results refreshed.
 */
final readonly class DoctrineUserLookup implements UserLookupInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserIdentifierMatcher $identifierMatcher = new UserIdentifierMatcher(),
    ) {
    }

    public function findByIdentifier(ProfileSettings $profile, string $identifier): ?UserInterface
    {
        // The stored identifier must equal the input (NFC, case-insensitive): an accent/case-insensitive database
        // collation must not resolve a look-alike address to another account (SEC-004 H1).
        $user = $this->identifierMatcher->find(
            $this->entityManager->getRepository($profile->userClass),
            $profile->userIdentifierField,
            $identifier,
        );

        return $user instanceof UserInterface ? $this->fresh($user) : null;
    }

    public function findById(ProfileSettings $profile, string $id): ?UserInterface
    {
        $user = $this->entityManager->find($profile->userClass, is_numeric($id) ? (int) $id : $id);

        return $user instanceof UserInterface ? $this->fresh($user) : null;
    }

    public function countUsers(ProfileSettings $profile): int
    {
        return $this->entityManager->getRepository($profile->userClass)->count([]);
    }

    public function hasCaseVariant(ProfileSettings $profile, string $property, string $value): bool
    {
        if (!$this->entityManager->getClassMetadata($profile->userClass)->hasField($property)) {
            return false;
        }

        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(u)')
            ->from($profile->userClass, 'u')
            ->where(sprintf('LOWER(u.%s) = :identifier', $property))
            ->setParameter('identifier', mb_strtolower(trim($value)))
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    private function fresh(UserInterface $user): UserInterface
    {
        // Long-lived workers keep the identity map: refresh so bans/identifier changes apply.
        if ($this->entityManager->contains($user)) {
            $this->entityManager->refresh($user);
        }

        return $user;
    }
}
