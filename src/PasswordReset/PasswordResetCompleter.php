<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\PasswordReset;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

/**
 * Applies a new password and clears the reset credential.
 */
final class PasswordResetCompleter
{
    /**
     * @param list<array{name: string, type: string, property: string, hash: bool, required: bool, security_name: null}> $registrationFields
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly PropertyAccessorInterface $propertyAccessor,
        private readonly PasswordResetTokenManagerInterface $tokenManager,
        private readonly array $registrationFields,
    ) {
    }

    public function complete(PasswordAuthenticatedUserInterface $user, string $plainPassword): void
    {
        $passwordProperty = $this->resolvePasswordProperty();
        $hashed           = $this->passwordHasher->hashPassword($user, $plainPassword);

        $this->propertyAccessor->setValue($user, $passwordProperty, $hashed);
        $this->tokenManager->clearForUser($user);

        $this->entityManager->persist($user);
        $this->entityManager->flush();
    }

    private function resolvePasswordProperty(): string
    {
        foreach ($this->registrationFields as $field) {
            if ($field['type'] === 'password') {
                return $field['property'];
            }
        }

        return 'password';
    }
}
