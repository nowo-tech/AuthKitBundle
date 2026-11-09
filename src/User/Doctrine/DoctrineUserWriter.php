<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\User\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\AuthKitBundle\Doctrine\EntityManagerRecovery;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\User\UserWriterInterface;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Default {@see UserWriterInterface} (the behaviour before 1.41): persist / remove + flush on the bundle's entity
 * manager (closed managers reopened by {@see EntityManagerRecovery}); the password hash is written to the property of
 * the first `registration_fields[]` entry of type `password` (default `password`) and stored by the next flush.
 */
final readonly class DoctrineUserWriter implements UserWriterInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PropertyAccessorInterface $propertyAccessor,
        private EntityManagerRecovery $entityManagerRecovery = new EntityManagerRecovery(),
    ) {
    }

    public function add(ProfileSettings $profile, UserInterface $user): void
    {
        $this->entityManager->persist($user);
        $this->entityManagerRecovery->flush($this->entityManager);
    }

    public function remove(ProfileSettings $profile, UserInterface $user): void
    {
        $this->entityManager->remove($user);
        $this->entityManagerRecovery->flush($this->entityManager);
    }

    public function changePassword(ProfileSettings $profile, PasswordAuthenticatedUserInterface $user, string $hashedPassword): void
    {
        $this->propertyAccessor->setValue($user, $this->passwordProperty($profile), $hashedPassword);
    }

    public function save(ProfileSettings $profile, PasswordAuthenticatedUserInterface|UserInterface $user): void
    {
        $this->entityManager->persist($user);
        $this->entityManagerRecovery->flush($this->entityManager);
    }

    private function passwordProperty(ProfileSettings $profile): string
    {
        foreach ($profile->registrationFields as $field) {
            if ($field['type'] === 'password') {
                return $field['property'];
            }
        }

        return 'password';
    }
}
