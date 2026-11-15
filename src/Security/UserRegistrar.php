<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Security;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\AuthKitBundle\Doctrine\EntityManagerRecovery;
use Nowo\AuthKitBundle\Enum\RegistrationMode;
use Nowo\AuthKitBundle\Invitation\InvitationAcceptor;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Transaction\AccountLock;
use Nowo\AuthKitBundle\Transaction\AuthKitTransactionRunner;
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
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function array_unique;
use function array_values;
use function is_string;
use function sprintf;

/**
 * Creates and stores users from registration form data, through the user ports (1.41): {@see UserFactoryInterface}
 * builds the user, {@see UserWriterInterface} stores it, {@see UserLookupInterface} counts and checks for duplicates.
 * Without them (direct construction) the Doctrine + PropertyAccessor defaults are used.
 */
final class UserRegistrar
{
    private readonly UserFactoryInterface $userFactory;

    private readonly UserWriterInterface $userWriter;

    private readonly UserLookupInterface $userLookup;

    private readonly AuthKitTransactionRunner $transactions;

    public function __construct(
        private readonly ProfileRegistry $profileRegistry,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        PropertyAccessorInterface $propertyAccessor,
        EntityManagerRecovery $entityManagerRecovery = new EntityManagerRecovery(),
        ?UserFactoryInterface $userFactory = null,
        ?UserWriterInterface $userWriter = null,
        ?UserLookupInterface $userLookup = null,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        private readonly UserIdentifierMatcher $identifierMatcher = new UserIdentifierMatcher(),
        ?AuthKitTransactionRunner $transactions = null,
    ) {
        $this->transactions = $transactions ?? new AuthKitTransactionRunner($entityManager);
        $this->userFactory  = $userFactory ?? new PropertyAccessUserFactory($passwordHasher, $propertyAccessor);
        $this->userWriter   = $userWriter ?? new DoctrineUserWriter($entityManager, $propertyAccessor, $entityManagerRecovery, $this->transactions);
        $this->userLookup   = $userLookup ?? new DoctrineUserLookup($entityManager);
    }

    /**
     * Dispatches {@see UserRegisteredEvent} (source `form`) once the account is stored.
     *
     * 1.50.0 (Beacon spec 239): one unit of work on AuthKit's transaction runner, under the account lock of the
     * identifier ({@see AccountLock}; with `first_user_only`, of the whole user class), so concurrent registrations of
     * one identifier never create two accounts: the second one's checks run after the first one's commit.
     *
     * @param array<string, mixed> $formData keyed by field name
     */
    public function register(array $formData, ?string $profileName = null): UserInterface&PasswordAuthenticatedUserInterface
    {
        $profile = $profileName !== null
            ? $this->profileRegistry->getByName($profileName)
            : $this->profileRegistry->getDefault();

        $mode    = RegistrationMode::from($profile->registrationMode);
        $newUser = NewUser::fromForm($formData, [$profile->registrationRole]);
        $work    = fn (): UserInterface&PasswordAuthenticatedUserInterface => $this->unit(function () use ($profile, $mode, $newUser): UserInterface&PasswordAuthenticatedUserInterface {
            if ($mode === RegistrationMode::FirstUserOnly) {
                $count = $this->userLookup->countUsers($profile);
                if ($count > 0) {
                    throw new RuntimeException('Registration is closed (first_user_only): users already exist.');
                }
            }

            $user = $this->createUser($profile, $newUser);

            if ($mode === RegistrationMode::FirstUserOnly) {
                $count = $this->userLookup->countUsers($profile);
                if ($count > 1) {
                    // A host writer that stores outside this transaction is not rolled back with it.
                    // @igor-ignore - Stateless user port call (stores the host user); no service state changes.
                    $this->userWriter->remove($profile, $user);

                    throw new RuntimeException(sprintf('Registration race detected for first_user_only on "%s"; rolling back extra user.', $profile->userClass));
                }
            }

            return $user;
        });
        $user = $mode === RegistrationMode::FirstUserOnly
            ? AccountLock::forUserClass($this->transactions, $profile, $work)
            : AccountLock::forIdentifier($this->transactions, $profile, $newUser->identifierValue($profile), $work);

        $this->eventDispatcher?->dispatch(UserRegisteredEvent::of($profile->name, $user, UserCreationSource::Form));

        return $user;
    }

