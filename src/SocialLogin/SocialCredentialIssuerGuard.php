<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\SocialLogin;

use Nowo\AuthKitBundle\Entity\SocialLoginCredential;
use Nowo\AuthKitBundle\Repository\SocialLoginAccountRepository;

use function rtrim;
use function sprintf;
use function trim;

/**
 * For administration screens (2.3.0, audit ZBP-L1): whether the issuer of a credential may change.
 *
 * A provider key is one subject namespace only together with its issuer: `sub` 1234 on gitlab.com and `sub` 1234 on a
 * self-hosted GitLab are two different people. The sign-in already refuses a link made under another issuer
 * ({@see SocialAccountLinker}); this guard lets an admin form say so up front instead of leaving stale links behind.
 * While the provider has linked accounts, its issuer is locked: unlink them first, or create a new provider key.
 *
 * Stateless (REQ-RUNTIME-002).
 */
final class SocialCredentialIssuerGuard
{
    public const ERROR = 'The issuer of the "%s" provider cannot change while accounts are linked to it: they were linked under the current issuer. Unlink them first, or add the new issuer under a new provider key.';

    public function __construct(
        private readonly SocialLoginAccountRepository $accounts,
    ) {
    }

    /**
     * @return string|null why the change is refused, or null when it is allowed (same issuer, or no linked account)
     */
    public function issuerChangeError(SocialLoginCredential $credential, ?string $newIssuer): ?string
    {
        if (self::normalize($credential->getIssuer()) === self::normalize($newIssuer)) {
            return null;
        }
        if ($credential->getProvider() === '' || $this->accounts->count(['provider' => $credential->getProvider()]) === 0) {
            return null;
        }

        return sprintf(self::ERROR, $credential->getProvider());
    }

    private static function normalize(?string $issuer): ?string
    {
        $issuer = $issuer === null ? '' : rtrim(trim($issuer), '/');

        return $issuer === '' ? null : $issuer;
    }
}
