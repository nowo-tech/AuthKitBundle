<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Security;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\AuthKitBundle\Doctrine\EntityManagerRecovery;
use Nowo\AuthKitBundle\Enum\RegistrationMode;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use RuntimeException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_unique;
use function array_values;
use function is_string;
use function mb_strtolower;
use function sprintf;
use function trim;

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
        private readonly EntityManagerRecovery $entityManagerRecovery = new EntityManagerRecovery(),
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

        $mode = RegistrationMode::from($profile->registrationMode);
        if ($mode === RegistrationMode::FirstUserOnly) {
            $count = $this->entityManager->getRepository($profile->userClass)->count([]);
            if ($count > 0) {
                throw new RuntimeException('Registration is closed (first_user_only): users already exist.');
            }
        }

        $user = $this->createUser($profile, $formData, [$profile->registrationRole]);

        if ($mode === RegistrationMode::FirstUserOnly) {
            $count = $this->entityManager->getRepository($profile->userClass)->count([]);
            if ($count > 1) {
                $this->entityManager->remove($user);
                $this->entityManagerRecovery->flush($this->entityManager);

                throw new RuntimeException(sprintf('Registration race detected for first_user_only on "%s"; rolling back extra user.', $profile->userClass));
            }
        }

        return $user;
    }

    /**
     * Creates the account of an accepted invitation (1.32): same field mapping and hashing as {@see self::register()},
     * roles = registration_role plus `$roles`. Not subject to `first_user_only` (the invitation is the authorisation);
     * the caller checks {@see RegistrationGate::isInvitationAcceptanceAllowed()}.
     *
     * @param array<string, mixed> $formData keyed by field name
     * @param list<string> $roles extra roles granted by the invitation
     *
     * @throws RuntimeException when a user with the same unique value already exists
     */
    public function registerInvited(array $formData, string $profileName, array $roles = []): UserInterface&PasswordAuthenticatedUserInterface
    {
        $profile = $this->profileRegistry->getByName($profileName);
        $this->assertNoCaseVariant($profile, $formData);

        return $this->createUser($profile, $formData, array_values(array_unique([$profile->registrationRole, ...$roles])));
    }

    /**
     * SEC-004 CD L2: on case-sensitive databases the unique constraint would accept `mixed@example.com` next to an
     * existing `Mixed@Example.com`. The invited address is compared lower-cased (the invitation normalisation) with the
     * stored identifiers first; a match refuses the acceptance like the constraint does.
     *
     * @param array<string, mixed> $formData
     */
    private function assertNoCaseVariant(ProfileSettings $profile, array $formData): void
    {
        $field = $profile->invitationEmailField();
        $value = $field !== null ? ($formData[$field] ?? null) : null;
        if (!is_string($value) || $value === '') {
            return;
        }
        $property = $field;
        foreach ($profile->registrationFields as $definition) {
            if (($definition['name'] ?? null) === $field) {
                $property = (string) ($definition['property'] ?? $field);
            }
        }
        if (!$this->entityManager->getClassMetadata($profile->userClass)->hasField($property)) {
            return;
        }
        $count = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(u)')
            ->from($profile->userClass, 'u')
            ->where(sprintf('LOWER(u.%s) = :identifier', $property))
            ->setParameter('identifier', mb_strtolower(trim($value)))
            ->getQuery()
            ->getSingleScalarResult();
        if ($count > 0) {
            throw new RuntimeException(sprintf('Registration failed: a "%s" with the same identifier (any case) already exists.', $profile->userClass));
        }
    }

    /**
     * @param array<string, mixed> $formData
     * @param list<string> $roles
     */
    private function createUser(ProfileSettings $profile, array $formData, array $roles): UserInterface&PasswordAuthenticatedUserInterface
    {
        /** @var PasswordAuthenticatedUserInterface&UserInterface $user */
        $user = new ($profile->userClass)();

        foreach ($profile->registrationFields as $field) {
            if (($field['mapped'] ?? true) === false) {
                continue;
            }

            $value = $formData[$field['name']] ?? null;

            if ($field['hash'] && is_string($value)) {
                $value = $this->passwordHasher->hashPassword($user, $value);
            }

            if ($value !== null) {
                $this->propertyAccessor->setValue($user, $field['property'], $value);
            }
        }

        if (method_exists($user, 'setRoles')) {
            $user->setRoles($roles);
        } elseif ($this->propertyAccessor->isWritable($user, 'roles')) {
            $this->propertyAccessor->setValue($user, 'roles', $roles);
        }

        $this->entityManager->persist($user);

        try {
            $this->entityManagerRecovery->flush($this->entityManager);
        } catch (UniqueConstraintViolationException $exception) {
            throw new RuntimeException(sprintf('Registration failed: a "%s" with the same unique value already exists.', $profile->userClass), 0, $exception);
        }

        return $user;
    }
}
