<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\PasswordReset;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

/**
 * Applies a new password and clears the reset credential.
 */
final class PasswordResetCompleter
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly PropertyAccessorInterface $propertyAccessor,
        private readonly PasswordResetTokenManagerInterface $tokenManager,
        private readonly ProfileRegistry $profileRegistry,
    ) {
    }

    public function complete(PasswordAuthenticatedUserInterface $user, string $plainPassword): void
    {
        $profile          = $this->profileRegistry->resolveForObject($user) ?? $this->profileRegistry->getDefault();
        $passwordProperty = $this->resolvePasswordProperty($profile->registrationFields);
        $hashed           = $this->passwordHasher->hashPassword($user, $plainPassword);

        $this->propertyAccessor->setValue($user, $passwordProperty, $hashed);
        $this->tokenManager->clearForUser($user);

        $this->entityManager->persist($user);
        $this->entityManager->flush();
    }

    /**
     * @param list<array<string, mixed>> $registrationFields
     */
    private function resolvePasswordProperty(array $registrationFields): string
    {
        foreach ($registrationFields as $field) {
            if ($field['type'] === 'password') {
                return $field['property'];
            }
        }

        return 'password';
    }
}
