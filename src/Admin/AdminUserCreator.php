<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Admin;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\AuthKitBundle\Doctrine\EntityManagerRecovery;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Security\UserIdentifierMatcher;
use Nowo\AuthKitBundle\User\Doctrine\DoctrineUserLookup;
use Nowo\AuthKitBundle\User\Doctrine\DoctrineUserWriter;
use Nowo\AuthKitBundle\User\DuplicateUserException;
use Nowo\AuthKitBundle\User\Event\UserRegisteredEvent;
use Nowo\AuthKitBundle\User\NewUser;
use Nowo\AuthKitBundle\User\PropertyAccessUserFactory;
use Nowo\AuthKitBundle\User\UserCreationSource;
use Nowo\AuthKitBundle\User\UserFactoryInterface;
use Nowo\AuthKitBundle\User\UserLookupInterface;
use Nowo\AuthKitBundle\User\UserWriterInterface;
use RuntimeException;
use SensitiveParameter;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function sprintf;

/**
 * Creates a privileged user for an AuthKit profile (CLI provisioning), independent of registration_mode.
 *
 * Through the user ports (1.41): {@see UserFactoryInterface} builds the user (default: the identifier to the profile's
 * user_identifier_field, the hashed password to the property of the first hashed registration field, default
 * "password"), {@see UserWriterInterface} stores it, {@see UserLookupInterface} answers {@see self::exists()}.
 * Dispatches {@see AdminUserCreatingEvent} before storing, {@see AdminUserCreatedEvent} and {@see UserRegisteredEvent}
 * (source `admin`) after. Stateless (worker-safe).
 */
final class AdminUserCreator
{
    private readonly UserFactoryInterface $userFactory;

    private readonly UserWriterInterface $userWriter;

    private readonly UserLookupInterface $userLookup;

    public function __construct(
        private readonly ProfileRegistry $profileRegistry,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        PropertyAccessorInterface $propertyAccessor,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        EntityManagerRecovery $entityManagerRecovery = new EntityManagerRecovery(),
        ?UserFactoryInterface $userFactory = null,
        ?UserWriterInterface $userWriter = null,
        ?UserLookupInterface $userLookup = null,
        private readonly UserIdentifierMatcher $identifierMatcher = new UserIdentifierMatcher(),
    ) {
        $this->userFactory = $userFactory ?? new PropertyAccessUserFactory($passwordHasher, $propertyAccessor);
        $this->userWriter  = $userWriter ?? new DoctrineUserWriter($entityManager, $propertyAccessor, $entityManagerRecovery);
        $this->userLookup  = $userLookup ?? new DoctrineUserLookup($entityManager);
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

        return $this->userLookup->findByIdentifier($profile, $identifier) instanceof UserInterface;
    }

    /**
     * @param list<string> $roles
     */
    public function create(string $identifier, #[SensitiveParameter] string $plainPassword, array $roles, ?string $profileName = null): UserInterface&PasswordAuthenticatedUserInterface
    {
        $profile = $this->profile($profileName);

        $user = $this->userFactory->create($profile, NewUser::forAdmin($identifier, $plainPassword, $roles));
        // 1.49.0: stored in the form lookups use (identifier_normalizer); unchanged with the default normaliser.
        $this->identifierMatcher->normalizeStored($user, $profile->userIdentifierField);

        $this->eventDispatcher?->dispatch(new AdminUserCreatingEvent($user, $profile->name));

        try {
            // @igor-ignore - Stateless user port call (stores the host user); no service state changes.
            $this->userWriter->add($profile, $user);
        } catch (DuplicateUserException|UniqueConstraintViolationException $exception) {
            throw new RuntimeException(sprintf('Admin creation failed: a "%s" with the same unique value already exists.', $profile->userClass), 0, $exception);
        }

        $this->eventDispatcher?->dispatch(new AdminUserCreatedEvent($user, $profile->name, $roles));
        $this->eventDispatcher?->dispatch(UserRegisteredEvent::of($profile->name, $user, UserCreationSource::Admin));

        return $user;
    }
}
