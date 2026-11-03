<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Mfa;

use Nowo\AuthKitBundle\Mfa\Totp\Base32;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\QrLogin\QrCodeGeneratorInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;

use function hash_equals;
use function is_array;
use function is_int;
use function is_string;

/**
 * Enrolment state (1.34): the new secret lives only in the user's session (bound to the owner, at most
 * {@see self::LIFETIME} seconds) until a code confirms it; the QR code is rendered server-side (no external API) and
 * nothing here is ever logged.
 */
final readonly class MfaEnrolment
{
    public const SESSION_KEY = '_nowo_auth_kit_mfa_enrolment';

    /** Seconds the unconfirmed secret stays in the session (DD-I4: short, the session store holds it in clear). */
    public const LIFETIME = 300;

    /** Session attribute: time of the session's last full sign-in (first factor, plus the second when asked). */
    public const SIGNED_IN_AT = '_nowo_auth_kit_mfa_signed_in_at';

    public function __construct(
        private MfaManager $manager,
        private MfaOwnerResolver $owners,
        private QrCodeGeneratorInterface $qrCodes,
    ) {
    }

    /**
     * The pending secret of this user's enrolment (a new one when there is none, it expired or belongs to someone
     * else).
     */
    public function secret(Request $request, UserInterface $user): string
    {
        $session = $request->getSession();
        $owner   = $this->owners->resolve($user)?->key() ?? '';
        $state   = $session->get(self::SESSION_KEY);
        $now     = $this->manager->now()->getTimestamp();
        if (is_array($state) && is_string($state['owner'] ?? null) && hash_equals($state['owner'], $owner) && is_string($state['secret'] ?? null) && is_int($state['at'] ?? null) && $now - $state['at'] < self::LIFETIME) {
            return $state['secret'];
        }
        $secret = $this->manager->newSecret();
        // @igor-ignore - The request's own session (not an injected service).
        $session->set(self::SESSION_KEY, ['owner' => $owner, 'secret' => $secret, 'at' => $now]);

        return $secret;
    }

    /**
     * Records a full sign-in of the request's session (not a remember-me one) at `$now`.
     */
    public static function signedIn(Request $request, int $now): void
    {
        if ($request->hasSession()) {
            $request->getSession()->set(self::SIGNED_IN_AT, $now);
        }
    }

    /**
     * Whether the session signed in within `mfa.recent_sign_in` seconds (DD-I1: setting up a second factor from a
     * long-lived session asks for a fresh sign-in, so a hijacked session cannot bind the attacker's authenticator).
     */
    public function isRecent(Request $request, ProfileSettings $profile): bool
    {
        $at = $request->hasSession() ? $request->getSession()->get(self::SIGNED_IN_AT) : null;

        return is_int($at) && $this->manager->now()->getTimestamp() - $at <= (int) ($profile->mfa['recent_sign_in'] ?? 900);
    }

    public function clear(Request $request): void
    {
        $request->getSession()->remove(self::SESSION_KEY);
    }

    /**
     * Template data of the enrolment step: the QR code (data URI, null without a QR generator) and the secret in
     * groups of four for manual entry.
     *
     * @return array{qr: string|null, secret: string, issuer: string, label: string}
     */
    public function view(UserInterface $user, string $secret): array
    {
        $profile = $this->manager->profileFor($user);

        return [
            'qr'     => $this->qrCodes->generateDataUri($this->manager->provisioningUri($user, $secret)),
            'secret' => Base32::group($secret),
            'issuer' => (string) ($profile?->mfa['issuer'] ?? ''),
            'label'  => $user->getUserIdentifier(),
        ];
    }
}
