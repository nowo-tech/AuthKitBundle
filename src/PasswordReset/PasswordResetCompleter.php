<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\PasswordReset;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\AuthKitBundle\Doctrine\EntityManagerRecovery;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\User\Doctrine\DoctrineUserWriter;
use Nowo\AuthKitBundle\User\UserIds;
use Nowo\AuthKitBundle\User\UserWriterInterface;
use SensitiveParameter;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Applies a new password and clears the reset credential. The hash is stored through {@see UserWriterInterface}
 * (1.41; default: the password property + flush), then {@see PasswordResetCompletedEvent} is dispatched.
 */
final class PasswordResetCompleter
{
    private readonly UserWriterInterface $userWriter;

    public function __construct(
        EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        PropertyAccessorInterface $propertyAccessor,
        private readonly PasswordResetTokenManagerInterface $tokenManager,
        private readonly ProfileRegistry $profileRegistry,
        EntityManagerRecovery $entityManagerRecovery = new EntityManagerRecovery(),
        ?UserWriterInterface $userWriter = null,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
    ) {
        $this->userWriter = $userWriter ?? new DoctrineUserWriter($entityManager, $propertyAccessor, $entityManagerRecovery);
    }

    public function complete(PasswordAuthenticatedUserInterface $user, #[SensitiveParameter] string $plainPassword): void
    {
        $profile = $this->profileRegistry->resolveForObject($user) ?? $this->profileRegistry->getDefault();
        $hashed  = $this->passwordHasher->hashPassword($user, $plainPassword);

        $this->userWriter->changePassword($profile, $user, $hashed);
        // @igor-ignore - Not shared worker service state.
        $this->tokenManager->clearForUser($user);

        $this->userWriter->save($profile, $user);

        $this->eventDispatcher?->dispatch(new PasswordResetCompletedEvent(
            $profile->name,
            $user::class,
            UserIds::of($user),
            $user instanceof UserInterface ? $user->getUserIdentifier() : '',
        ));
    }
}
