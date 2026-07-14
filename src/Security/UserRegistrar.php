<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Security;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function is_string;

/**
 * Creates and persists users from registration form data.
 */
final class UserRegistrar
{
    public function __construct(
        private readonly ProfileRegistry $profileRegistry,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly PropertyAccessorInterface $propertyAccessor,
    ) {
    }

    /**
     * @param array<string, mixed> $formData keyed by field name
     */
    public function register(array $formData, ?string $profileName = null): UserInterface&PasswordAuthenticatedUserInterface
    {
        $profile = $profileName !== null
            ? $this->profileRegistry->getByName($profileName)
            : $this->profileRegistry->getDefault();

        /** @var PasswordAuthenticatedUserInterface&UserInterface $user */
        $user = new ($profile->userClass)();

        foreach ($profile->registrationFields as $field) {
            $value = $formData[$field['name']] ?? null;

            if ($field['hash'] && is_string($value)) {
                $value = $this->passwordHasher->hashPassword($user, $value);
            }

            if ($value !== null) {
                $this->propertyAccessor->setValue($user, $field['property'], $value);
            }
        }

        if (method_exists($user, 'setRoles')) {
            $user->setRoles([$profile->registrationRole]);
        } elseif ($this->propertyAccessor->isWritable($user, 'roles')) {
            $this->propertyAccessor->setValue($user, 'roles', [$profile->registrationRole]);
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
