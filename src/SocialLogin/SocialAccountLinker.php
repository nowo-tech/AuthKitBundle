<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\SocialLogin;

use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\AuthKitBundle\Doctrine\EntityManagerRecovery;
use Nowo\AuthKitBundle\Entity\SocialLoginAccount;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Repository\SocialLoginAccountRepository;
use Nowo\AuthKitBundle\Security\RegistrationGate;
use Nowo\AuthKitBundle\Security\UserIdentifierMatcher;
use Nowo\AuthKitBundle\User\Doctrine\DoctrineUserLookup;
use Nowo\AuthKitBundle\User\Doctrine\DoctrineUserWriter;
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

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SocialLoginAccountRepository $accountRepository,
        private readonly PropertyAccessorInterface $propertyAccessor,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ClockInterface $clock,
        private readonly EntityManagerRecovery $entityManagerRecovery = new EntityManagerRecovery(),
        private readonly ?RegistrationGate $registrationGate = null,
        UserIdentifierMatcher $identifierMatcher = new UserIdentifierMatcher(),
        ?UserLookupInterface $userLookup = null,
        ?UserFactoryInterface $userFactory = null,
        ?UserWriterInterface $userWriter = null,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
    ) {
        $this->userLookup  = $userLookup ?? new DoctrineUserLookup($entityManager, $identifierMatcher);
        $this->userFactory = $userFactory ?? new PropertyAccessUserFactory($passwordHasher, $propertyAccessor);
        $this->userWriter  = $userWriter ?? new DoctrineUserWriter($entityManager, $propertyAccessor, $entityManagerRecovery);
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
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            [$user, $registered] = $this->link($profile, $provider, $socialProfile, $tokens);
            $signIn($user);
            // On PostgreSQL a listener that swallowed a database error leaves the transaction aborted, and COMMIT then
            // rolls back without an error, with the sign-in kept (HP-L1). A statement fails there, so it counts as failed.
            $connection->executeQuery($connection->getDatabasePlatform()->getDummySelectSQL());
            $connection->commit();
        } catch (Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            // The objects in memory still hold what was rolled back.
            // @igor-ignore - Unit of work of the entity manager (cleared per request by Doctrine's reset), not worker state.
            $this->entityManager->clear();

            throw $exception;
        }

        if ($registered instanceof UserRegisteredEvent) {
            $this->eventDispatcher?->dispatch($registered);
        }
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
        [$user, $registered] = $this->link($profile, $provider, $socialProfile, $tokens);
        if ($registered instanceof UserRegisteredEvent) {
            $this->eventDispatcher?->dispatch($registered);
        }

        return $user;
    }

    /**
     * @param array{access_token: string, refresh_token: ?string, expires_in: ?int} $tokens
     *
     * @return array{UserInterface, ?UserRegisteredEvent} the user and, when it was created, the event to dispatch once stored
     */
    private function link(
        ProfileSettings $profile,
        string $provider,
        SocialUserProfile $socialProfile,
        array $tokens,
    ): array {
        // SEC-004 H2: only a link made for this profile's user class signs in here.
        $account = $this->accountRepository->findOneByProviderSubject($provider, $socialProfile->id, $profile->userClass);
        if ($account instanceof SocialLoginAccount) {
            $user = $this->loadUser($profile, $profile->userClass, $account->getUserId());
            $this->refreshAccountTokens($account, $socialProfile, $tokens);
            $this->entityManagerRecovery->flush($this->entityManager);

            return [$user, null];
        }

        $requireVerified = (bool) ($profile->socialLogin['require_verified_email'] ?? true);

        $user = null;
        if ($socialProfile->email !== null && $socialProfile->email !== '') {
            $candidate = $this->findUserByIdentifier($profile, $socialProfile->email);
            if ($candidate instanceof UserInterface) {
                if ($requireVerified && $socialProfile->emailVerified !== true) {
                    throw new RuntimeException('Cannot link social identity to an existing account: the provider did not assert a verified email.');
                }
                $user = $this->linkExistingAccount($profile, $candidate);
            }
        }

        $createIfMissing = (bool) ($profile->socialLogin['create_user_if_missing'] ?? false);
        $registered      = null;
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
            $user       = $this->createUser($profile, $socialProfile);
            $registered = UserRegisteredEvent::of($profile->name, $user, UserCreationSource::Social);
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
        $this->entityManager->persist($account);
        $this->entityManagerRecovery->flush($this->entityManager);

        return [$user, $registered];
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
    private function linkExistingAccount(ProfileSettings $profile, UserInterface $user): UserInterface
    {
        $policy = $profile->socialLogin['link_existing_account'] ?? 'reset_password';

        if ($policy === 'deny') {
            throw new RuntimeException('A local account already uses this email address: sign in with it (link_existing_account is deny).');
        }

        if ($policy === 'reset_password' && $user instanceof PasswordAuthenticatedUserInterface) {
            // Stored by the flush of the account link below (default writer) or by the host writer itself.
            $this->userWriter->changePassword($profile, $user, $this->passwordHasher->hashPassword($user, bin2hex(random_bytes(32))));
            foreach (['token_field', 'token_expires_field'] as $key) {
                $field = $profile->passwordReset[$key] ?? null;
                if (is_string($field) && $field !== '' && $this->propertyAccessor->isWritable($user, $field)) {
                    $this->propertyAccessor->setValue($user, $field, null);
                }
            }
        }

        return $user;
    }

    private function findUserByIdentifier(ProfileSettings $profile, string $identifier): ?UserInterface
    {
        // SEC-004 H1: the stored identifier must equal the IdP address (no collation look-alike match).
        return $this->userLookup->findByIdentifier($profile, $identifier);
    }

    private function createUser(ProfileSettings $profile, SocialUserProfile $socialProfile): UserInterface&PasswordAuthenticatedUserInterface
    {
        $user = $this->userFactory->create($profile, NewUser::forSocial(
            (string) $socialProfile->email,
            $socialProfile->name,
            bin2hex(random_bytes(32)),
            [$profile->registrationRole],
        ));
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
}
