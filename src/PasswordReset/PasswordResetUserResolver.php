<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\PasswordReset;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Resolves users by the configured identifier field.
 */
final class PasswordResetUserResolver
{
    /**
     * @param class-string<object> $userClass
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly string $userClass,
        private readonly string $userIdentifierField,
    ) {
    }

    public function findByIdentifier(string $identifier): ?object
    {
        $repository = $this->entityManager->getRepository($this->userClass);

        return $repository->findOneBy([$this->userIdentifierField => $identifier]);
    }
}
