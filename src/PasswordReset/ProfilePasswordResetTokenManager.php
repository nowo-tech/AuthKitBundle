<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\PasswordReset;

use Nowo\AuthKitBundle\DependencyInjection\Configuration;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Profile\ProfileSettings;

/**
 * The reset credential store of each profile (1.46.0): {@see TablePasswordResetTokenManager} for the profiles with
 * `password_reset.storage: table`, {@see PasswordResetTokenManager} (the user entity) for the others. Registered as
 * {@see PasswordResetTokenManagerInterface} only when a profile uses the table; otherwise the interface keeps pointing
 * at the user-entity manager, as before. A user of no profile goes to the user-entity manager (which refuses it, as
 * before). Stateless.
 *
 * @internal
 */
final readonly class ProfilePasswordResetTokenManager implements PasswordResetTokenManagerInterface
{
    public function __construct(
        private ProfileRegistry $profileRegistry,
        private PasswordResetTokenManagerInterface $userEntity,
        private PasswordResetTokenManagerInterface $table,
    ) {
    }

    public function createForUser(object $user): PasswordResetTokenResult
    {
        return $this->forUser($user)->createForUser($user);
    }

    public function resolveUserByLinkToken(string $linkToken, ?string $profileName = null): ?object
    {
        return $this->forProfile($profileName)->resolveUserByLinkToken($linkToken, $profileName);
    }

    public function resolveUserByIdentifierAndCode(string $identifier, string $code, ?string $profileName = null): ?object
    {
        return $this->forProfile($profileName)->resolveUserByIdentifierAndCode($identifier, $code, $profileName);
    }

    public function clearForUser(object $user): void
    {
        $this->forUser($user)->clearForUser($user);
    }

    private function forUser(object $user): PasswordResetTokenManagerInterface
    {
        return $this->pick($this->profileRegistry->resolveForObject($user));
    }

    private function forProfile(?string $profileName): PasswordResetTokenManagerInterface
    {
        return $this->pick($profileName !== null ? $this->profileRegistry->getByName($profileName) : $this->profileRegistry->getDefault());
    }

    private function pick(?ProfileSettings $profile): PasswordResetTokenManagerInterface
    {
        return $profile instanceof ProfileSettings && self::usesTable($profile) ? $this->table : $this->userEntity;
    }

    public static function usesTable(ProfileSettings $profile): bool
    {
        return ($profile->passwordReset['storage'] ?? Configuration::PASSWORD_RESET_STORAGE_USER_ENTITY) === Configuration::PASSWORD_RESET_STORAGE_TABLE;
    }
}
