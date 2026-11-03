<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Mfa;

use DateTimeImmutable;
use Nowo\AuthKitBundle\Entity\MfaCredential;
use Nowo\AuthKitBundle\Mfa\Event\MfaTrustedDeviceAddedEvent;
use Nowo\AuthKitBundle\Mfa\Event\MfaTrustedDevicesForgottenEvent;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\UserKitBundle\Sessions\Client\SessionClientFactory;
use Nowo\UserKitBundle\Sessions\UserSessionManager;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_filter;
use function array_slice;
use function array_values;
use function base64_encode;
use function bin2hex;
use function count;
use function explode;
use function hash_hmac;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function preg_match;
use function random_bytes;
use function rtrim;
use function sprintf;
use function str_contains;
use function strtr;

/**
 * "Trust this device for N days" (1.34, D-M3): a browser keeps one opaque token per user in an httpOnly cookie
 * (`__Host-` on HTTPS, SameSite=Lax, no identity inside); the server keeps only its HMAC with an expiry. A trusted
 * device skips the TOTP step (never the forced enrolment); its token is rotated on every use. With UserKit sessions
 * and a device id resolver, the device id is kept: forgetting the device signs its sessions out (`revokeDevice()`). The limit is UserKit's
 * `maxTrustedDevices` when set, else `mfa.trusted_devices.max` (the least recently used device is forgotten).
 *
 * Cookie changes are queued on the request and written by {@see EventSubscriber\MfaResponseSubscriber}.
 */
