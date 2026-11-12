<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\PasswordReset;

use LogicException;
use Nowo\AuthKitBundle\Enum\PasswordResetDeliveryMode;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Repository\PasswordResetRequestRepository;
use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;
use Nowo\AuthKitBundle\User\UserIds;
use Nowo\AuthKitBundle\User\UserLookupInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function explode;
use function hash;
use function is_a;
use function is_string;
use function max;
use function sprintf;

/**
 * `password_reset.storage: table` (1.46.0, REQ-ARCH-001 rule 2): the reset credential lives in the bundle-owned
 * `auth_kit_password_reset` table ({@see PasswordResetRequestRepository}) instead of on the user, which is never
 * written by a reset request. Same rules as the user-entity storage ({@see PasswordResetTokenManager}): the same
 * generation, hashes and constant-time code check ({@see PasswordResetCredentials}), one pending credential per user
 * (a new request replaces it), expiry against the clock, the code attempt budget per account, and the credential
 * cleared when the budget is spent. The user of a row is found again through {@see UserLookupInterface} (by id, or by
 * identifier when it has none), so the answer is the current user, never a cached one. Stateless.
 */
final readonly class TablePasswordResetTokenManager implements PasswordResetTokenManagerInterface
{
    public function __construct(
        private PasswordResetRequestRepository $requests,
        private UserLookupInterface $userLookup,
        private PropertyAccessorInterface $propertyAccessor,
        private PasswordResetUserResolver $userResolver,
        private ProfileRegistry $profileRegistry,
        private ClockInterface $clock,
        private AuthKitAttemptLimiter $attemptLimiter,
    ) {
    }

    public function createForUser(object $user): PasswordResetTokenResult
    {
        $profile  = $this->requireProfileForUser($user);
        $settings = $profile->passwordReset;
        $delivery = PasswordResetDeliveryMode::from($settings['delivery']);
        $now      = $this->clock->now();
        $expires  = $now->modify('+' . $settings['token_ttl'] . ' seconds');
        $plain    = PasswordResetCredentials::generate($profile, $delivery);
        $stored   = explode('|', PasswordResetCredentials::storageValue($plain, $delivery), 2);

        $this->requests->save(new PasswordResetRequestRow(
            $profile->name,
            UserIds::className($user),
            UserIds::of($user, $this->propertyAccessor),
            $this->identifierOf($profile, $user),
            $stored[0],
            $stored[1] ?? null,
            $expires,
            $now,
        ));

        return new PasswordResetTokenResult($user, $plain, $expires, $delivery);
    }

    public function resolveUserByLinkToken(string $linkToken, ?string $profileName = null): ?object
    {
        $profile = $this->resolveProfile($profileName);
        $hash    = hash('sha256', $linkToken);
        foreach ($this->requests->findByTokenHash($hash) as $row) {
            if (!is_a($row->userClass, $profile->userClass, true)) {
                continue;
            }

            if (!$this->validExpiry($row)) {
                return null;
            }
            $user = $this->userOf($profile, $row);
            // Audit NV-L1: only the user's newest credential counts (two concurrent requests may both have stored one).
            $newest = $user !== null ? $this->requests->findForUser($row->userClass, $row->userId, $row->userIdentifier) : null;

            return $newest instanceof PasswordResetRequestRow && $newest->tokenHash === $row->tokenHash ? $user : null;
        }

        return null;
    }

    public function resolveUserByIdentifierAndCode(string $identifier, string $code, ?string $profileName = null): ?object
    {
        $profile     = $this->resolveProfile($profileName);
        $maxAttempts = (int) ($profile->passwordReset['max_code_attempts'] ?? 5);
        $window      = max(60, (int) ($profile->passwordReset['token_ttl'] ?? 3600));
        $user        = $this->userResolver->findByIdentifier($identifier, $profile->name);
        $attemptKey  = PasswordResetCredentials::codeAttemptKey($this->propertyAccessor, $profile, $user, $identifier);

        if (!$this->attemptLimiter->isAllowed($attemptKey, $maxAttempts)) {
            if ($user instanceof UserInterface) {
                $this->clearForUser($user);
            }
            $this->attemptLimiter->reset($attemptKey);

            return null;
        }

        if (!$user instanceof UserInterface) {
            $this->attemptLimiter->hit($attemptKey, $window);

            return null;
        }

        $row = $this->requests->findForUser(UserIds::className($user), UserIds::of($user, $this->propertyAccessor), $this->identifierOf($profile, $user));
        if (!$row instanceof PasswordResetRequestRow) {
            $this->attemptLimiter->hit($attemptKey, $window);

            return null;
        }

        $stored = $row->codeHash !== null ? $row->tokenHash . '|' . $row->codeHash : $row->tokenHash;
        if (!PasswordResetCredentials::codeMatches($stored, $code)) {
            $this->attemptLimiter->hit($attemptKey, $window);
            if (!$this->attemptLimiter->isAllowed($attemptKey, $maxAttempts)) {
                $this->clearForUser($user);
                $this->attemptLimiter->reset($attemptKey);
            }

            return null;
        }

        if (!$this->validExpiry($row)) {
            $this->attemptLimiter->hit($attemptKey, $window);

            return null;
        }

        $this->attemptLimiter->reset($attemptKey);

        return $user;
    }

    public function clearForUser(object $user): void
    {
        $profile = $this->requireProfileForUser($user);

        $this->requests->delete(UserIds::className($user), UserIds::of($user, $this->propertyAccessor), [$this->identifierOf($profile, $user)]);
    }

    /**
     * The current user of a row: found again by id (or by identifier for a user without an id) through the user
     * lookup port, and only when it is still that user.
     */
    private function userOf(ProfileSettings $profile, PasswordResetRequestRow $row): ?object
    {
        $user = $row->userId !== null
            ? $this->userLookup->findById($profile, $row->userId)
            : $this->userLookup->findByIdentifier($profile, $row->userIdentifier);
        if (!$user instanceof UserInterface || UserIds::className($user) !== $row->userClass) {
            return null;
        }
        if ($row->userId !== null ? UserIds::of($user, $this->propertyAccessor) !== $row->userId : $this->identifierOf($profile, $user) !== $row->userIdentifier) {
            return null;
        }

        return $user;
    }

    private function validExpiry(PasswordResetRequestRow $row): bool
    {
        return $row->expiresAt >= $this->clock->now();
    }

    /**
     * The value of the profile's identifier field (the row's `user_identifier`), else the security identifier.
     */
    private function identifierOf(ProfileSettings $profile, object $user): string
    {
        $stored = $this->propertyAccessor->isReadable($user, $profile->userIdentifierField)
            ? $this->propertyAccessor->getValue($user, $profile->userIdentifierField)
            : null;
        if (is_string($stored) && $stored !== '') {
            return $stored;
        }

        return $user instanceof UserInterface ? $user->getUserIdentifier() : '';
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
