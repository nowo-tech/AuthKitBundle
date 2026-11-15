<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\SocialLogin;

use Closure;
use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\AuthKitBundle\Doctrine\EntityManagerRecovery;
use Nowo\AuthKitBundle\Entity\SocialLoginAccount;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Repository\SocialLoginAccountRepository;
use Nowo\AuthKitBundle\Security\ExactIdentifier;
use Nowo\AuthKitBundle\Security\RegistrationGate;
use Nowo\AuthKitBundle\Security\UserIdentifierMatcher;
use Nowo\AuthKitBundle\Transaction\AccountLock;
use Nowo\AuthKitBundle\Transaction\AuthKitTransactionRunner;
use Nowo\AuthKitBundle\User\Doctrine\DoctrineUserLookup;
use Nowo\AuthKitBundle\User\Doctrine\DoctrineUserWriter;
use Nowo\AuthKitBundle\User\Event\CredentialsChangedEvent;
use Nowo\AuthKitBundle\User\Event\UserRegisteredEvent;
use Nowo\AuthKitBundle\User\NewUser;
use Nowo\AuthKitBundle\User\PropertyAccessUserFactory;
use Nowo\AuthKitBundle\User\UserCreationSource;
use Nowo\AuthKitBundle\User\UserFactoryInterface;
use Nowo\AuthKitBundle\User\UserIds;
use Nowo\AuthKitBundle\User\UserLookupInterface;
use Nowo\AuthKitBundle\User\UserWriterInterface;
use Psr\Clock\ClockInterface;
use RuntimeException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

use function bin2hex;
use function class_exists;
use function is_int;
use function is_string;
use function random_bytes;
use function sprintf;

/**
 * Resolves or creates the application user and persists social account credentials. Users are looked up, built and
 * stored through the user ports (1.41: {@see UserLookupInterface}, {@see UserFactoryInterface},
 * {@see UserWriterInterface}; Doctrine + PropertyAccessor defaults); a created user dispatches
 * {@see UserRegisteredEvent} (source `social`) once stored (after the commit in {@see self::linkAndSignIn()}).
 */
final class SocialAccountLinker
{
    private readonly UserLookupInterface $userLookup;

    private readonly UserFactoryInterface $userFactory;

    private readonly UserWriterInterface $userWriter;