final readonly class MfaTrustedDevices
{
    public const COOKIE = 'nowo_auth_kit_mfa_device';

    /** Request attribute: the cookie value to write on the response. */
    public const QUEUE_ATTRIBUTE = '_nowo_auth_kit_mfa_device_cookie';

    /** Tokens one browser keeps (several accounts). */
    public const MAX_TOKENS = 10;

    public function __construct(
        private MfaManager $manager,
        private MfaStore $store,
        private ?EventDispatcherInterface $dispatcher = null,
        private ?UserSessionManager $sessions = null,
        private ?SessionClientFactory $clients = null,
    ) {
    }

    public static function cookieName(Request $request): string
    {
        return ($request->isSecure() ? '__Host-' : '') . self::COOKIE;
    }

    /**
     * Whether this browser trusts the user: one of its tokens is the live token of a device of the user (rotated).
     */
    public function isTrusted(Request $request, UserInterface $user): bool
    {
        $profile    = $this->manager->profileFor($user);
        $credential = $this->manager->credential($user);
        if (!$profile instanceof ProfileSettings || !$credential instanceof MfaCredential || !$credential->isEnabled() || $this->lifetime($profile) === 0) {
            return false;
        }
        $tokens = $this->tokens($request);
        foreach ($tokens as $i => $token) {
            $fresh = $this->newToken();
            if ($this->store->rotateDevice($credential, $this->hash($profile, $token), $this->hash($profile, $fresh), $this->manager->now())) {
                $tokens[$i] = $fresh;
                $this->queue($request, $tokens, $profile);

                return true;
            }
        }

        return false;
    }

    /**
     * Trusts this browser for the user (after a valid code). Returns the device public id, or null when trusted
     * devices are off.
     */
    public function trust(Request $request, UserInterface $user): ?string
    {
        $profile    = $this->manager->profileFor($user);
        $credential = $this->manager->credential($user);
        $lifetime   = $profile instanceof ProfileSettings ? $this->lifetime($profile) : 0;
        if (!$profile instanceof ProfileSettings || !$credential instanceof MfaCredential || !$credential->isEnabled() || $lifetime === 0) {
            return null;
        }
        $now      = $this->manager->now();
        $token    = $this->newToken();
        $publicId = bin2hex(random_bytes(16));
        $this->store->insertDevice($credential, $publicId, $this->hash($profile, $token), self::label((string) $request->headers->get('User-Agent')), $this->clients?->deviceId($request), $now, $now->modify(sprintf('+%d seconds', $lifetime)));
        $this->queue($request, [...$this->tokens($request), $token], $profile);
        $this->dispatcher?->dispatch(new MfaTrustedDeviceAddedEvent($profile->name, $user, $publicId));
        $this->enforceLimit($profile, $user, $credential, $now);

        return $publicId;
    }

    /**
     * The tokens of the request cookie (well-formed only, at most {@see self::MAX_TOKENS}).
     *
     * @return list<string>
     */
    public function tokens(Request $request): array
    {
        $value = $request->cookies->get(self::cookieName($request));

        return array_values(array_filter(
            explode('.', is_string($value) ? $value : ''),
            static fn (string $token): bool => preg_match('/^[A-Za-z0-9_-]{43}\z/', $token) === 1,
        ));
    }

    /**
     * Writes the queued cookie on the response.
     */
    public static function writeCookie(Request $request, Response $response): void
    {
        $queued = $request->attributes->get(self::QUEUE_ATTRIBUTE);
        if (!is_array($queued) || !is_string($queued['value'] ?? null)) {
            return;
        }
        $response->headers->setCookie(Cookie::create(
            self::cookieName($request),
            $queued['value'],
            (int) ($queued['expires'] ?? 0),
            '/',
            null,
            $request->isSecure(),
            true,
            false,
            Cookie::SAMESITE_LAX,
        ));
    }

    /**
     * Short browser-on-OS label of a User-Agent (no version, nothing identifying).
     */
    public static function label(string $userAgent): string
    {
        $browser = match (true) {
            str_contains($userAgent, 'Edg/')                                          => 'Edge',
            str_contains($userAgent, 'Firefox/')                                      => 'Firefox',
            str_contains($userAgent, 'Chrome/') || str_contains($userAgent, 'CriOS/') => 'Chrome',
            str_contains($userAgent, 'Safari/')                                       => 'Safari',
            default                                                                   => 'Browser',
        };
        $os = match (true) {
            str_contains($userAgent, 'Android')                                    => 'Android',
            str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Windows')                                    => 'Windows',
            str_contains($userAgent, 'Mac OS X')                                   => 'macOS',
            str_contains($userAgent, 'Linux')                                      => 'Linux',
            default                                                                => '',
        };

        return $os !== '' ? $browser . ' · ' . $os : $browser;
    }

    private function enforceLimit(ProfileSettings $profile, UserInterface $user, MfaCredential $credential, DateTimeImmutable $now): void
    {
        $max     = $this->sessions?->limitFor($user)->maxTrustedDevices ?? (int) ($profile->mfa['trusted_devices']['max'] ?? 5);
        $devices = $this->store->devices($credential, $now);
        if (count($devices) <= $max) {
            return;
        }
        $forgotten = 0;
        foreach (array_slice($devices, $max) as $device) {
            $forgotten += count($this->store->deleteDevices($credential, $device['public_id']));
        }
        $this->dispatcher?->dispatch(new MfaTrustedDevicesForgottenEvent($profile->name, $user, 'limit', $forgotten));
    }

    /**
     * @param list<string> $tokens
     */
    private function queue(Request $request, array $tokens, ProfileSettings $profile): void
    {
        $unique = [];
        foreach ($tokens as $token) {
            if (!in_array($token, $unique, true)) {
                $unique[] = $token;
            }
        }
        $request->attributes->set(self::QUEUE_ATTRIBUTE, [
            'value'   => implode('.', array_slice($unique, -self::MAX_TOKENS)),
            'expires' => $this->manager->now()->getTimestamp() + $this->lifetime($profile),
        ]);
    }

    private function lifetime(ProfileSettings $profile): int
    {
        return (int) ($profile->mfa['trusted_devices']['lifetime'] ?? 0);
    }

    private function hash(ProfileSettings $profile, string $token): string
    {
        return hash_hmac('sha256', 'nowo-auth-kit-mfa-device|' . $token, (string) ($profile->mfa['hash_secret'] ?? ''));
    }

    private function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