    /**
     * Creates the account of an accepted invitation (1.32): same field mapping and hashing as {@see self::register()},
     * roles = `invitations.registration_role` (1.33; null: registration_role) plus `$roles`. Not subject to `first_user_only` (the invitation is the authorisation);
     * the caller checks {@see RegistrationGate::isInvitationAcceptanceAllowed()}. Dispatches no event: the caller runs
     * it in its transaction ({@see InvitationAcceptor} dispatches
     * {@see UserRegisteredEvent} after its commit).
     *
     * @param array<string, mixed> $formData keyed by field name
     * @param list<string> $roles extra roles granted by the invitation
     *
     * @throws RuntimeException when a user with the same unique value already exists
     */
    public function registerInvited(array $formData, string $profileName, array $roles = []): UserInterface&PasswordAuthenticatedUserInterface
    {
        $profile = $this->profileRegistry->getByName($profileName);
        $base    = $profile->invitations['registration_role'] ?? null;
        $newUser = NewUser::fromForm(
            $formData,
            array_values(array_unique([is_string($base) && $base !== '' ? $base : $profile->registrationRole, ...$roles])),
            UserCreationSource::Invitation,
        );

        // 1.50.0: under the account lock (re-entrant: {@see InvitationAcceptor} already holds it) and in a unit of work
        // (nested in the acceptance's when called from it).
        return AccountLock::forIdentifier($this->transactions, $profile, $newUser->identifierValue($profile), fn (): UserInterface&PasswordAuthenticatedUserInterface => $this->unit(function () use ($profile, $formData, $newUser): UserInterface&PasswordAuthenticatedUserInterface {
            $this->assertNoCaseVariant($profile, $formData);

            return $this->createUser($profile, $newUser);
        }));
    }

    /**
     * The identifier of the account the registration form data would create (1.50.0), for the caller's account lock
     * ({@see AccountLock}); null when the form has no identifier field value.
     *
     * @param array<string, mixed> $formData keyed by field name
     */
    public function identifierOf(array $formData, string $profileName): ?string
    {
        $profile = $this->profileRegistry->getByName($profileName);

        return NewUser::fromForm($formData, [])->identifierValue($profile);
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
        if ($this->userLookup->hasCaseVariant($profile, $property, $value)) {
            throw new RuntimeException(sprintf('Registration failed: a "%s" with the same identifier (any case) already exists.', $profile->userClass));
        }
    }

    private function createUser(ProfileSettings $profile, NewUser $newUser): UserInterface&PasswordAuthenticatedUserInterface
    {
        // 1.49.0 (audit OV): refused / case-variant identifiers never reach the factory, which receives the normalised one.
        $newUser = $this->identifierMatcher->prepareNewUser($profile, $newUser, $this->userLookup, 'Registration failed');
        // 1.50.0: re-checked under the account lock, before the insert: a concurrent creation of the same identifier
        // committed first is refused here (the unique key, when the host has one, would refuse it later and close the
        // entity manager).
        $this->assertNotTaken($profile, $newUser);
        $user = $this->userFactory->create($profile, $newUser);
        // 1.49.0: stored in the form lookups use (identifier_normalizer); unchanged with the default normaliser.
        $this->identifierMatcher->normalizeStored($user, $profile->userIdentifierField);

        try {
            // @igor-ignore - Stateless user port call (stores the host user); no service state changes.
            $this->userWriter->add($profile, $user);
        } catch (DuplicateUserException|UniqueConstraintViolationException $exception) {
            throw new RuntimeException(sprintf('Registration failed: a "%s" with the same unique value already exists.', $profile->userClass), 0, $exception);
        }

        return $user;
    }

    /**
     * 1.50.0: the unit of work of an account creation: with the Doctrine user writer the accounts go through the
     * application's manager ({@see AuthKitTransactionRunner::runWithAccounts()}); a host user port writes them itself,
     * so no manager is flushed ({@see AuthKitTransactionRunner::runOnConnection()}).
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function unit(callable $work): mixed
    {
        return $this->userWriter instanceof DoctrineUserWriter ? $this->transactions->runWithAccounts($work) : $this->transactions->runOnConnection($work);
    }

    /**
     * 1.50.0: refuses an identifier the lookup port already resolves to an account (the message of the unique key).
     */
    private function assertNotTaken(ProfileSettings $profile, NewUser $newUser): void
    {
        $identifier = $newUser->identifierValue($profile);
        if ($identifier !== null && $identifier !== '' && $this->userLookup->findByIdentifier($profile, $identifier) instanceof UserInterface) {
            throw new RuntimeException(sprintf('Registration failed: a "%s" with the same unique value already exists.', $profile->userClass));
        }
    }
}
