<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\PasswordReset;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Nowo\AuthKitBundle\Enum\PasswordResetDeliveryMode;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

use function bin2hex;
use function hash;
use function is_string;
use function random_bytes;
use function random_int;
use function strlen;

/**
 * Stores hashed reset credentials on configurable user entity properties.
 */
final class PasswordResetTokenManager implements PasswordResetTokenManagerInterface
{
    /**
     * @param class-string<object> $userClass
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PropertyAccessorInterface $propertyAccessor,
        private readonly PasswordResetUserResolver $userResolver,
        private readonly string $userClass,
        private readonly string $tokenField,
        private readonly string $tokenExpiresField,
        private readonly int $tokenTtl,
        private readonly int $tokenBytes,
        private readonly int $codeLength,
        private readonly string $codeCharset,
        private readonly string $deliveryMode,
    ) {
        if ($this->tokenBytes < 1) {
            throw new LogicException('password_reset.token_bytes must be at least 1.');
        }
    }

    public function createForUser(object $user): PasswordResetTokenResult
    {
        $delivery = PasswordResetDeliveryMode::from($this->deliveryMode);
        $expires  = new DateTimeImmutable('+' . $this->tokenTtl . ' seconds');
        $plain    = match ($delivery) {
            PasswordResetDeliveryMode::Link => bin2hex(random_bytes($this->tokenBytes)),
            PasswordResetDeliveryMode::Code => $this->generateCode(),
            PasswordResetDeliveryMode::Both => bin2hex(random_bytes($this->tokenBytes)) . ':' . $this->generateCode(),
        };
        $stored = $this->storageValue($plain, $delivery);

        $this->propertyAccessor->setValue($user, $this->tokenField, $stored);
        $this->propertyAccessor->setValue($user, $this->tokenExpiresField, $expires);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return new PasswordResetTokenResult($user, $plain, $expires, $delivery);
    }

    public function resolveUserByLinkToken(string $linkToken): ?object
    {
        $hash = hash('sha256', $linkToken);

        $user = $this->findUserByStoredToken($hash);

        return $this->validateExpiry($user);
    }

    public function resolveUserByIdentifierAndCode(string $identifier, string $code): ?object
    {
        $user = $this->userResolver->findByIdentifier($identifier);

        if ($user === null) {
            return null;
        }

        $stored = $this->propertyAccessor->getValue($user, $this->tokenField);

        if (!is_string($stored)) {
            return null;
        }

        $codeHash = hash('sha256', $code);

        if ($stored !== $codeHash && !str_ends_with($stored, '|' . $codeHash)) {
            return null;
        }

        return $this->validateExpiry($user);
    }

    public function clearForUser(object $user): void
    {
        $this->propertyAccessor->setValue($user, $this->tokenField, null);
        $this->propertyAccessor->setValue($user, $this->tokenExpiresField, null);

        $this->entityManager->persist($user);
        $this->entityManager->flush();
    }

    private function findUserByStoredToken(string $hash): ?object
    {
        $repository = $this->entityManager->getRepository($this->userClass);

        /** @var object|null $user */
        $user = $repository->createQueryBuilder('u')
            ->where('u.' . $this->tokenField . ' = :hash OR u.' . $this->tokenField . ' LIKE :prefix')
            ->setParameter('hash', $hash)
            ->setParameter('prefix', $hash . '|%')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $user;
    }

    private function validateExpiry(?object $user): ?object
    {
        if ($user === null) {
            return null;
        }

        $expires = $this->propertyAccessor->getValue($user, $this->tokenExpiresField);

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

    private function generateCode(): string
    {
        $charset = $this->codeCharset === 'alphanumeric'
            ? '23456789ABCDEFGHJKLMNPQRSTUVWXYZ'
            : '0123456789';

        $maxIndex = strlen($charset) - 1;
        $code     = '';

        for ($i = 0; $i < $this->codeLength; ++$i) {
            $code .= $charset[random_int(0, $maxIndex)];
        }

        return $code;
    }
}