    private readonly AuthKitTransactionRunner $transactions;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SocialLoginAccountRepository $accountRepository,
        private readonly PropertyAccessorInterface $propertyAccessor,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ClockInterface $clock,
        EntityManagerRecovery $entityManagerRecovery = new EntityManagerRecovery(),
        private readonly ?RegistrationGate $registrationGate = null,
        private readonly UserIdentifierMatcher $identifierMatcher = new UserIdentifierMatcher(),
        ?UserLookupInterface $userLookup = null,
        ?UserFactoryInterface $userFactory = null,
        ?UserWriterInterface $userWriter = null,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        private readonly ?EntityManagerInterface $privateEntityManager = null,
        ?AuthKitTransactionRunner $transactions = null,
    ) {
        $this->transactions = $transactions ?? new AuthKitTransactionRunner($privateEntityManager ?? $entityManager, $entityManager);
        $this->userLookup   = $userLookup ?? new DoctrineUserLookup($entityManager, $this->identifierMatcher);
        $this->userFactory  = $userFactory ?? new PropertyAccessUserFactory($passwordHasher, $propertyAccessor);
        $this->userWriter   = $userWriter ?? new DoctrineUserWriter($entityManager, $propertyAccessor, $entityManagerRecovery, $this->transactions);
    }

    /**
     * {@see self::linkOrCreate()} and the sign-in as one unit (GY-L9): if `$signIn` throws, the link, a new account and
     * the `link_existing_account: reset_password` replacement are rolled back with the rest of the transaction. UserKit's
     * `password_changed` runs after the commit, so a listener on it cannot undo them (HU-I2). The
     * order stays: the password goes before the sign-in, so the session of this sign-in survives it.
     *
     * @param array{access_token: string, refresh_token: ?string, expires_in: ?int} $tokens
     * @param callable(UserInterface): mixed $signIn
     */
    public function linkAndSignIn(ProfileSettings $profile, string $provider, SocialUserProfile $socialProfile, array $tokens, callable $signIn): void
    {
        $created = null;

        try {
            $unit = function () use ($profile, $provider, $socialProfile, $tokens, $signIn, &$created): array {
                $linked = $this->link($profile, $provider, $socialProfile, $tokens, $created);
                $signIn($linked[0]);
                // On PostgreSQL a listener that swallowed a database error leaves the transaction aborted, and COMMIT then
                // rolls back without an error, with the sign-in kept (HP-L1). A statement fails there, so it counts as failed.
                $connection = $this->transactions->connection();
                $connection->executeQuery($connection->getDatabasePlatform()->getDummySelectSQL());

                return $linked;
            };
            [, $events] = $this->locked($profile, $provider, $socialProfile, fn (): array => $this->transactions->runWithAccounts($unit));
        } catch (Throwable $exception) {
            // 1.50.0: AuthKit's runner rolled the unit back and detached the rows it touched (the link, a new account, a
            // replaced password); the other entities of the request stay managed.
            $this->compensate($profile, $created);

            throw $exception;
        }

        $this->dispatchAll($events);
    }

    /**
     * Writes at once and signs nobody in. A handler that signs in afterwards should prefer {@see self::linkAndSignIn()}.
     *
     * @param array{access_token: string, refresh_token: ?string, expires_in: ?int} $tokens
     */
    public function linkOrCreate(
        ProfileSettings $profile,
        string $provider,
        SocialUserProfile $socialProfile,
        array $tokens,
    ): UserInterface {
        $created = null;

        try {
            $unit = function () use ($profile, $provider, $socialProfile, $tokens, &$created): array {
                return $this->link($profile, $provider, $socialProfile, $tokens, $created);
            };
            [$user, $events] = $this->locked($profile, $provider, $socialProfile, fn (): array => $this->transactions->runWithAccounts($unit));
        } catch (Throwable $exception) {
            $this->compensate($profile, $created);

            throw $exception;
        }
        $this->dispatchAll($events);

        return $user;
    }

    /**
     * @param array{access_token: string, refresh_token: ?string, expires_in: ?int} $tokens
     *
     * @param-out UserInterface|null $created the user created by this call (compensated on failure)
     *
     * @return array{UserInterface, list<object>} the user and the events to dispatch once stored
     */
    private function link(
        ProfileSettings $profile,
        string $provider,
        SocialUserProfile $socialProfile,
        array $tokens,
        ?UserInterface &$created,
    ): array {
        // REQ-SEC-006 (1.47.0): the provider subject is a key matched byte for byte; one that cannot be told apart from
        // a look-alike (edge white space, control or format characters, invalid UTF-8, too long) is refused.
        if (!ExactIdentifier::usable($socialProfile->id, SocialLoginAccount::PROVIDER_USER_ID_MAX_LENGTH)) {
            throw new RuntimeException('The social provider returned an unusable subject identifier.');
        }
        // SEC-004 H2: only a link made for this profile's user class signs in here.
        $account = $this->accountRepository->findOneByProviderSubject($provider, $socialProfile->id, $profile->userClass);
        if ($account instanceof SocialLoginAccount) {
            $user = $this->loadUser($profile, $profile->userClass, $account->getUserId());
            $this->refreshAccountTokens($account, $socialProfile, $tokens);
            // Flushed by AuthKit's runner inside the unit of work (1.50.0).
            $this->accountManager()->persist($account);

            return [$user, []];
        }

        $requireVerified = (bool) ($profile->socialLogin['require_verified_email'] ?? true);

        $user   = null;
        $events = [];
        if ($socialProfile->email !== null && $socialProfile->email !== '') {
            $candidate = $this->findUserByIdentifier($profile, $socialProfile->email);
            if ($candidate instanceof UserInterface) {
                if ($requireVerified && $socialProfile->emailVerified !== true) {
                    throw new RuntimeException('Cannot link social identity to an existing account: the provider did not assert a verified email.');
                }
                $user = $this->linkExistingAccount($profile, $candidate, $events);
            }
        }

        $createIfMissing = (bool) ($profile->socialLogin['create_user_if_missing'] ?? false);
        if (!$user instanceof UserInterface) {
            if (!$createIfMissing) {
                throw new RuntimeException('No local account matches this social identity and create_user_if_missing is false.');
            }
            // SEC-004 M1: social sign-up follows registration_mode like the registration page.
            if ($this->registrationGate instanceof RegistrationGate && !$this->registrationGate->isRegistrationAllowed($profile->name)) {
                throw new RuntimeException('No local account matches this social identity and registration is not allowed for this profile.');
            }
            if ($socialProfile->email === null || $socialProfile->email === '') {
                throw new RuntimeException('Social provider did not return an email address; cannot create a local user.');
            }
            if ($requireVerified && $socialProfile->emailVerified !== true) {
                throw new RuntimeException('Cannot create a local account from social login: the provider did not assert a verified email.');
            }
            $user     = $this->createUser($profile, $socialProfile);
            $created  = $user;
            $events[] = UserRegisteredEvent::of($profile->name, $user, UserCreationSource::Social);
        }

        $userId  = $this->resolveUserId($user);
        $account = (new SocialLoginAccount())
            ->setProvider($provider)
            ->setProviderUserId($socialProfile->id)
            ->setUserClass($profile->userClass)
            ->setUserId($userId)
            ->setUserIdentifier($user->getUserIdentifier())
            ->setEmail($socialProfile->email)
            ->setDisplayName($socialProfile->name)
            ->setRawProfile($socialProfile->raw);
        $this->refreshAccountTokens($account, $socialProfile, $tokens);
        // Flushed by AuthKit's runner inside the unit of work (1.50.0), under the social identity's lock.
        $this->accountManager()->persist($account);

        return [$user, $events];
    }

    /**
     * 1.50.0 (Beacon spec 239): the unit of work under the named locks of {@see AccountLock}, in its order: the social
     * identity (two callbacks of one identity never link it twice: the second one finds the first one's link), then,
     * when an account may be created, the provider's e-mail address as the account identifier.
     *
     * @template T
     *
     * @param Closure(): T $work
     *
     * @return T
     */
    private function locked(ProfileSettings $profile, string $provider, SocialUserProfile $socialProfile, Closure $work): mixed
    {
        $createIfMissing = (bool) ($profile->socialLogin['create_user_if_missing'] ?? false);

        return AccountLock::forSocialIdentity(
            $this->transactions,
            $provider,
            $socialProfile->id,
            $profile->userClass,
            fn (): mixed => $createIfMissing ? AccountLock::forIdentifier($this->transactions, $profile, $socialProfile->email, $work) : $work(),
        );
    }

    /**
     * @param list<object> $events
     */
    private function dispatchAll(array $events): void
    {
        foreach ($events as $event) {
            $this->eventDispatcher?->dispatch($event);
        }
    }

    /**
     * JW-A2: a host writer that stores outside the bundle's transaction is not rolled back with it; the account created
     * by the failed attempt is removed again. The Doctrine default is rolled back with the transaction (nothing to do).
     * A failing removal does not hide the original error.
     */
    private function compensate(ProfileSettings $profile, ?UserInterface $created): void
    {
        if (!$created instanceof UserInterface || $this->userWriter instanceof DoctrineUserWriter) {
            return;
        }

        try {
            // @igor-ignore - Stateless user port call (removes the host user); no service state changes.
            $this->userWriter->remove($profile, $created);
        } catch (Throwable) {
            // The original failure is what the caller must see.
        }
    }

    /**
     * @param array{access_token: string, refresh_token: ?string, expires_in: ?int} $tokens
     */
    private function refreshAccountTokens(
        SocialLoginAccount $account,
        SocialUserProfile $socialProfile,
        array $tokens,
    ): void {
        $account
            ->setAccessToken($tokens['access_token'])
            ->setRefreshToken($tokens['refresh_token'])
            ->setEmail($socialProfile->email ?? $account->getEmail())
            ->setDisplayName($socialProfile->name ?? $account->getDisplayName())
            ->setRawProfile($socialProfile->raw);

        $expiresIn = $tokens['expires_in'] ?? null;
        if (is_int($expiresIn) && $expiresIn > 0) {
            $account->setTokenExpiresAt(
                DateTimeImmutable::createFromInterface($this->clock->now())
                    ->add(new DateInterval('PT' . $expiresIn . 'S')),
            );
        }
    }

    /**
     * SEC-004 M2: the local account may have been registered by someone else with this (unverified) address. Unless
     * the application verifies e-mail ownership (`keep`), the first link either is refused (`deny`) or replaces the
     * local password with a random one (`reset_password`): a password chosen by a pre-registering attacker, and the
     * sessions authenticated with it, stop working.
     */
    /**
     * @param list<object> $events receives a {@see CredentialsChangedEvent} when a host writer replaced the password
     */
    private function linkExistingAccount(ProfileSettings $profile, UserInterface $user, array &$events): UserInterface
    {
        $policy = $profile->socialLogin['link_existing_account'] ?? 'reset_password';

        if ($policy === 'deny') {
            throw new RuntimeException('A local account already uses this email address: sign in with it (link_existing_account is deny).');
        }

        if ($policy === 'reset_password' && $user instanceof PasswordAuthenticatedUserInterface) {
            $this->userWriter->changePassword($profile, $user, $this->passwordHasher->hashPassword($user, bin2hex(random_bytes(32))));
            foreach (['token_field', 'token_expires_field'] as $key) {
                $field = $profile->passwordReset[$key] ?? null;
                if (is_string($field) && $field !== '' && $this->propertyAccessor->isWritable($user, $field)) {
                    $this->propertyAccessor->setValue($user, $field, null);
                }
            }
            // JW-A1: stored now, inside the link transaction: a writer that defers to save() must not keep the old password.
            $this->userWriter->save($profile, $user);
            // JW-A4: the Doctrine listeners see the change of a Doctrine writer; a host writer gets the explicit event.
            if (!$this->userWriter instanceof DoctrineUserWriter) {
                $events[] = new CredentialsChangedEvent($user);
            }
        }

        return $user;
    }

    private function findUserByIdentifier(ProfileSettings $profile, string $identifier): ?UserInterface
    {
        // SEC-004 H1: the stored identifier must equal the IdP address (no collation look-alike match). Re-checked
        // here (JW-A3): a host lookup's query may be looser than the contract.
        $user = $this->userLookup->findByIdentifier($profile, $identifier);

        return $user instanceof UserInterface && $this->identifierMatcher->matchesUser($user, $profile->userIdentifierField, $identifier) ? $user : null;
    }

    private function createUser(ProfileSettings $profile, SocialUserProfile $socialProfile): UserInterface&PasswordAuthenticatedUserInterface
    {
        // 1.49.0 (audit OV): refused / case-variant identifiers never reach the factory, which receives the normalised one.
        $newUser = $this->identifierMatcher->prepareNewUser($profile, NewUser::forSocial(
            (string) $socialProfile->email,
            $socialProfile->name,
            bin2hex(random_bytes(32)),
            [$profile->registrationRole],
        ), $this->userLookup, 'Social sign-up failed');
        $user = $this->userFactory->create($profile, $newUser);
        // 1.49.0: stored in the form lookups use (identifier_normalizer); unchanged with the default normaliser.
        $this->identifierMatcher->normalizeStored($user, $profile->userIdentifierField);
        // @igor-ignore - Stateless user port call (stores the host user); no service state changes.
        $this->userWriter->add($profile, $user);

        return $user;
    }

    private function loadUser(ProfileSettings $profile, string $userClass, string $userId): UserInterface
    {
        if (!class_exists($userClass)) {
            throw new RuntimeException(sprintf('Linked social user class "%s" does not exist.', $userClass));
        }

        $user = $this->userLookup->findById($profile, $userId);
        if (!$user instanceof UserInterface || !$user instanceof $userClass) {
            throw new RuntimeException(sprintf('Linked social user "%s" (%s) was not found.', $userId, $userClass));
        }

        return $user;
    }

    private function resolveUserId(UserInterface $user): string
    {
        return UserIds::of($user, $this->propertyAccessor) ?? $user->getUserIdentifier();
    }

    /**
     * The manager of the social account rows: the private one with `doctrine.private_entity_manager: true` (1.46.0),
     * else the application's (1.45). Both share the connection, so the link transaction covers them.
     */
    private function accountManager(): EntityManagerInterface
    {
        return $this->privateEntityManager ?? $this->entityManager;
    }
}
