<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Security;

use Doctrine\ORM\EntityManagerInterface;
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
    /**
     * @param class-string<PasswordAuthenticatedUserInterface&UserInterface> $userClass
     * @param list<array{name: string, type: string, property: string, hash: bool, required: bool, security_name: null}> $registrationFields
     */
    public function __construct(
        private readonly string $userClass,
        private readonly string $registrationRole,
        private readonly array $registrationFields,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly PropertyAccessorInterface $propertyAccessor,
    ) {
    }

    /**
     * @param array<string, mixed> $formData keyed by field name
     */
    public function register(array $formData): UserInterface&PasswordAuthenticatedUserInterface
    {
        /** @var PasswordAuthenticatedUserInterface&UserInterface $user */
        $user = new $this->userClass();

        foreach ($this->registrationFields as $field) {
            $value = $formData[$field['name']] ?? null;

            if ($field['hash'] && is_string($value)) {
                $value = $this->passwordHasher->hashPassword($user, $value);
            }

            if ($value !== null) {
                $this->propertyAccessor->setValue($user, $field['property'], $value);
            }
        }

        if (method_exists($user, 'setRoles')) {
            $user->setRoles([$this->registrationRole]);
        } elseif ($this->propertyAccessor->isWritable($user, 'roles')) {
            $this->propertyAccessor->setValue($user, 'roles', [$this->registrationRole]);
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
