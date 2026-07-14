<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\PasswordReset;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Nowo\AuthKitBundle\Enum\PasswordResetDeliveryMode;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

use function bin2hex;
use function hash;
use function is_string;
use function random_bytes;
use function random_int;
use function sprintf;
use function strlen;

/**
 * Stores hashed reset credentials on configurable user entity properties.
 */
final class PasswordResetTokenManager implements PasswordResetTokenManagerInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PropertyAccessorInterface $propertyAccessor,
        private readonly PasswordResetUserResolver $userResolver,
        private readonly ProfileRegistry $profileRegistry,
    ) {
    }

    public function createForUser(object $user): PasswordResetTokenResult
    {
        $profile  = $this->requireProfileForUser($user);
        $settings = $profile->passwordReset;
        $delivery = PasswordResetDeliveryMode::from($settings['delivery']);
        $expires  = new DateTimeImmutable('+' . $settings['token_ttl'] . ' seconds');
        $plain    = match ($delivery) {
            PasswordResetDeliveryMode::Link => bin2hex(random_bytes($settings['token_bytes'])),
            PasswordResetDeliveryMode::Code => $this->generateCode($profile),
            PasswordResetDeliveryMode::Both => bin2hex(random_bytes($settings['token_bytes'])) . ':' . $this->generateCode($profile),
        };
        $stored = $this->storageValue($plain, $delivery);

        $this->propertyAccessor->setValue($user, $settings['token_field'], $stored);
        $this->propertyAccessor->setValue($user, $settings['token_expires_field'], $expires);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return new PasswordResetTokenResult($user, $plain, $expires, $delivery);
    }

    public function resolveUserByLinkToken(string $linkToken, ?string $profileName = null): ?object
    {
        $profile = $this->resolveProfile($profileName);
        $hash    = hash('sha256', $linkToken);
        $user    = $this->findUserByStoredToken($profile, $hash);

        return $this->validateExpiry($profile, $user);
    }

    public function resolveUserByIdentifierAndCode(string $identifier, string $code, ?string $profileName = null): ?object
    {
        $profile = $this->resolveProfile($profileName);
        $user    = $this->userResolver->findByIdentifier($identifier, $profile->name);

        if ($user === null) {
            return null;
        }

        $tokenField = $profile->passwordReset['token_field'];
        $stored     = $this->propertyAccessor->getValue($user, $tokenField);

        if (!is_string($stored)) {
            return null;
        }

        $codeHash = hash('sha256', $code);

        if ($stored !== $codeHash && !str_ends_with($stored, '|' . $codeHash)) {
            return null;
        }

        return $this->validateExpiry($profile, $user);
    }

    public function clearForUser(object $user): void
    {
        $profile      = $this->requireProfileForUser($user);
        $tokenField   = $profile->passwordReset['token_field'];
        $expiresField = $profile->passwordReset['token_expires_field'];

        $this->propertyAccessor->setValue($user, $tokenField, null);
        $this->propertyAccessor->setValue($user, $expiresField, null);

        $this->entityManager->persist($user);
        $this->entityManager->flush();
    }

    private function findUserByStoredToken(ProfileSettings $profile, string $hash): ?object
    {
        $tokenField = $profile->passwordReset['token_field'];
        $repository = $this->entityManager->getRepository($profile->userClass);

        /** @var object|null $user */
        $user = $repository->createQueryBuilder('u')
            ->where('u.' . $tokenField . ' = :hash OR u.' . $tokenField . ' LIKE :prefix')
            ->setParameter('hash', $hash)
            ->setParameter('prefix', $hash . '|%')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $user;
    }

    private function validateExpiry(ProfileSettings $profile, ?object $user): ?object
    {
        if ($user === null) {
            return null;
        }

        $expiresField = $profile->passwordReset['token_expires_field'];
        $expires      = $this->propertyAccessor->getValue($user, $expiresField);

        if (!$expires instanceof DateTimeImmutable && !$expires instanceof DateTimeInterface) {
            return null;
        }

        if ($expires < new DateTimeImmutable()) {
            return null;
        }

        return $user;
    }

    private function storageValue(string $plain, PasswordResetDeliveryMode $delivery): string
    {
        return match ($delivery) {
            PasswordResetDeliveryMode::Link => hash('sha256', $plain),
            PasswordResetDeliveryMode::Code => hash('sha256', $plain),
            PasswordResetDeliveryMode::Both => hash('sha256', explode(':', $plain, 2)[0]) . '|' . hash('sha256', explode(':', $plain, 2)[1]),
        };
    }

    private function generateCode(ProfileSettings $profile): string
    {
        $settings = $profile->passwordReset;

        $charset = $settings['code_charset'] === 'alphanumeric'
            ? '23456789ABCDEFGHJKLMNPQRSTUVWXYZ'
            : '0123456789';

        $maxIndex = strlen($charset) - 1;
        $code     = '';

        for ($i = 0; $i < $settings['code_length']; ++$i) {
            $code .= $charset[random_int(0, $maxIndex)];
        }

        return $code;
    }

    private function requireProfileForUser(object $user): ProfileSettings
    {
        $profile = $this->profileRegistry->resolveForObject($user);

        if (!$profile instanceof ProfileSettings) {
            throw new LogicException(sprintf('No Auth Kit profile is configured for user class "%s".', $user::class));
        }

        return $profile;
    }

    private function resolveProfile(?string $profileName): ProfileSettings
    {
        return $profileName !== null
            ? $this->profileRegistry->getByName($profileName)
            : $this->profileRegistry->getDefault();
    }
}
