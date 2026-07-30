<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\SocialLogin;

use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\AuthKitBundle\Entity\SocialLoginAccount;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Repository\SocialLoginAccountRepository;
use Psr\Clock\ClockInterface;
use RuntimeException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function is_int;
use function is_string;
use function method_exists;
use function sprintf;

/**
 * Resolves or creates the application user and persists social account credentials.
 */
final class SocialAccountLinker
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SocialLoginAccountRepository $accountRepository,
        private readonly PropertyAccessorInterface $propertyAccessor,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param array{access_token: string, refresh_token: ?string, expires_in: ?int} $tokens
     */
    public function linkOrCreate(
        ProfileSettings $profile,
        string $provider,
        SocialUserProfile $socialProfile,
        array $tokens,
    ): UserInterface {
        $account = $this->accountRepository->findOneByProviderSubject($provider, $socialProfile->id);
        if ($account instanceof SocialLoginAccount) {
            $user = $this->loadUser($profile->userClass, $account->getUserId());
            $this->refreshAccountTokens($account, $socialProfile, $tokens);
            $this->entityManager->flush();

            return $user;
        }

        $user = null;
        if ($socialProfile->email !== null && $socialProfile->email !== '') {
            $user = $this->findUserByIdentifier($profile, $socialProfile->email);
        }

        $createIfMissing = (bool) ($profile->socialLogin['create_user_if_missing'] ?? true);
        if (!$user instanceof UserInterface) {
            if (!$createIfMissing) {
                throw new RuntimeException('No local account matches this social identity and create_user_if_missing is false.');
            }
            if ($socialProfile->email === null || $socialProfile->email === '') {
                throw new RuntimeException('Social provider did not return an email address; cannot create a local user.');
            }
            $user = $this->createUser($profile, $socialProfile);
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
        $this->entityManager->flush();

        return $user;
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

    private function findUserByIdentifier(ProfileSettings $profile, string $identifier): ?UserInterface
    {
        $user = $this->entityManager->getRepository($profile->userClass)->findOneBy([
            $profile->userIdentifierField => $identifier,
        ]);

        return $user instanceof UserInterface ? $user : null;
    }

    private function createUser(ProfileSettings $profile, SocialUserProfile $socialProfile): UserInterface&PasswordAuthenticatedUserInterface
    {
        /** @var PasswordAuthenticatedUserInterface&UserInterface $user */
        $user = new ($profile->userClass)();

        $this->propertyAccessor->setValue($user, $profile->userIdentifierField, $socialProfile->email);

        if ($socialProfile->name !== null && $this->propertyAccessor->isWritable($user, 'displayName')) {
            $this->propertyAccessor->setValue($user, 'displayName', $socialProfile->name);
        } elseif ($socialProfile->name !== null && $this->propertyAccessor->isWritable($user, 'name')) {
            $this->propertyAccessor->setValue($user, 'name', $socialProfile->name);
        }

        $random = bin2hex(random_bytes(32));
        $this->propertyAccessor->setValue($user, 'password', $this->passwordHasher->hashPassword($user, $random));

        if (method_exists($user, 'setRoles')) {
            $user->setRoles([$profile->registrationRole]);
        } elseif ($this->propertyAccessor->isWritable($user, 'roles')) {
            $this->propertyAccessor->setValue($user, 'roles', [$profile->registrationRole]);
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function loadUser(string $userClass, string $userId): UserInterface
    {
        if (!class_exists($userClass)) {
            throw new RuntimeException(sprintf('Linked social user class "%s" does not exist.', $userClass));
        }

        $user = $this->entityManager->find($userClass, is_numeric($userId) ? (int) $userId : $userId);
        if (!$user instanceof UserInterface) {
            throw new RuntimeException(sprintf('Linked social user "%s" (%s) was not found.', $userId, $userClass));
        }

        return $user;
    }

    private function resolveUserId(UserInterface $user): string
    {
        if (method_exists($user, 'getId')) {
            $id = $user->getId();
            if (is_int($id) || (is_string($id) && $id !== '')) {
                return (string) $id;
            }
        }

        if ($this->propertyAccessor->isReadable($user, 'id')) {
            $id = $this->propertyAccessor->getValue($user, 'id');
            if (is_int($id) || (is_string($id) && $id !== '')) {
                return (string) $id;
            }
        }

        return $user->getUserIdentifier();
    }
}
