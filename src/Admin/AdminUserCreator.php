<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Admin;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\AuthKitBundle\Doctrine\EntityManagerRecovery;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use RuntimeException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function is_string;
use function sprintf;

/**
 * Creates a privileged user for an AuthKit profile (CLI provisioning), independent of registration_mode.
 *
 * The identifier is written to the profile's user_identifier_field, the hashed password to the property of
 * the first hashed registration field (default "password"). Dispatches {@see AdminUserCreatingEvent} before
 * persisting and {@see AdminUserCreatedEvent} after flushing. Stateless (worker-safe).
 */
final class AdminUserCreator
{
    public function __construct(
        private readonly ProfileRegistry $profileRegistry,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly PropertyAccessorInterface $propertyAccessor,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        private readonly EntityManagerRecovery $entityManagerRecovery = new EntityManagerRecovery(),
    ) {
    }

    public function profile(?string $profileName = null): ProfileSettings
    {
        return $profileName !== null
            ? $this->profileRegistry->getByName($profileName)
            : $this->profileRegistry->getDefault();
    }

    public function exists(string $identifier, ?string $profileName = null): bool
    {
        $profile = $this->profile($profileName);

        return $this->entityManager->getRepository($profile->userClass)
            ->findOneBy([$profile->userIdentifierField => $identifier]) !== null;
    }

    /**
     * @param list<string> $roles
     */
    public function create(string $identifier, string $plainPassword, array $roles, ?string $profileName = null): UserInterface&PasswordAuthenticatedUserInterface
    {
        $profile = $this->profile($profileName);

        /** @var PasswordAuthenticatedUserInterface&UserInterface $user */
        $user = new ($profile->userClass)();

        $this->propertyAccessor->setValue($user, $profile->userIdentifierField, $identifier);
        $this->propertyAccessor->setValue(
            $user,
            $this->passwordProperty($profile),
            $this->passwordHasher->hashPassword($user, $plainPassword),
        );

        if (method_exists($user, 'setRoles')) {
            $user->setRoles($roles);
        } elseif ($this->propertyAccessor->isWritable($user, 'roles')) {
            $this->propertyAccessor->setValue($user, 'roles', $roles);
        }

        $this->eventDispatcher?->dispatch(new AdminUserCreatingEvent($user, $profile->name));

        $this->entityManager->persist($user);

        try {
            $this->entityManagerRecovery->flush($this->entityManager);
        } catch (UniqueConstraintViolationException $exception) {
            throw new RuntimeException(sprintf('Admin creation failed: a "%s" with the same unique value already exists.', $profile->userClass), 0, $exception);
        }

        $this->eventDispatcher?->dispatch(new AdminUserCreatedEvent($user, $profile->name, $roles));

        return $user;
    }

    private function passwordProperty(ProfileSettings $profile): string
    {
        foreach ($profile->registrationFields as $field) {
            if (($field['hash'] ?? false) === true && is_string($field['property'] ?? null) && $field['property'] !== '') {
                return $field['property'];
            }
        }

        return 'password';
    }
}
